<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * Les deux plafonds que PHP applique AVANT Laravel, et comment les dire.
 *
 * Ces plafonds ne sont pas de la validation : ils sont appliques par le moteur
 * au demarrage de la requete, avant que la moindre regle Laravel ne soit lue.
 * Laisser leurs cas de bord se rendre tout seuls donne deux messages qui ne
 * veulent rien dire pour un vendeur. Mesure par HTTP reel sur
 * POST /api/parts/1/media, avec les defauts compiles de PHP
 * (upload_max_filesize=2M, post_max_size=8M) :
 *
 *     un fichier de 2 255 773 o -> 422 « The photos.0 failed to upload. »
 *     dix fichiers, 20 722 380 o, avec Content-Length    -> 413 « The POST data is too large. »
 *     les memes dix SANS Content-Length (chunked)        -> 422 « The photos field is required. »
 *
 * Les trois sont faux ou muets : le premier parle d'un echec de televersement
 * et pas de taille, le deuxieme est en anglais, et le troisieme affirme qu'un
 * champ manque alors que dix fichiers ont ete envoyes.
 *
 * Cette classe tient la LECTURE des plafonds et la mise en forme ; le choix des
 * phrases vit la ou le cas se traite — MediaController pour le depot,
 * bootstrap/app.php pour le refus qui arrive avant tout controleur.
 */
final class PlafondsDeDepot
{
    /** Ce qu'un SEUL fichier peut peser avant que PHP le vide, en octets. */
    public static function parFichier(): int
    {
        return self::enOctets((string) ini_get('upload_max_filesize'));
    }

    /** Ce que le CORPS ENTIER peut peser avant que PHP le jette, en octets. */
    public static function parRequete(): int
    {
        return self::enOctets((string) ini_get('post_max_size'));
    }

    /**
     * Un poids, dans l'unite qui le rend lisible.
     *
     * Sous le megaoctet, des kilooctets entiers ; au-dela, enMo(). C'est ce
     * qu'il faut pour annoncer un DEPASSEMENT, qui peut valoir 1 ko comme 2 Mo.
     *
     * Pourquoi un depassement et pas le poids du fichier : parce que le poids
     * et la limite, dits tous les deux, se rejoignent au bord. Mesure sur un
     * fichier de 8 193 ko contre une limite de 8 192 ko, enMo() a deux
     * decimales :
     *
     *     « La photo pese 8 Mo ; le serveur n'accepte pas plus de 8 Mo »
     *
     * — 8,0007 et 8,0000 arrondis au meme chiffre. Un refus qui se lit comme
     * une contradiction, et aucun nombre de decimales n'y change quoi que ce
     * soit : il y a toujours un fichier assez pres de la limite pour les
     * confondre. Le depassement, lui, n'a pas ce defaut — il n'est jamais nul,
     * puisqu'on ne l'annonce que quand il y en a un — et il dit directement ce
     * qu'on attend du vendeur : de combien reduire.
     */
    public static function enPoids(int $octets): string
    {
        if ($octets < 1024 * 1024) {
            return number_format(max(1, (int) ceil($octets / 1024)), 0, ',', "\u{A0}").' ko';
        }

        return self::enMo($octets);
    }

    /**
     * Le poids en megaoctets, tel qu'on l'ecrit a un vendeur.
     *
     * Base 1024 et non 1000 : c'est celle des directives php.ini, et annoncer
     * « 8,4 Mo » pour une limite ecrite `8M` ferait douter du chiffre.
     *
     * DEUX decimales, et c'est une correction. Avec une seule, le message d'un
     * refus juste au-dessus de la limite se lisait :
     *
     *     « La photo pese 8 Mo ; le serveur n'accepte pas plus de 8 Mo »
     *
     * — mesure sur un fichier de 8 407 863 o contre une limite de 8 388 608 o,
     * soit 8,018 et 8,000 arrondis au meme chiffre. Un message qui refuse une
     * photo au motif qu'elle pese exactement la limite ne dit rien a personne.
     * Deux decimales separent les deux a 10 ko pres, et c'est le cas le plus
     * frequent : une photo depasse rarement de beaucoup.
     *
     * Les zeros de fin sont retires : « 8 Mo » plutot que « 8,00 Mo ». La
     * virgule est le separateur decimal francais.
     */
    public static function enMo(int $octets): string
    {
        $mo = $octets / (1024 * 1024);

        return str_replace('.', ',', rtrim(rtrim(number_format($mo, 2, '.', ''), '0'), '.')).' Mo';
    }

    /**
     * Le poids du corps que PHP a refuse, ou `null` quand il n'a rien refuse.
     *
     * DEUX voies, parce qu'il y a deux facons d'arriver la et qu'une seule des
     * deux laisse un Content-Length.
     *
     * Content-Length annonce et superieur au plafond : c'est le cas courant,
     * et il n'arrive plus jusqu'ici — public/index.php le refuse avant
     * `Request::capture()`, et ValidatePostSize le refuserait encore ensuite.
     * On le lit quand meme, par depassementAnnonce, pour que la detection ne
     * dependent ni du controleur frontal ni de l'ordre des intergiciels.
     *
     * Pas de Content-Length — un corps en `Transfer-Encoding: chunked` —, et
     * c'est le cas vicieux : PHP ne peut plus refuser d'avance, il lit le corps,
     * constate le depassement et vide TOUT. $_POST et $_FILES sont vides, et
     * Laravel conclut qu'aucun champ n'a ete envoye. Mesure de ce que PHP laisse
     * derriere lui dans ce cas, dix fichiers en chunked :
     *
     *     error_get_last() = array(
     *       'type' => 2,
     *       'message' => 'PHP Request Startup: POST Content-Length of 20724002 '
     *                  . 'bytes exceeds the limit of 8388608 bytes',
     *     )
     *
     * C'est le SEUL endroit ou le poids reellement envoye subsiste, et il est
     * exact. A comparer avec un multipart reellement vide, mesure cote a cote :
     * error_get_last() y vaut `null`. La distinction est donc nette, et c'est
     * ce qui permet de ne pas repondre « trop lourd » a une requete vide.
     */
    public static function depassementDuCorps(Request $requete): ?int
    {
        // `post_max_size=0` veut dire « pas de plafond » : PHP ne refuse alors
        // aucun corps pour sa taille, et il n'y a rien a lire dans l'un ni dans
        // l'autre chemin. On sort avant les deux, plutot que de chercher dans
        // error_get_last() un avertissement que PHP ne peut pas avoir pose.
        if (self::parRequete() <= 0) {
            return null;
        }

        return self::depassementAnnonce($requete->server('CONTENT_LENGTH'))
            ?? self::poidsRefuseParPhp(error_get_last()['message'] ?? null);
    }

    /**
     * Le Content-Length annonce quand il depasse post_max_size, ou `null`.
     *
     * PRISE A PART DE depassementDuCorps, ET SANS OBJET Request, parce que le
     * seul endroit d'ou ce refus est utile n'a pas encore de Request :
     * public/index.php, avant `Request::capture()`.
     *
     * La raison est mesuree. `Illuminate\Http\Request::createFromBase()`
     * — appelee par capture() — fait
     *
     *     if ($newRequest->isJson()) {
     *         $newRequest->request->replace($newRequest->json()->all());
     *
     * soit un json_decode du corps ENTIER, avant le noyau et donc avant le
     * premier intergiciel. Et `php artisan serve` garde tout le corps dans le
     * tampon de son SAPI : php://input le rend en entier MEME quand PHP a
     * refuse le corps pour depassement de post_max_size. Mesure par HTTP reel,
     * post_max_size=24M, memory_limit=192M, corps JSON de 94 371 840 o sur
     * POST /api/login :
     *
     *     500, corps VIDE, 135,67 s
     *     « Allowed memory size of 201326592 bytes exhausted (tried to
     *       allocate 94371872 bytes) in
     *       vendor/symfony/http-foundation/Request.php on line 1564 »
     *
     * 94 371 872 o, soit exactement le corps. Le meme essai avec un
     * intergiciel place en tete de la pile globale a rendu le MEME 500 au
     * corps vide, en 114,35 s : la pile globale arrive apres capture(), donc
     * trop tard. D'ou le refus dans le controleur frontal, et d'ou cette
     * methode pure.
     *
     * `$annonce` est volontairement `mixed` : elle vient de
     * `$_SERVER['CONTENT_LENGTH']`, qui est une chaine, absente, ou n'importe
     * quoi quand le client en decide ainsi.
     */
    public static function depassementAnnonce(mixed $annonce): ?int
    {
        $plafond = self::parRequete();

        if ($plafond <= 0) {
            return null;
        }

        return is_numeric($annonce) && (int) $annonce > $plafond ? (int) $annonce : null;
    }

    /**
     * Le poids lu dans l'avertissement de PHP, ou `null`.
     *
     * Publique et pure, et c'est la seule facon de l'eprouver : PHPUnit comme
     * Laravel posent un gestionnaire d'erreurs, et un gestionnaire qui traite
     * l'erreur empeche error_get_last() d'etre renseigne. Mesure, le meme test
     * ecrit avec trigger_error() puis un appel a depassementDuCorps() :
     * error_get_last() rend `null` sous PHPUnit la ou il rend l'avertissement
     * dans un script nu. Impossible, donc, de fabriquer l'etat ; possible de
     * verifier la LECTURE, qui est tout ce qui peut se tromper ici.
     *
     * Que l'avertissement survive en production n'est pas une supposition :
     * PHP le pose au DEMARRAGE de la requete, avant qu'une ligne de Laravel ne
     * tourne, et le gestionnaire que Laravel installe ensuite traite tout ce
     * qui suit — donc rien ne vient l'ecraser. Mesure de l'avertissement lui
     * meme, par HTTP reel, un corps de 94 371 836 o sous post_max_size=24M :
     *
     *     « PHP Request Startup: POST Content-Length of 94371836 bytes
     *       exceeds the limit of 25165824 bytes »
     *
     * CE DOCUMENT CITAIT « Les photos envoyees pesent 98,76 Mo au total ».
     * C'etait faux : messageCorpsTropLourd dit « L'envoi pese [...] », et
     * volontairement, puisque le plafond garde toutes les routes POST. La
     * citation datait d'une redaction anterieure a ce choix.
     */
    public static function poidsRefuseParPhp(?string $avertissement): ?int
    {
        // L'ancrage porte sur « POST Content-Length of N bytes exceeds » et non
        // sur le seul mot « exceeds » : d'autres avertissements de PHP emploient
        // ce verbe, et repondre « vos photos sont trop lourdes » a cause de
        // l'un d'eux serait pire que le message d'origine.
        $trouve = [];

        if (preg_match('/POST Content-Length of (\d+) bytes exceeds/', (string) $avertissement, $trouve) !== 1) {
            return null;
        }

        return (int) $trouve[1];
    }

    /**
     * Le refus d'un corps de requete trop lourd, en francais.
     *
     * ECRIT ICI, ET NON DANS SES DEUX APPELANTS. Ce refus arrive par deux
     * chemins qui n'ont rien en commun — l'intergiciel ValidatePostSize quand
     * l'appelant annonce un Content-Length, le controleur quand PHP a vide le
     * corps sans en annoncer la taille — et le meme geste ne doit pas donner
     * deux explications selon le client HTTP employe. Deux redactions cote a
     * cote auraient derive au premier remaniement ; une seule ne peut pas.
     *
     * « L'envoi » et non « les photos » : ValidatePostSize garde TOUTES les
     * routes POST, pas seulement le depot de media. Nommer les photos serait
     * faux sur un corps JSON trop lourd envoye ailleurs, et ce serait le genre
     * d'erreur qui envoie quelqu'un chercher un probleme de photo qu'il n'a
     * pas.
     *
     * `$octets` a `null` quand le poids envoye n'a pas pu etre etabli : le
     * message perd alors le chiffre plutot que d'en inventer un.
     */
    public static function messageCorpsTropLourd(?int $octets): string
    {
        return 'L\'envoi pese '
            .($octets === null ? 'plus que ce que le serveur accepte' : self::enMo($octets).' au total')
            .', et le serveur n\'accepte pas plus de '
            .self::enMo(self::parRequete())
            .' par envoi. Envoyez-le en plusieurs fois.';
    }

    /**
     * Les noms des fichiers que PHP a vides parce qu'ils depassaient
     * upload_max_filesize.
     *
     * UPLOAD_ERR_INI_SIZE et non une comparaison de taille : la taille est
     * perdue. Mesure de ce que $_FILES contient pour un fichier de 2 255 773 o
     * sous upload_max_filesize=2M :
     *
     *     'tmp_name' => '', 'type' => '', 'size' => 0, 'error' => 1
     *
     * Le nom d'origine est la seule chose qui survit, et c'est pourquoi le
     * message le cite : sur un envoi de dix photos, c'est tout ce qui permet au
     * vendeur de savoir LAQUELLE reprendre. La taille, elle, ne peut pas etre
     * annoncee par fichier — PHP ne l'a pas conservee.
     *
     * Les cles rendues sont celles de la VALIDATION — `photos.0` — et non de
     * simples numeros. L'appelant en fait une ValidationException, donc une
     * reponse 422 portant un tableau `errors` : c'est la seule forme que
     * l'application sait afficher. Mesure du besoin dans api_exception.dart,
     * ou `userMessage` ignore le message du serveur sur un 413 et rend son
     * propre texte, tandis que le cas 422 lit la premiere entree de `errors`.
     * Un refus de photo trop lourde rendu en 413 serait donc muet a l'ecran,
     * quel que soit le soin mis a sa redaction.
     *
     * @return array<string, string> cle de validation => nom du fichier
     */
    public static function fichiersTronques(Request $requete): array
    {
        return self::tronquesDe($requete->allFiles(), '');
    }

    /**
     * La descente recursive, parce qu'un champ de fichier peut etre un objet
     * seul, un tableau, ou un tableau de tableaux selon le nom du champ HTML.
     *
     * @param  array<mixed>  $noeud
     * @return array<string, string>
     */
    private static function tronquesDe(array $noeud, string $prefixe): array
    {
        $trouves = [];

        foreach ($noeud as $cle => $valeur) {
            $chemin = $prefixe === '' ? (string) $cle : $prefixe.'.'.$cle;

            if (is_array($valeur)) {
                $trouves += self::tronquesDe($valeur, $chemin);
                continue;
            }

            if ($valeur instanceof UploadedFile
                && $valeur->getError() === UPLOAD_ERR_INI_SIZE) {
                $trouves[$chemin] = (string) $valeur->getClientOriginalName();
            }
        }

        return $trouves;
    }

    /**
     * Les suffixes K/M/G des directives php.ini, que PHP n'exprime pas en
     * octets. Meme conversion que Illuminate\Http\Middleware\ValidatePostSize,
     * volontairement : les deux doivent voir le meme plafond, sinon l'un
     * refuserait ce que l'autre accepte.
     */
    private static function enOctets(string $brut): int
    {
        $brut = trim($brut);

        if ($brut === '') {
            return 0;
        }

        if (is_numeric($brut)) {
            return (int) $brut;
        }

        $facteur = match (strtoupper(substr($brut, -1))) {
            'K'     => 1024,
            'M'     => 1024 ** 2,
            'G'     => 1024 ** 3,
            default => 1,
        };

        return (int) $brut * $facteur;
    }
}
