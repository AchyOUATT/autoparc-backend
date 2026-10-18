<?php

namespace App\Support;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * La photo telle qu'og:image doit l'annoncer : une adresse, et ce qu'on sait
 * d'elle sans la telecharger.
 *
 * Les dimensions et le type ne sont pas de la decoration. Sans og:image:width,
 * og:image:height et og:image:type, le robot doit telecharger l'image ENTIERE
 * avant de pouvoir composer la vignette — et il le fait au premier appel,
 * celui qui a deja le reveil de l'instance Render devant lui. Avec elles, il
 * reserve la place et compose l'apercu pendant que l'image arrive.
 *
 * Elles ne sont declarees que quand elles sont SUES : une variante porte ses
 * dimensions en base, un original n'en a aucune d'enregistree. Une dimension
 * annoncee fausse vaut moins que pas de dimension du tout — le robot recadre
 * alors sur un rapport qui n'est pas celui de l'image.
 */
final class PhotoPartage
{
    public function __construct(
        public readonly string $url,
        public readonly ?int $largeur = null,
        public readonly ?int $hauteur = null,
        public readonly ?string $type = null,
    ) {}

    /**
     * Ce qu'un media offre a l'apercu, ou `null` quand il n'offre rien
     * d'utilisable.
     *
     * La variante bornee a 1200x630 d'abord, l'original a defaut : les media
     * deja en base n'ont pas de variante, et doivent garder l'apercu qu'ils
     * avaient — mediocre, mais existant — au lieu de le perdre le jour ou la
     * generation est arrivee. La commande `media:apercus` les rattrape.
     */
    public static function depuis(?Media $media): ?self
    {
        if ($media === null || ! self::cheminExploitable($media->path)) {
            return null;
        }

        $disque = (string) $media->disk;

        if (! self::adressePublique($disque)) {
            return null;
        }

        if (self::cheminExploitable($media->preview_path)) {
            return new self(
                url:     self::url($disque, (string) $media->preview_path),
                largeur: $media->preview_width,
                hauteur: $media->preview_height,
                type:    VignetteApercu::TYPE,
            );
        }

        return new self(
            url:  self::url($disque, (string) $media->path),
            // Aucune dimension : celles de l'original ne sont pas enregistrees,
            // et les lire exigerait de telecharger le fichier a chaque rendu.
            type: ((string) $media->mime_type) !== '' ? (string) $media->mime_type : null,
        );
    }

    /**
     * Ce chemin designe-t-il quelque chose qu'on peut servir ?
     *
     * DEUX valeurs a rejeter, et la seconde n'est pas theorique.
     *
     * Vide : il n'y a rien a servir, et laisser passer la chaine vide
     * produisait une adresse de dossier — « .../storage/ » — que le robot
     * resout en 403 ou en page HTML.
     *
     * « 0 » : la corruption que config/filesystems.php documente comme ayant
     * DEJA eu lieu. Avec `throw` et `report` a false, Flysystem rendait `false`
     * sur une ecriture refusee, MediaController l'enregistrait tel quel, et la
     * colonne `path` valait `0` ; deux photos ont ete perdues ainsi. Mesure
     * avant correction, galerie dont la premiere ligne porte path='0' et dont
     * la suivante est valable :
     *
     *     <meta property="og:image" content="http://localhost:8000/storage/0">
     *     <meta name="twitter:card" content="summary_large_image">
     *
     * Une vignette cassee annoncee en grand format, c'est-a-dire pire que pas
     * de vignette : l'apercu montre un cadre vide a la place de la photo.
     *
     * `(string) $chemin === '0'` et non `empty()` ni `! $chemin` : le test est
     * ecrit en clair parce que c'est precisement la conversion implicite de
     * PHP — « 0 » est faux — qui a fabrique le defaut ailleurs dans ce depot.
     */
    public static function cheminExploitable(?string $chemin): bool
    {
        $chemin = trim((string) $chemin);

        return $chemin !== '' && $chemin !== '0';
    }

    /**
     * L'adresse absolue, chemin ENCODE.
     *
     * Blade echappe le HTML, pas l'URL. Mesure avec
     * path='vehicles/corolla couv (1).jpg' :
     *
     *     content="https://pub-....r2.dev/vehicles/corolla couv (1).jpg"
     *
     * Deux espaces brutes dans un attribut d'URL, et aucune vignette. Le cas
     * n'est pas atteignable aujourd'hui — MediaController nomme en
     * Str::uuid() — mais la colonne est remplie par l'application et des
     * lignes anciennes survivent aux changements de reglage.
     *
     * Encode SEGMENT PAR SEGMENT : rawurlencode sur le chemin entier
     * transformerait aussi les « / » en %2F. Et on encode le chemin BRUT, tel
     * qu'il est en base, plutot que l'URL rendue — mesure des deux disques en
     * service, le driver local et le driver s3 muni d'AWS_URL concatenent sans
     * encoder, donc une seule etape d'encodage, jamais deux.
     *
     * Un « % » litteral dans un nom de fichier ancien sortirait en %25 : c'est
     * le prix de ne pas tenter de devenir ce qui est deja encode, et une
     * adresse surencodee rend 404 la ou une adresse sous-encodee rend une
     * vignette muette dans toutes les conversations.
     */
    private static function url(string $disque, string $chemin): string
    {
        $encode = implode('/', array_map(
            'rawurlencode',
            explode('/', str_replace('\\', '/', $chemin)),
        ));

        return Storage::disk($disque)->url($encode);
    }

    /**
     * Ce disque sert-il une adresse que le robot peut atteindre ?
     *
     * Cas de bord MESURE : MEDIA_DISK=s3 et AWS_URL vide. Le driver s3 se
     * rabat alors sur AWS_ENDPOINT et rend
     * « https://autoparc.abc.r2.cloudflarestorage.com/autoparc/... » — le point
     * d'acces PRIVE de l'API R2, qui repond 403 a une demande non signee, donc
     * a tout robot. og:image y pointerait une image interdite : une vignette
     * cassee, qui fait passer la page pour une page en panne.
     *
     * On omet alors la balise, comme pour un article sans photo, et on laisse
     * une trace : la page reste servie, mais la configuration est fautive et
     * doit se voir dans le visualiseur de Render. Une page de partage sans
     * vignette est une page de partage sans raison d'etre — ce n'est pas une
     * situation a traverser en silence.
     */
    private static function adressePublique(string $disque): bool
    {
        if ($disque === '') {
            return false;
        }

        $reglage = config("filesystems.disks.{$disque}");

        if (! is_array($reglage)) {
            return false;
        }

        if (($reglage['driver'] ?? null) !== 's3') {
            return true;
        }

        if (trim((string) ($reglage['url'] ?? '')) !== '') {
            return true;
        }

        Log::warning(
            'Apercu : AWS_URL est vide, og:image est omis plutot que de pointer '
            .'le point d\'acces prive du stockage, qui repond 403 au robot.',
            ['disque' => $disque],
        );

        return false;
    }
}
