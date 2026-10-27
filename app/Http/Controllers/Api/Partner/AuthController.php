<?php

namespace App\Http\Controllers\Api\Partner;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPartnerRequest;
use App\Http\Resources\PartnerAccountResource;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Support\MargePartenaire;
use App\Support\NumeroDeTelephone;
use App\Support\PromotionPartenaire;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(RegisterPartnerRequest $request): JsonResponse
    {
        $data = $request->validated();

        return DB::transaction(function () use ($request, $data) {
            $user = User::create([
                'name' => $data['contact_name'],
                // Nul quand le partenaire n'en a pas donne : c'est son numero
                // qui l'identifie desormais. La colonne reste unique, donc
                // deux comptes ne peuvent pas partager une adresse.
                'email' => $data['email'] ?? null,
                'password' => $data['password'],
                'phone' => $data['phone'],
                'role' => UserRole::Partner,
                'is_active' => true,
            ]);
            $account = PartnerAccount::create([
                'user_id' => $user->id,
                // Pas de `trim(...) ?: null` ici : `TrimStrings` puis
                // `ConvertEmptyStringsToNull`, tous deux actifs par defaut,
                // ont deja transforme « ␣␣␣ » en null avant la validation. Le
                // garde serait inatteignable — et un banc ne pourrait pas le
                // tuer, donc il mentirait. Un banc tient cette chaine.
                //
                // Ce qui protege l'application publiee n'est pas ici mais dans
                // `PartnerListingPresentation::seller()`, dont le `?:` couvre
                // aussi la chaine vide ecrite directement en base.
                'company_name' => $data['company_name'] ?? null,
                'contact_name' => $data['contact_name'],
                'phone' => $data['phone'],
                'whatsapp' => $data['whatsapp'] ?? null,
                'status' => 'pending',
            ]);
            $user->setRelation('partnerAccount', $account);

            return response()->json(array_merge(
                $this->profile($request, $user),
                [
                    'message' => 'Inscription recue. Votre entreprise doit etre approuvee avant de publier des annonces.',
                    'token' => $user->createToken($data['device_name'] ?? 'partner', ['partner'])->plainTextToken,
                ],
            ), 201);
        });
    }

    /**
     * Se connecter avec un NUMERO ou une adresse.
     *
     * ── POURQUOI DEUX CHAMPS ACCEPTES ─────────────────────────────────────
     *
     * Le courriel est devenu facultatif a l'inscription, donc l'identifiant
     * d'un partenaire est desormais son numero. Mais l'application PUBLIEE sur
     * le Play Store envoie `email`, et son propre validateur exige une forme
     * d'adresse avant meme d'appeler : lui retirer ce champ deconnecterait du
     * jour au lendemain tous les partenaires deja installes.
     *
     * `identifiant` est donc le nouveau nom, `email` reste accepte, et c'est
     * la presence d'un « @ » qui decide de la colonne interrogee — non le nom
     * du champ, pour qu'un numero envoye par un ancien binaire fonctionne s'il
     * y parvient.
     *
     * Le message d'echec reste le MEME dans tous les cas, y compris quand
     * l'identifiant n'existe pas : le distinguer dirait a un inconnu quels
     * numeros sont enregistres chez nous.
     */
    public function login(Request $request): JsonResponse
    {
        $champ = $request->has('identifiant') ? 'identifiant' : 'email';

        $data = $request->validate([
            $champ => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:72'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $identifiant = trim((string) $data[$champ]);

        return DB::transaction(function () use ($request, $data, $champ, $identifiant) {
            $cle = NumeroDeTelephone::cle($identifiant);

            // Un identifiant sans « @ » ni aucun chiffre ne designe rien. On
            // ne cherche pas : interroger avec une cle nulle trouverait les
            // comptes dont la colonne est nulle, c'est-a-dire n'importe qui.
            $user = match (true) {
                NumeroDeTelephone::estUnCourriel($identifiant) => User::query()
                    ->whereRaw('LOWER(email) = ?', [mb_strtolower($identifiant)])
                    ->lockForUpdate()
                    ->first(),
                $cle !== null => User::query()
                    ->whereHas('partnerAccount', fn ($q) => $q->where('phone_key', $cle))
                    ->lockForUpdate()
                    ->first(),
                default => null,
            };

            if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->isPartner() || ! $user->is_active) {
                throw ValidationException::withMessages([$champ => ['Identifiants invalides ou compte desactive.']]);
            }

            $account = $user->partnerAccount()->lockForUpdate()->first();
            abort_unless($account, 403, 'Compte partenaire introuvable.');
            if (in_array($account->status, ['rejected', 'suspended'], true)) {
                return response()->json([
                    'message' => 'Votre compte partenaire ne peut pas se connecter dans son etat actuel.',
                    'status' => $account->status,
                    'review_reason' => $account->review_reason,
                ], 403);
            }
            $user->setRelation('partnerAccount', $account);

            return response()->json(array_merge(
                $this->profile($request, $user),
                ['token' => $user->createToken($data['device_name'] ?? 'partner', ['partner'])->plainTextToken],
            ));
        });
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->profile($request, $request->user()));
    }

    private function profile(Request $request, User $user): array
    {
        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'role' => $user->role->value,
            ],
            'partner_account' => (new PartnerAccountResource($user->partnerAccount))->resolve($request),

            // ── CE QUE LE VENDEUR DOIT SAVOIR AVANT DE PROPOSER UN PRIX ────
            //
            // La boutique ajoute un pourcentage aux prix qu'il propose. Sans
            // le taux COURANT, son formulaire ne peut pas lui montrer le total
            // avant qu'il n'enregistre : il decouvrirait le prix affiche apres
            // coup, sur sa propre annonce.
            //
            // Le taux voyage avec le profil plutot que dans une route a part
            // parce que l'application le lit deja a chaque ouverture, et le
            // garde au coffre : la premiere annonce du jour ne demande donc
            // aucun appel de plus.
            //
            // C'est le taux du JOUR, pas celui d'une annonce : une annonce
            // deja enregistree porte le sien dans `partner_pricing`, et c'est
            // celui-la qu'on lui reaffiche.
            'boutique' => [
                'marge_pourcentage' => MargePartenaire::taux(),

                // Le plafond de remise, pour que le formulaire refuse AVANT
                // d'envoyer. Un vendeur qui compose sa promotion doit voir
                // « au-dela de 40 %, ce n'est pas accepte » pendant qu'il
                // tape, et non recevoir un 422 apres coup.
                'promotion_remise_max_pourcentage' => PromotionPartenaire::remiseMaximale(),
            ],
        ];
    }
}
