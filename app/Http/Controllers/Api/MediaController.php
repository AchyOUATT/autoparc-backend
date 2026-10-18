<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\PlafondsDeDepot;
use App\Support\VignetteApercu;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Closure;

/**
 * Gestion des médias polymorphes (photos véhicules, pièces, accessoires).
 *
 * Routes (dans le groupe auth:sanctum + staff) :
 *   POST   /vehicles/{vehicle}/media
 *   POST   /parts/{part}/media
 *   POST   /accessories/{accessory}/media
 *   DELETE /media/{media}
 *   PATCH  /media/{media}/cover
 *   PATCH  /vehicles/{vehicle}/media/reorder
 *   PATCH  /parts/{part}/media/reorder
 *   PATCH  /accessories/{accessory}/media/reorder
 */
class MediaController extends Controller
{
    /**
     * Ce qu'une photo peut peser, en ko.
     *
     * C'est la limite DU PRODUIT, et elle doit etre celle que le vendeur lit.
     * Pour cela il faut que PHP ne la devance pas : upload_max_filesize est
     * pose strictement au-dessus dans docker/php/depot-de-photos.ini, parce que
     * PHP qui refuse le premier vide le fichier et ne laisse qu'un code
     * d'erreur, la ou la validation d'ici nomme la taille et la limite.
     */
    private const MAX_SIZE   = 8192;   // ko — 8 Mo par fichier

    /**
     * Combien de photos dans un seul envoi.
     *
     * Dix, parce que c'est ce que l'application envoie : `pickMultiImage(limit:
     * 10)` dans media_upload_page.dart. post_max_size est dimensionne sur ce
     * chiffre et sur le POIDS REELLEMENT envoye, pas sur dix fichiers au
     * plafond de PHP : mesure par HTTP reel, dix photos de 2 072 670 o ->
     * Content-Length 20 728 232 o, soit 1 532 o d'enveloppe multipart. Le
     * calcul est dans docker/php/depot-de-photos.ini.
     *
     * CE COMMENTAIRE ANNONCAIT qu'un envoi de onze photos ferait lire
     * « The photos field is required. » au vendeur. C'etait faux deux fois.
     * Onze photos ne depassent aucun plafond de PHP — elles sont refusees par
     * la regle ci-dessous, et en francais : mesure par HTTP reel sous 24M, onze
     * fichiers de 706 508 o -> 422 « Vous ne pouvez pas envoyer plus de 10
     * photos a la fois. Envoyez-les en plusieurs fois. », en 2,12 s. Et un
     * corps qui depasse vraiment post_max_size n'atteint plus ce controleur :
     * public/index.php le refuse en 413 avant le noyau.
     *
     * Valide ici plutot que laisse a max_file_uploads, qui reste au defaut de
     * PHP (mesure : 20) : au-dela de son plafond, PHP fait disparaitre les
     * fichiers excedentaires SANS RIEN DIRE. Un refus explicite vaut mieux
     * qu'un envoi de douze photos dont deux manquent a l'arrivee.
     */
    private const MAX_PHOTOS = 10;

    private const ALLOWED    = ['image/jpeg', 'image/png', 'image/webp'];

    /**
     * Ou ecrire les nouvelles photos.
     *
     * Le disque etait fige a `public`, c'est-a-dire le disque local. En ligne,
     * celui de Render est ephemere : les photos disparaissaient au deploiement
     * suivant, en laissant des lignes `media` qui pointent sur des fichiers
     * absents. Le reglage vit maintenant dans la configuration, et vaut `s3`
     * en production.
     *
     * Lu a chaque ecriture, et non mis en cache dans une propriete : la
     * configuration peut changer d'une requete a l'autre, et les tests en
     * dependent.
     */
    private function disque(): string
    {
        return config('media.disk');
    }

    // ── Upload ───────────────────────────────────────────────────────

    /** POST /vehicles/{vehicle}/media */
    public function storeForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->storeMedia($request, $vehicle, 'vehicles');
    }

    /** POST /parts/{part}/media */
    public function storeForPart(Request $request, Part $part): JsonResponse
    {
        return $this->storeMedia($request, $part, 'parts');
    }

    /** POST /accessories/{accessory}/media */
    public function storeForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->storeMedia($request, $accessory, 'accessories');
    }

    private function storeMedia(Request $request, $model, string $folder): JsonResponse
    {
        // Les plafonds de PHP se traitent AVANT la validation, parce qu'ils ont
        // deja frappe : le fichier est vide ou le corps entier a disparu, et
        // aucune regle Laravel ne peut plus en dire quoi que ce soit d'utile.
        // Le detail des trois messages d'origine est dans PlafondsDeDepot.
        if (($refus = $this->refusDePhpSurLaTaille($request)) !== null) {
            return $refus;
        }

        $request->validate([
            'photos'     => ['required', 'array', 'min:1', 'max:' . self::MAX_PHOTOS],
            'photos.*'   => ['file', 'mimes:jpg,jpeg,png,webp', $this->reglePoids()],
            'collection' => ['nullable', 'string', 'in:gallery,documents,damages'],
        ], [
            'photos.max' => 'Vous ne pouvez pas envoyer plus de '.self::MAX_PHOTOS
                            .' photos a la fois. Envoyez-les en plusieurs fois.',
        ]);

        $collection = $request->input('collection', 'gallery');
        $created    = [];

        foreach ($request->file('photos', []) as $file) {
            $ext  = $file->getClientOriginalExtension();
            $name = Str::uuid() . '.' . $ext;
            $path = $file->storeAs("{$folder}/{$model->id}", $name, $this->disque());

            // storeAs() rend `false` quand le disque refuse l'écriture, et le
            // disque s3 est configuré avec `throw => false` : aucune exception
            // ne remonte. Sans ce garde-fou, `false` partait dans `path`, la
            // base enregistrait `0`, l'URL devenait « <AWS_URL>/0 » — un 404 —
            // et la réponse restait un 201 que l'application affichait en
            // « photo ajoutée ». Deux lignes fantômes ont été créées ainsi.
            //
            // On s'arrête ici plutôt que de poursuivre la boucle : les photos
            // déjà enregistrées sont rendues, celles qui suivent ne le seront
            // pas, et l'appelant sait exactement où il en est.
            if ($path === false) {
                Log::error('Media : le disque a refusé l\'écriture', [
                    'disque'   => $this->disque(),
                    'dossier'  => "{$folder}/{$model->id}",
                    'fichier'  => $name,
                    'taille'   => $file->getSize(),
                    'deja_cree' => count($created),
                ]);

                return response()->json([
                    'message' => "La photo n'a pas pu être enregistrée sur le stockage. "
                               . 'Réessayez ; si le problème persiste, le stockage est mal configuré.',
                    'data'    => $created,
                ], 502);
            }

            // Position = dernier existant + 1
            $position = $model->media()
                ->where('collection', $collection)
                ->max('position') + 1;

            // Premier upload dans la galerie → couverture automatique
            $isCover = $collection === 'gallery'
                && ! $model->media()->where('collection', 'gallery')->exists();

            // La variante d'apercu, generee MAINTENANT et non au rendu de la
            // page de partage : la generation lit le fichier sur le stockage
            // objet, et la faire au rendu ajouterait ce voyage devant une
            // instance Render qui met deja plusieurs secondes a se reveiller.
            //
            // `null` a chaque empechement — GD absent du poste, image hors des
            // bornes memoire, ecriture refusee —, et c'est voulu : le depot de
            // la photo doit aboutir, l'apercu retombant alors sur l'original.
            // Le detail est dans VignetteApercu.
            $apercu = VignetteApercu::generer($this->disque(), $path);

            $media = $model->media()->create([
                'collection'     => $collection,
                'path'           => $path,
                'preview_path'   => $apercu['chemin'] ?? null,
                'preview_width'  => $apercu['largeur'] ?? null,
                'preview_height' => $apercu['hauteur'] ?? null,
                'disk'           => $this->disque(),
                'mime_type'      => $file->getMimeType(),
                'size'           => $file->getSize(),
                'position'       => $position,
                'is_cover'       => $isCover,
            ]);

            $created[] = $this->formatMedia($media);
        }

        return response()->json(['data' => $created], 201);
    }

    /**
     * Ce que PHP a deja refuse, dit en francais, ou `null` quand il n'a rien
     * refuse.
     *
     * DEUX CAS DISTINCTS, qui ne se detectent pas de la meme facon et ne
     * meritent pas le meme code. Les mesures des messages d'origine sont dans
     * PlafondsDeDepot.
     *
     * Le CORPS ENTIER d'abord : quand post_max_size est depasse sans que
     * l'appelant ait annonce de Content-Length, PHP vide $_POST ET $_FILES, et
     * la requete arrive ici sans aucun champ. Rendre 422 « champ requis »
     * serait mentir sur ce qui s'est passe. 413 est le code de cette situation,
     * et c'est aussi celui des DEUX AUTRES chemins du meme refus : un
     * Content-Length annonce est refuse par public/index.php avant le noyau, et
     * ValidatePostSize reste derriere comme filet. Les trois rendent le meme
     * code et le MEME TEXTE, parce que tous appellent
     * PlafondsDeDepot::messageCorpsTropLourd. Mesure du chemin frontal, dix
     * fichiers de 6 739 621 o sous post_max_size=24M : 413 « L'envoi pese
     * 64,28 Mo au total, et le serveur n'accepte pas plus de 24 Mo par envoi.
     * Envoyez-le en plusieurs fois. », en 57,73 s.
     *
     * CE CAS-CI, en revanche, n'est atteignable QUE sans Content-Length : avec
     * une longueur annoncee, le controleur frontal a deja repondu.
     *
     * UN FICHIER ensuite : quand upload_max_filesize est depasse, les autres
     * fichiers de l'envoi sont intacts. On refuse quand meme l'envoi ENTIER
     * plutot que d'enregistrer les neuf qui passent : une galerie a laquelle il
     * manque une photo sans que personne ne le dise est le genre de defaut qui
     * se decouvre des mois plus tard, et le vendeur qui reprend son envoi
     * complet ne perd que quelques secondes. Ce cas rend 422 et non 413 — la
     * raison est sur le throw.
     *
     * Rend une reponse pour le premier cas, LEVE pour le second, et `null`
     * quand PHP n'a rien refuse. L'asymetrie vient des codes : une
     * ValidationException est deja rendue en 422 avec son tableau `errors`,
     * alors que le 413 n'a pas d'exception equivalente a reutiliser.
     */
    private function refusDePhpSurLaTaille(Request $request): ?JsonResponse
    {
        $envoye = PlafondsDeDepot::depassementDuCorps($request);

        if ($envoye !== null) {
            return response()->json([
                'message' => PlafondsDeDepot::messageCorpsTropLourd($envoye),
            ], 413);
        }

        $tronques = PlafondsDeDepot::fichiersTronques($request);

        if ($tronques !== []) {
            // 422 et une ValidationException, et non un 413 ecrit a la main.
            // Le refus est de la meme nature que celui de reglePoids() — une
            // photo trop lourde — et il doit se lire pareil : l'application
            // rend son propre texte sur un 413 et n'affiche le message du
            // serveur que sur un 422 (voir api_exception.dart). Un soin mis a
            // la phrase sans ce choix-la serait du soin perdu.
            //
            // Le NOM du fichier est cite parce que c'est la seule chose que PHP
            // conserve d'un fichier qu'il a vide : sa taille vaut 0, son
            // fichier temporaire est une chaine vide. Sur un envoi de dix
            // photos, c'est tout ce qui permet de savoir laquelle reprendre.
            // Sa taille, elle, ne peut PAS etre annoncee — PHP ne l'a plus.
            throw ValidationException::withMessages(
                array_map(
                    fn (string $nom) => 'La photo « '.$nom.' » depasse '
                        .PlafondsDeDepot::enMo(PlafondsDeDepot::parFichier())
                        .', le poids maximal d\'un fichier accepte par le serveur. '
                        .'Reduisez-la avant de la renvoyer.',
                    $tronques,
                ),
            );
        }

        return null;
    }

    /**
     * Le poids d'une photo, refuse avec la taille ET la limite.
     *
     * Une regle ecrite a la main plutot que `max:8192`, pour une seule raison :
     * le message de `max` ne dit rien du fichier refuse. Il rend « The photos.0
     * field must not be greater than 8192 kilobytes. » — en anglais, et sans
     * dire de combien la photo depasse, qui est la seule chose dont le vendeur
     * ait besoin pour agir.
     *
     * C'est cette regle-ci qui porte la limite du produit, et non PHP : voir
     * MAX_SIZE et docker/php/depot-de-photos.ini.
     */
    private function reglePoids(): Closure
    {
        return function (string $attribut, mixed $valeur, Closure $echec): void {
            if (! $valeur instanceof UploadedFile) {
                return;
            }

            $octets = (int) $valeur->getSize();

            if ($octets <= self::MAX_SIZE * 1024) {
                return;
            }

            // Le DEPASSEMENT, et non le poids du fichier suivi de la limite.
            // Les deux, dits cote a cote, se rejoignent au bord : mesure sur un
            // fichier de 8 193 ko contre 8 192 ko, « pese 8 Mo ; pas plus de
            // 8 Mo ». Le depassement dit la meme chose sans se contredire, et
            // il dit directement de combien reduire. Voir
            // PlafondsDeDepot::enPoids.
            $echec(
                'La photo « '.$valeur->getClientOriginalName().' » depasse de '
                .PlafondsDeDepot::enPoids($octets - self::MAX_SIZE * 1024)
                .' la limite de '.PlafondsDeDepot::enMo(self::MAX_SIZE * 1024)
                .' par photo. Reduisez-la avant de la renvoyer.'
            );
        };
    }
    // ── Suppression ──────────────────────────────────────────────────

    /** DELETE /media/{media} */
    public function destroy(Media $media): JsonResponse
    {
        Storage::disk($media->disk)->delete($media->path);

        // La variante d'apercu part avec l'original. Sans cette ligne, chaque
        // photo supprimee laisserait son fichier dans `apercus/` : un stockage
        // objet ne signale rien, et les orphelins ne se voient qu'a la facture.
        if ($media->preview_path !== null) {
            Storage::disk($media->disk)->delete($media->preview_path);
        }

        $wasCover   = $media->is_cover;
        $collection = $media->collection;
        $model      = $media->mediable;

        $media->delete();

        // Si c'était la couverture → promouvoir la suivante
        if ($wasCover && $model) {
            $next = $model->media()
                ->where('collection', $collection)
                ->orderBy('position')
                ->first();
            $next?->update(['is_cover' => true]);
        }

        return response()->json(['message' => 'Média supprimé.']);
    }

    // ── Couverture ───────────────────────────────────────────────────

    /** PATCH /media/{media}/cover */
    public function setCover(Media $media): JsonResponse
    {
        $model = $media->mediable;

        if ($model) {
            // Retirer l'ancien cover
            $model->media()
                ->where('collection', $media->collection)
                ->where('is_cover', true)
                ->update(['is_cover' => false]);
        }

        $media->update(['is_cover' => true]);

        return response()->json(['data' => $this->formatMedia($media)]);
    }

    // ── Réordonnancement ─────────────────────────────────────────────

    /** PATCH /vehicles/{vehicle}/media/reorder */
    public function reorderForVehicle(Request $request, Vehicle $vehicle): JsonResponse
    {
        return $this->reorderMedia($request, $vehicle);
    }

    /** PATCH /parts/{part}/media/reorder */
    public function reorderForPart(Request $request, Part $part): JsonResponse
    {
        return $this->reorderMedia($request, $part);
    }

    /** PATCH /accessories/{accessory}/media/reorder */
    public function reorderForAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        return $this->reorderMedia($request, $accessory);
    }

    private function reorderMedia(Request $request, $model): JsonResponse
    {
        $request->validate([
            'ids'   => ['required', 'array'],
            'ids.*' => ['integer'],
        ]);

        foreach ($request->ids as $position => $id) {
            $model->media()->where('id', $id)->update(['position' => $position]);
        }

        return response()->json(['message' => 'Ordre mis à jour.']);
    }

    // ── Listing ──────────────────────────────────────────────────────

    /** GET /vehicles/{vehicle}/media */
    public function indexForVehicle(Vehicle $vehicle): JsonResponse
    {
        return $this->indexMedia($vehicle);
    }

    /** GET /parts/{part}/media */
    public function indexForPart(Part $part): JsonResponse
    {
        return $this->indexMedia($part);
    }

    /** GET /accessories/{accessory}/media */
    public function indexForAccessory(Accessory $accessory): JsonResponse
    {
        return $this->indexMedia($accessory);
    }

    private function indexMedia($model): JsonResponse
    {
        $media = $model->media()->orderBy('position')->get();
        return response()->json(['data' => $media->map(fn ($m) => $this->formatMedia($m))]);
    }

    // ── Helper ───────────────────────────────────────────────────────

    private function formatMedia(Media $media): array
    {
        return [
            'id'         => $media->id,
            'url'        => $media->url,
            'collection' => $media->collection,
            'mime_type'  => $media->mime_type,
            'size'       => $media->size,
            'caption'    => $media->caption,
            'position'   => $media->position,
            'is_cover'   => $media->is_cover,
        ];
    }
}
