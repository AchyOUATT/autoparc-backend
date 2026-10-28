<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrivateVehicleListingResource;
use App\Models\PrivateVehicleListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PrivateListingReviewController extends Controller
{
    /**
     * Retirer UNE photo, quel que soit l'etat de l'annonce.
     *
     * POURQUOI LE PERSONNEL A BESOIN DE CECI
     *
     * `reject()` retire la PAGE : l'annonce quitte la consultation publique et
     * la page de partage rend 404. Le FICHIER, lui, reste sur un disque public
     * et lisible par son URL. Pour un prix mal saisi c'est sans consequence ;
     * pour une photo qu'il fallait retirer, le retrait etait cosmetique.
     *
     * Le proprietaire, lui, POURRAIT l'effacer : `editable()` inclut
     * `rejected`. Mais la moderation ne peut pas en dependre — une photo
     * illicite doit quitter le stockage tout de suite, pas quand le vendeur
     * voudra bien s'en occuper, et il peut tres bien ne jamais revenir. C'est
     * aussi ce qu'une boutique qui publie du contenu de tiers doit pouvoir
     * faire d'elle-meme.
     *
     * TROIS CHOIX A NE PAS DEFAIRE SANS LIRE CECI
     *
     * Pas de `requireEditable` : c'est precisement sur une annonce qui ne l'est
     * plus que ce chemin sert.
     *
     * Pas de `resetReview()` : il remettrait l'annonce en brouillon et
     * EFFACERAIT le motif du refus — le vendeur perdrait l'explication qu'on
     * vient de lui donner, et la raison du retrait disparaitrait avec elle.
     *
     * Pas de `revision` exigee, contrairement a `approve` et `reject`. Le
     * verrou optimiste protege d'une DECISION prise sur une version perimee ;
     * ici il n'y a pas de decision a perdre : la photo existe et part, ou elle
     * a deja disparu et `firstOrFail` rend 404. On incremente tout de meme la
     * revision, parce que le contenu change et qu'un ecran ouvert doit le
     * savoir avant de decider.
     */
    public function destroyPhoto(Request $request, PrivateVehicleListing $listing, int $photo)
    {
        DB::transaction(function () use ($listing, $photo) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            // Bornee a l'annonce visee : un identifiant de photo appartenant a
            // une autre annonce rend 404 au lieu de l'effacer.
            $image = $locked->photos()->whereKey($photo)->firstOrFail();
            $fichiers = array_values(array_filter([$image->path, $image->preview_path]));
            $image->delete();
            $locked->update(['revision' => $locked->revision + 1]);
            DB::afterCommit(fn () => Storage::disk($image->disk)->delete($fichiers));
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'in:pending,published,rejected']]);

        return PrivateVehicleListingResource::collection(PrivateVehicleListing::with('photos')
            ->where('status', $data['status'] ?? 'pending')->oldest('submitted_at')->paginate(20));
    }

    public function approve(Request $request, PrivateVehicleListing $listing)
    {
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            // L'affirmation du moderateur : « j'ai appele ce numero, et la
            // personne a confirme avoir depose cette annonce ». Facultative
            // dans la requete, exigee plus bas quand la ligne ne porte pas
            // deja un appel valable — le controle a besoin de l'etat VERROUILLE
            // pour savoir laquelle des deux situations s'applique.
            'phone_verified' => ['nullable', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $listing, $data) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->revision === (int) $data['revision'], 409, 'L’annonce a changé. Actualisez-la avant de décider.');
            abort_unless($locked->status === 'pending', 422, 'Cette annonce n’est plus en attente. Actualisez la liste.');
            abort_unless($locked->photos()->exists()
                && $locked->seller?->is_active && $locked->seller->isClient(), 422, 'Cette annonce ne remplit plus les conditions de publication.');

            // LE NUMERO, ET QUI LE PROUVE.
            //
            // La verification par SMS prouvait la possession du numero par
            // cryptographie. L'appel la prouve par un geste humain, et ce geste
            // n'a de valeur que s'il est EXIGE puis CONSIGNE. Exige par la case
            // ci-dessous ; consigne par `reviewed_by` et `phone_verified_at`,
            // qui disent ensemble qui a appele et quand.
            //
            // CE QUE LE MODERATEUR DOIT DEMANDER, et c'est la seule chose qui
            // separe ce dispositif d'une case cochee pour rien : « est-ce VOUS
            // qui avez depose cette annonce ? », pas « est-ce votre numero ? ».
            // Le SMS rendait impossible de deposer une annonce avec le numero
            // de quelqu'un d'autre. L'appel ne l'attrape que si l'on pose la
            // bonne question. C'est ecrit dans docs/ventes-particuliers.md.
            //
            // Un appel deja passe reste valable tant que le numero n'a pas
            // bouge : `update()` efface la preuve des que `phone` change, donc
            // l'egalite ci-dessous suffit a distinguer « deja verifie » de
            // « verifie, puis le numero a change ». Sans cela, le personnel
            // devrait rappeler le meme vendeur a chaque retouche de prix.
            $dejaVerifie = $locked->phone_verified_at !== null
                && $locked->verified_phone !== null
                && $locked->verified_phone === $locked->phone;

            abort_unless(
                $dejaVerifie || $request->boolean('phone_verified'),
                422,
                'Appelez le vendeur au numéro indiqué et confirmez qu’il a bien déposé cette annonce avant de publier.',
            );

            $miseAJour = ['status' => 'published', 'published_at' => now(), 'reviewed_by' => $request->user()->id, 'review_reason' => null, 'revision' => $locked->revision + 1];
            if (! $dejaVerifie) {
                $miseAJour['verified_phone'] = $locked->phone;
                $miseAJour['phone_verified_at'] = now();
            }
            $locked->update($miseAJour);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function reject(Request $request, PrivateVehicleListing $listing)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $listing, $data) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->revision === (int) $data['revision'], 409, 'L’annonce a changé. Actualisez-la avant de décider.');
            abort_unless(in_array($locked->status, ['pending', 'published'], true), 422, 'Cette annonce n’est plus à examiner.');
            $locked->update([
                'status' => 'rejected', 'published_at' => null,
                'reviewed_by' => $request->user()->id, 'review_reason' => $data['reason'],
                'revision' => $locked->revision + 1,
            ]);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }
}
