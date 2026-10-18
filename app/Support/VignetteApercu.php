<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * La variante d'image servie a og:image, bornee a 1200x630.
 *
 * MediaController::MAX_SIZE vaut 8192 ko et storeMedia ecrit le fichier tel
 * qu'il arrive : aucun redimensionnement, aucune variante. Les photos du parc
 * sont des prises de telephone, et WhatsApp refuse la vignette au-dela de
 * quelques centaines de ko — il se rabat alors sur titre + description.
 * L'apercu perd la photo, c'est-a-dire la seule chose qui le distingue d'un
 * lien nu : le defaut annulait silencieusement toute la livraison des pages de
 * partage.
 *
 * LE POIDS DE CES ORIGINAUX A CHANGE, et le commentaire qui tenait cette place
 * annoncait « de 2 a 6 Mo » alors que c'etait impossible. Jusqu'a
 * docker/php/depot-de-photos.ini, l'image de production n'activait aucun
 * php.ini : PHP appliquait son defaut compile, upload_max_filesize=2M, et
 * refusait tout fichier plus lourd AVANT que Laravel le voie. Mesure par HTTP
 * reel sous ce plafond, POST /api/parts/1/media :
 *
 *     2 072 238 o -> 201
 *     2 255 773 o -> 422 « The photos.0 failed to upload. »
 *
 * Le stock deja en base pese donc au plus 2 Mo par photo. Les depots a venir
 * iront jusqu'a 8192 ko, et c'est la que « 2 a 6 Mo » devient la description
 * juste. Dans les deux cas la variante garde sa raison d'etre : la limite de
 * WhatsApp est en centaines de ko, pas en megaoctets.
 *
 * 1200x630 est le format que Facebook et WhatsApp recadrent sans marge, et la
 * borne tient dans quelques centaines de ko a cette qualite.
 *
 * SANS NOUVELLE DEPENDANCE. GD suffit, et il est la : le Dockerfile de
 * production l'installe (`docker-php-ext-install ... gd`). Le depot a deja paye
 * une resolution de versions bloquee ; Intervention Image n'apporterait ici
 * qu'un redimensionnement que trois appels GD font aussi bien.
 *
 * Mesure du poste de developpement : `php -m` ne liste NI gd NI imagick — le
 * php.ini local commente `extension=gd`. D'ou la verification a l'execution
 * plutot qu'a l'installation : sans GD, la generation rend `null` et l'appelant
 * retombe sur l'original. Une photo deposee depuis un poste sans GD garde donc
 * son apercu d'origine au lieu de faire echouer le depot.
 *
 * Toujours du JPEG en sortie, quel que soit le format d'entree. C'est ce qui
 * rend og:image:type deterministe : la balise annonce image/jpeg sans avoir a
 * relire le fichier, et un PNG a canal alpha est aplati sur du blanc plutot que
 * de rendre un fond noir dans la conversation.
 */
final class VignetteApercu
{
    /** Le cadre que WhatsApp et Facebook recadrent sans marge. */
    public const LARGEUR_MAX = 1200;

    public const HAUTEUR_MAX = 630;

    /** Le type que la variante porte toujours, annonce par og:image:type. */
    public const TYPE = 'image/jpeg';

    /**
     * 82 : le palier au-dela duquel le poids monte sans que la vignette change
     * a l'oeil, a la taille ou elle est affichee dans une conversation.
     */
    private const QUALITE = 82;

    /**
     * Plafond ABSOLU en pixels, independant de la memoire disponible.
     *
     * Il ne protege pas du depassement memoire — c'est le budget calcule plus
     * bas qui s'en charge. Il borne le TEMPS : la generation tourne pendant un
     * depot de photo, et une image de 100 Mpx decompresserait pendant plusieurs
     * secondes avant d'etre reduite a 1200x630. Au-dela de ce plafond,
     * l'original sert et l'utilisateur garde une reponse immediate.
     *
     * 16 Mpx couvre les capteurs de telephone courants (12 Mpx) avec une marge,
     * et MediaController::MAX_SIZE borne de toute facon l'entree a 8192 ko —
     * borne qui mord desormais vraiment : jusqu'a
     * docker/php/depot-de-photos.ini, PHP refusait des 2 Mo et aucune image de
     * ce poids n'arrivait jusqu'ici.
     */
    private const PIXELS_MAX = 16_000_000;

    /**
     * Part de la memoire ENCORE LIBRE que la generation s'autorise.
     *
     * Le budget est calcule a l'execution et non grave dans une constante,
     * parce que la memoire restante depend du chemin d'appel, pas de l'image :
     * mesure du socle deja consomme avant l'appel (releve sous
     * memory_limit=128M ; l'image en pose 192M depuis, et un socle ne depend
     * pas de la limite),
     *
     *     requete HTTP de depot (noyau amorce, multipart de 2,7 Mo) -> 26,0 Mo
     *     commande de console (noyau console amorce)                -> 30,0 Mo
     *     test PHPUnit (la suite entiere chargee)                   -> davantage
     *
     * Une borne en pixels calibree sur l'un de ces socles ne vaut pour aucun
     * autre. Les 0,8 laissent un cinquieme du reste a ce que la requete alloue
     * APRES la generation : la reponse, le journal, l'ecriture sur le disque.
     */
    private const PART_UTILISABLE = 0.8;

    /** Les types que GD relit ici, et que MediaController accepte au depot. */
    private const TYPES = [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP];

    /**
     * GD est-il la ?
     *
     * Relu a chaque appel et non retenu dans une propriete statique : le cout
     * est nul, et une valeur retenue survivrait au test qui simule son absence.
     */
    public static function disponible(): bool
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring');
    }

    /**
     * Ce que la recompression va allouer, en octets.
     *
     * Publique et pure : c'est la seule facon de verifier le garde-fou sans
     * fabriquer une image qui mettrait le test lui-meme a terre.
     *
     * GD garde une image decompressee a QUATRE octets par pixel, quel que soit
     * le format d'entree, et recomprimer() en tient DEUX a la fois — la source
     * et la cible, l'une ne pouvant etre liberee avant que l'autre soit
     * remplie. S'y ajoute le JPEG de sortie, borne par le poids de l'entree
     * puisque la cible est plus petite et plus compressee.
     *
     * Verification du modele contre la mesure, relevee sous memory_limit=128M,
     * pic de generer() apres memory_reset_peak_usage() — sans cette remise a
     * zero le
     * « socle » est un pic deja atteint, qui contient la chaine d'entree, et le
     * delta mesure serait minore d'a peu pres le poids du fichier.
     *
     * LE POIDS D'ENTREE EST CITE, et ce n'est pas de la decoration : il est l'un
     * des trois termes du calcul, et deux images de meme taille en pixels mais
     * de poids differents ne demandent pas la meme memoire. Un pic annonce sans
     * lui ne veut rien dire.
     *
     *      3,00 Mpx (2000x1500), entree 1 697 851 o -> 20,0 Mo mesures, 15,1 calcules
     *     12,19 Mpx (4032x3024), entree 6 901 583 o -> 62,0 Mo mesures, 55,1 calcules
     *     16,00 Mpx (4000x4000), entree 9 057 386 o -> 76,0 Mo mesures, 71,2 calcules
     *
     * Le calcul SOUS-ESTIME sur les trois, de 4,8 a 6,9 Mo — l'ecart est le
     * frais fixe de GD, qui ne suit pas les pixels. C'est le mauvais sens pour
     * un garde-fou, et c'est PART_UTILISABLE qui le couvre : un cinquieme de la
     * memoire restante mise de cote valait, sous les 128M d'alors, bien plus
     * que les 7 Mo manquants ici, et davantage encore sous les 192M que
     * l'image pose desormais.
     *
     * CES PICS NE DEPENDENT PAS DE memory_limit — ils ne dependent que des
     * trois termes ci-dessus. Verification, un fichier de 7 931 546 o a
     * 16,00 Mpx mesure sous 192M : pic 102 798 656 o, variante 630x630 en
     * 1,406 s, contre 102,0 Mo releves a 128M sur une entree de 9 057 386 o.
     * Ce que la limite change, c'est le VERDICT de tientDansLaMemoire, pas le
     * besoin.
     */
    public static function octetsRequis(
        int $largeur,
        int $hauteur,
        int $largeurCible,
        int $hauteurCible,
        int $octetsSource,
    ): int {
        return 4 * ($largeur * $hauteur + $largeurCible * $hauteurCible) + $octetsSource;
    }

    /**
     * Le besoin tient-il dans ce qui reste ?
     *
     * `$octetsLibres` negatif veut dire « limite absente » (memory_limit=-1,
     * ce que posent certains postes et la ligne de commande de plusieurs
     * distributions) : il n'y a alors rien a verifier, et PIXELS_MAX reste
     * seul a borner le travail.
     */
    public static function tientDansLaMemoire(int $besoin, int $octetsLibres): bool
    {
        return $octetsLibres < 0 || $besoin <= $octetsLibres;
    }

    /**
     * Ce que la generation peut encore allouer sans approcher la limite.
     *
     * `-1` quand memory_limit est absent. La limite est lue a CHAQUE appel :
     * le Dockerfile ne la pose que sur la commande de rattrapage
     * (`php -d memory_limit=256M artisan media:apercus`), si bien que le meme
     * code tourne sous deux limites differentes selon qu'il sert un depot ou
     * un rattrapage — une valeur retenue mentirait pour l'un des deux.
     */
    private static function octetsDisponibles(): int
    {
        $limite = self::limiteMemoire();

        if ($limite < 0) {
            return -1;
        }

        return max(0, (int) (($limite - memory_get_usage(true)) * self::PART_UTILISABLE));
    }

    /** memory_limit en octets, ou `-1` quand il n'y en a pas. */
    private static function limiteMemoire(): int
    {
        $brut = trim((string) ini_get('memory_limit'));

        if ($brut === '' || $brut === '-1') {
            return -1;
        }

        // Les suffixes K/M/G d'ini_get, que PHP n'exprime pas en octets.
        $facteur = match (strtolower(substr($brut, -1))) {
            'g'     => 1024 ** 3,
            'm'     => 1024 ** 2,
            'k'     => 1024,
            default => 1,
        };

        $valeur = (int) $brut * ($facteur === 1 ? 1 : $facteur);

        return $valeur <= 0 ? -1 : $valeur;
    }

    /**
     * Ou vit la variante d'un original.
     *
     * A cote de lui, dans un sous-dossier `apercus`, et en .jpg : le dossier
     * rend le rattrapage et le menage lisibles depuis la console du stockage
     * objet, ou l'on ne voit par ailleurs que des noms d'UUID.
     */
    public static function chemin(string $original): string
    {
        $dossier = str_replace('\\', '/', dirname($original));
        $dossier = ($dossier === '.' || $dossier === '/') ? '' : trim($dossier, '/').'/';

        return $dossier.'apercus/'.pathinfo($original, PATHINFO_FILENAME).'.jpg';
    }

    /**
     * Fabrique la variante d'une image deja posee sur un disque.
     *
     * Rend le chemin et les dimensions REELLES de ce qui a ete ecrit — ce sont
     * elles que la page declare en og:image:width/height, et une dimension
     * annoncee fausse vaut moins que pas de dimension du tout.
     *
     * Rend `null` a chaque empechement, sans jamais lever : cette fabrique est
     * appelee pendant un depot de photo, et le depot doit aboutir meme quand la
     * variante echoue. L'appelant retombe alors sur l'original, qui a toujours
     * donne un apercu — mediocre, mais existant.
     *
     * @return array{chemin: string, largeur: int, hauteur: int}|null
     */
    public static function generer(string $disque, string $original): ?array
    {
        if (! self::disponible()) {
            return null;
        }

        try {
            $stockage = Storage::disk($disque);

            if (! $stockage->exists($original)) {
                return null;
            }

            $source = (string) $stockage->get($original);

            if ($source === '') {
                return null;
            }

            $taille = @getimagesizefromstring($source);

            if ($taille === false || ! in_array($taille[2] ?? null, self::TYPES, true)) {
                return null;
            }

            [$largeur, $hauteur] = $taille;

            if ($largeur < 1 || $hauteur < 1 || $largeur * $hauteur > self::PIXELS_MAX) {
                Log::warning('Vignette : image hors du plafond de pixels, original conserve', [
                    'disque'  => $disque,
                    'chemin'  => $original,
                    'largeur' => $largeur,
                    'hauteur' => $hauteur,
                ]);

                return null;
            }

            // `min(1, ...)` : jamais d'agrandissement. Etirer une petite photo
            // a 1200 de large ne lui ajoute aucun detail et multiplie son poids.
            $facteur = min(1.0, self::LARGEUR_MAX / $largeur, self::HAUTEUR_MAX / $hauteur);

            $largeurCible = max(1, (int) round($largeur * $facteur));
            $hauteurCible = max(1, (int) round($hauteur * $facteur));

            // LE garde-fou qui empeche le depot de mourir. Lu AVANT d'allouer,
            // parce qu'un depassement de memoire est une erreur FATALE : il ne
            // leve pas d'exception, le try/catch ci-dessous ne le voit pas, la
            // requete meurt, et l'utilisateur ne recoit aucune explication sur
            // le geste le plus courant de l'application.
            $besoin = self::octetsRequis($largeur, $hauteur, $largeurCible, $hauteurCible, strlen($source));
            $libres = self::octetsDisponibles();

            if (! self::tientDansLaMemoire($besoin, $libres)) {
                Log::warning('Vignette : memoire insuffisante, original conserve', [
                    'disque'            => $disque,
                    'chemin'            => $original,
                    'largeur'           => $largeur,
                    'hauteur'           => $hauteur,
                    'octets_requis'     => $besoin,
                    'octets_utilisables' => $libres,
                ]);

                return null;
            }

            $jpeg = self::recomprimer($source, $largeur, $hauteur, $largeurCible, $hauteurCible);

            if ($jpeg === null) {
                return null;
            }

            $destination = self::chemin($original);

            // Le disque s3 est configure avec `throw => false` : put() rend
            // `false` au lieu de lever. Sans ce test, un stockage qui refuse
            // l'ecriture laissait croire la variante ecrite, et og:image
            // pointerait un fichier absent — une vignette cassee, qui fait
            // passer la page pour une page en panne.
            if ($stockage->put($destination, $jpeg) === false) {
                Log::warning('Vignette : le disque a refuse l\'ecriture, original conserve', [
                    'disque'      => $disque,
                    'destination' => $destination,
                ]);

                return null;
            }

            return [
                'chemin'  => $destination,
                'largeur' => $largeurCible,
                'hauteur' => $hauteurCible,
            ];
        } catch (Throwable $e) {
            Log::warning('Vignette : generation impossible, original conserve', [
                'disque' => $disque,
                'chemin' => $original,
                'erreur' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Le travail GD lui-meme, isole pour que les ressources soient liberees
     * sur TOUS les chemins de sortie — y compris celui d'une ecriture refusee.
     *
     * Deux images vivent en memoire a la fois ici ; les laisser au ramasse-
     * miettes de PHP suffirait en requete courte, pas dans la commande de
     * rattrapage qui en enchaine des centaines.
     */
    private static function recomprimer(
        string $source,
        int $largeur,
        int $hauteur,
        int $largeurCible,
        int $hauteurCible,
    ): ?string {
        $entree = @imagecreatefromstring($source);

        if ($entree === false) {
            return null;
        }

        $sortie = null;

        try {
            $sortie = imagecreatetruecolor($largeurCible, $hauteurCible);

            // Aplatissement sur du blanc. Un PNG ou un WebP a canal alpha perd
            // sa transparence en JPEG, et le defaut de GD est le noir : un logo
            // de piece sur fond transparent sortait en pave noir dans la
            // conversation.
            imagefill($sortie, 0, 0, imagecolorallocate($sortie, 255, 255, 255));

            imagecopyresampled(
                $sortie, $entree,
                0, 0, 0, 0,
                $largeurCible, $hauteurCible,
                $largeur, $hauteur,
            );

            ob_start();
            $ecrit = imagejpeg($sortie, null, self::QUALITE);
            $jpeg = (string) ob_get_clean();

            return ($ecrit === false || $jpeg === '') ? null : $jpeg;
        } finally {
            imagedestroy($entree);

            if ($sortie !== null) {
                imagedestroy($sortie);
            }
        }
    }
}
