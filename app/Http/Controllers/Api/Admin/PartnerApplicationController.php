<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PartnerAccountResource;
use App\Models\AppNotification;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PartnerApplicationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(PartnerAccount::STATUSES)],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return PartnerAccountResource::collection(
            PartnerAccount::query()->with('user')
                ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
                ->when($data['q'] ?? null, fn ($query, $search) => $query->where(function ($query) use ($search) {
                    $query->whereLike('company_name', '%'.$search.'%')
                        ->orWhereLike('contact_name', '%'.$search.'%')
                        ->orWhereHas('user', fn ($user) => $user->whereLike('email', '%'.$search.'%'));
                }))
                ->orderByDesc('created_at')->orderByDesc('id')
                ->paginate($data['per_page'] ?? 25)->withQueryString(),
        );
    }

    public function show(PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return new PartnerAccountResource($partnerAccount->load('user'));
    }

    public function approve(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'approved');
    }

    public function reject(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'rejected');
    }

    public function suspend(Request $request, PartnerAccount $partnerAccount): PartnerAccountResource
    {
        return $this->review($request, $partnerAccount, 'suspended');
    }

    private function review(Request $request, PartnerAccount $partnerAccount, string $status): PartnerAccountResource
    {
        $data = $request->validate([
            'review_reason' => [$status === 'approved' ? 'nullable' : 'required', 'string', 'max:2000'],
        ]);

        $account = DB::transaction(function () use ($request, $partnerAccount, $status, $data) {
            // Meme ordre de verrous que la connexion : utilisateur, puis compte.
            $user = $partnerAccount->user()->lockForUpdate()->firstOrFail();
            $account = PartnerAccount::query()->lockForUpdate()->findOrFail($partnerAccount->id);
            $reason = $data['review_reason'] ?? null;

            // ── LA MEME DECISION DEUX FOIS N'EST PAS DEUX DECISIONS ──────────
            //
            // Une instance lente, un client qui reessaie, un double appui : le
            // meme « approuver » arrive deux fois. Sans cette garde, chaque
            // passage ecrivait une ligne « Compte approuve » de plus et la
            // pastille du partenaire disait « 2 » pour une seule nouvelle. On
            // compare sur la ligne VERROUILLEE, pas sur celle que la route a
            // liee : c'est elle qui dit ce que le second appel trouve.
            //
            // Un motif qui change est une autre decision : le partenaire
            // suspendu doit lire le motif corrige.
            if ($account->status === $status && $account->review_reason === $reason) {
                return $account->setRelation('user', $user);
            }

            $previous = $account->status;
            $account->update([
                'status' => $status,
                'review_reason' => $reason,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
            ]);

            if (in_array($status, ['rejected', 'suspended'], true)) {
                $user->tokens()->delete();
            }

            // Une note changee sur un compte DEJA approuve ne change rien a ce
            // qu'il peut faire : pas de nouvelle « Compte approuve » pour cela.
            if ($previous !== $status || $status !== 'approved') {
                $this->notifier($user, $account, $status, $previous);
            }

            return $account->setRelation('user', $user);
        });

        return new PartnerAccountResource($account);
    }

    /**
     * Le partenaire apprend l'issue de l'examen dans sa boîte.
     *
     * Dans la transaction, avec la décision : une décision enregistrée sans
     * sa notification, ou l'inverse, raconterait deux histoires.
     *
     * ── CE QU'UN COMPTE REFUSÉ OU SUSPENDU PEUT EN LIRE ──────────────────
     *
     * Rien, tant qu'il reste dans cet état : ses jetons viennent d'être
     * révoqués et `AuthController::login` lui refuse une nouvelle session
     * (403, avec le motif). Le motif lui parvient donc par la réponse de
     * connexion ; la notification est la trace qu'il retrouvera dans sa
     * boîte si le compte est réapprouvé — l'historique de son examen, dans
     * l'ordre.
     *
     * ── POURQUOI PAS DE PUSH ─────────────────────────────────────────────
     *
     * Aucune route partenaire n'enregistre de jeton FCM — `POST /api/fcm-token`
     * est réservée au personnel — si bien que `users.fcm_token` est toujours
     * nul pour un partenaire. Le jour où les partenaires enregistreront un
     * appareil, c'est ici qu'il faudra brancher `FcmService::sendToToken`.
     *
     * Les textes sont en français, la langue source de toutes les
     * notifications automatiques, et traduits à la livraison par
     * `NotificationText` : la boîte se lit dans la langue de la requête, pas
     * dans celle du personnel qui a cliqué. Le motif, lui, est du texte
     * humain : il voyage tel quel.
     */
    private function notifier(User $user, PartnerAccount $account, string $status, string $previous): void
    {
        // Un compte reapprouve apres un refus ou une suspension a deja ses
        // annonces : lui dire de publier la premiere serait faux, et il le
        // saurait. Meme type — c'est la meme porte qui se rouvre —, autre corps.
        $approved = in_array($previous, ['rejected', 'suspended'], true)
            ? 'Votre compte est de nouveau actif.'
            : 'Votre compte est approuvé. Publiez votre première annonce.';

        [$type, $title, $body] = match ($status) {
            'approved' => ['partner_account_approved', 'Compte approuvé', $approved],
            'rejected' => ['partner_account_rejected', 'Demande refusée', 'Votre demande a été refusée : '.$account->review_reason],
            'suspended' => ['partner_account_suspended', 'Compte suspendu', 'Votre compte est suspendu : '.$account->review_reason],
        };

        AppNotification::notifyPartner($user->id, $type, $title, $body, [
            'status' => $status,
            'partner_account_id' => $account->id,
        ]);
    }
}
