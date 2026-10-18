<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\Part;
use App\Models\User;
use App\Support\PlafondsDeDepot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Ce qu'un vendeur lit quand sa photo est trop lourde.
 *
 * CE N'EST PAS UNE PRECAUTION : c'etait une panne en cours. L'image de
 * production n'activait aucun php.ini — php:8.4-cli ne livre que des modeles,
 * et le Dockerfile n'en copiait aucun — si bien que PHP appliquait ses defauts
 * compiles, upload_max_filesize=2M et post_max_size=8M. Or
 * MediaController::MAX_SIZE vaut 8192 ko et la validation l'annonce. PHP
 * refusait donc le fichier AVANT que Laravel le voie.
 *
 * Mesure par HTTP reel sous ces defauts, POST /api/parts/1/media avec un jeton
 * d'administrateur, serveur lance avec les plafonds d'origine :
 *
 *     2 072 238 o -> 201
 *     2 255 773 o -> 422 « The photos.0 failed to upload. »
 *     6 797 161 o -> 422 « The photos.0 failed to upload. »
 *
 * Une prise de telephone ordinaire fait 2 a 5 Mo : aucune ne passait, et le
 * message ne parlait pas de taille. docker/php/depot-de-photos.ini pose
 * desormais 9M, 24M et 192M, et ces tests tiennent la seconde moitie du
 * correctif — que CHAQUE refus dise la taille et la limite, en francais.
 *
 * ── CE QUI EST EPROUVE ICI, ET CE QUI NE PEUT PAS L'ETRE ──────────────────
 *
 * upload_max_filesize, post_max_size et memory_limit sont des directives
 * PHP_INI_PERDIR ou PHP_INI_ALL qu'aucun test ne peut poser pour la requete
 * qui le lit. Ce qui est verifie ici, c'est donc le TRAITEMENT de chaque cas,
 * l'etat de PHP etant reproduit a la main : un UploadedFile porteur de
 * UPLOAD_ERR_INI_SIZE pour le fichier vide, un Content-Length annonce pour le
 * corps refuse d'avance, et l'avertissement que PHP laisse derriere lui pour
 * le corps vide sans Content-Length. Les trois formes sont celles mesurees par
 * la sonde, et elles sont citees la ou elles servent.
 *
 * ET CE QUI NE PASSE PAS PAR LE NOYAU. Le refus qui compte en production vit
 * dans public/index.php, que PHPUnit ne traverse pas : la suite amorce le
 * noyau directement. Deux tests couvrent ce que l'on peut en couvrir — la
 * DECISION (`depassementAnnonce`, pure) et la POSITION du refus dans le
 * fichier —, et aucun ne remplace la mesure par HTTP reel. Les trois chiffres
 * qui l'ont etablie sont cites sur
 * test_le_refus_du_corps_precede_la_capture_de_la_requete.
 *
 * Les plafonds eux-memes sont poses sur la CI aux valeurs de l'image
 * (.github/workflows/tests.yml) : sans cela la suite eprouverait un serveur
 * qui n'existe nulle part. Les assertions, elles, lisent les plafonds REELS
 * plutot que d'ecrire « 9 Mo » en dur, pour rester vraies sur un poste regle
 * autrement.
 */
class PlafondsDeDepotTest extends TestCase
{
    use RefreshDatabase;

    private Part $piece;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        Storage::fake('public');

        $this->piece = Part::factory()->create();
    }

    private function url(): string
    {
        return "/api/parts/{$this->piece->id}/media";
    }

    /**
     * Un fichier FACTICE, et c'est un choix.
     *
     * `->create()` fabrique un fichier de la taille demandee sans l'image qui
     * va avec : la validation regarde l'extension et le type MIME, et ces
     * tests-ci portent sur le POIDS. Une vraie image de 8 Mo couterait des
     * secondes a fabriquer et exigerait GD, qui manque sur certains postes.
     */
    private function photo(string $nom, int $ko): UploadedFile
    {
        return UploadedFile::fake()->create($nom, $ko, 'image/jpeg');
    }

    // ── Le poids d'une photo ─────────────────────────────────────────────

    public function test_une_photo_juste_sous_la_limite_est_acceptee(): void
    {
        // 8191 ko contre une limite de 8192 : le dernier kilooctet du contrat.
        $this->postJson($this->url(), ['photos' => [$this->photo('presque.jpg', 8191)]])
            ->assertCreated();

        $this->assertSame(1, Media::count());
    }

    public function test_une_photo_juste_au_dessus_de_la_limite_dit_son_poids_et_la_limite(): void
    {
        // 8193 ko : un kilooctet de trop, c'est-a-dire le cas ou un message
        // vague est le plus penible — le vendeur ne sait pas s'il doit retirer
        // une photo ou en reduire une.
        $reponse = $this->postJson($this->url(), ['photos' => [$this->photo('trop.jpg', 8193)]])
            ->assertStatus(422);

        // `json('errors.photos.0')` ne marche PAS : la cle est litteralement
        // « photos.0 », point compris, et data_get la lirait comme une
        // descente de deux niveaux. On prend donc le tableau entier.
        $message = $this->premierMessage($reponse);

        // LE DEPASSEMENT, le nom du fichier, et la limite. Un kilooctet de trop
        // doit se lire comme un kilooctet de trop : c'est le cas ou un message
        // vague est le plus penible, le vendeur ne sachant pas s'il doit retirer
        // une photo ou en reduire une.
        $this->assertStringContainsString('trop.jpg', $message);
        $this->assertStringContainsString('depasse de 1 ko', $message);
        $this->assertStringContainsString('limite de 8 Mo', $message);

        // Et rien n'est enregistre : un refus partiel laisserait une galerie
        // incomplete sans que personne ne le dise.
        $this->assertSame(0, Media::count());
        Storage::disk('public')->assertDirectoryEmpty('parts');
    }

    /**
     * Le refus ne doit JAMAIS se lire comme une contradiction.
     *
     * C'est le defaut des deux premieres redactions de ce message, qui disaient
     * le poids du fichier puis la limite. Mesure sur un fichier de 8 193 ko
     * contre une limite de 8 192 ko, enMo() a deux decimales :
     *
     *     « La photo pese 8 Mo ; le serveur n'accepte pas plus de 8 Mo »
     *
     * Aucun nombre de decimales n'y suffit : il y a toujours un fichier assez
     * pres de la limite pour les confondre. Ce test tient la propriete qui
     * compte — le chiffre annonce n'est jamais egal a la limite — sur toute une
     * serie de depassements, du kilooctet au megaoctet.
     */
    public function test_aucun_depassement_ne_se_lit_comme_une_contradiction(): void
    {
        $limite = PlafondsDeDepot::enMo(8192 * 1024);

        foreach ([8193, 8195, 8211, 8300, 9000, 11000] as $ko) {
            $message = $this->premierMessage(
                $this->postJson($this->url(), ['photos' => [$this->photo("p{$ko}.jpg", $ko)]])
                    ->assertStatus(422),
            );

            $this->assertStringContainsString(
                'limite de '.$limite,
                $message,
                "La limite doit etre nommee pour un fichier de {$ko} ko.",
            );

            $this->assertStringNotContainsString(
                'depasse de '.$limite,
                $message,
                "Le depassement annonce vaut la limite elle-meme pour {$ko} ko : "
                .'le message se contredit.',
            );

            $this->assertSame(0, Media::count());
        }
    }

    // ── Ce que PHP a deja vide ───────────────────────────────────────────

    /**
     * upload_max_filesize depasse : PHP ne laisse qu'un code d'erreur.
     *
     * Mesure de ce que $_FILES contient alors, sonde posee devant un serveur
     * PHP regle a upload_max_filesize=2M, un fichier de 2 255 773 o :
     *
     *     'tmp_name' => '', 'type' => '', 'size' => 0, 'error' => 1
     *
     * Le nom d'origine est la seule chose qui survit — d'ou un message qui le
     * cite et qui NE PEUT PAS citer le poids. Laravel voyait cela comme un
     * echec de televersement et rendait « The photos.0 failed to upload. ».
     */
    public function test_un_fichier_vide_par_php_est_refuse_avec_la_limite_du_fichier(): void
    {
        $message = $this->premierMessage(
            $this->postJson($this->url(), ['photos' => [$this->fichierVideParPhp('enorme.jpg')]])
                ->assertStatus(422),
        );

        $this->assertStringContainsString('enorme.jpg', $message);
        $this->assertStringContainsString(
            PlafondsDeDepot::enMo(PlafondsDeDepot::parFichier()),
            $message,
            'Le plafond par fichier de PHP doit etre nomme.',
        );

        // Et surtout : plus le message d'origine, qui ne parlait pas de taille.
        $this->assertStringNotContainsString('failed to upload', $message);
        $this->assertSame(0, Media::count());
    }

    /**
     * 422 et non 413, et ce n'est pas un detail de protocole.
     *
     * Mesure dans api_exception.dart : `userMessage` rend son propre texte sur
     * un 413 et IGNORE celui du serveur ; sur un 422 il lit la premiere entree
     * de `errors`. Un refus de photo trop lourde rendu en 413 serait donc muet
     * a l'ecran, quel que soit le soin mis a sa redaction.
     */
    public function test_le_refus_d_un_fichier_vide_prend_la_forme_d_une_erreur_de_validation(): void
    {
        $this->postJson($this->url(), ['photos' => [$this->fichierVideParPhp('enorme.jpg')]])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['photos.0']]);
    }

    // ── Le corps entier ──────────────────────────────────────────────────

    /**
     * post_max_size depasse AVEC un Content-Length : Laravel refuse d'avance.
     *
     * C'est le cas courant — l'application mobile annonce la longueur de son
     * corps —, et il ne passe par aucun controleur : ValidatePostSize leve
     * depuis la pile d'intergiciels. Mesure du message d'origine, dix photos de
     * 2 072 238 o sous post_max_size=8M :
     *
     *     413 {"message":"The POST data is too large."}
     *
     * En anglais, et sans dire ni ce qui a ete envoye ni ce qui est accepte.
     */
    public function test_un_corps_trop_lourd_annonce_est_refuse_en_francais(): void
    {
        $envoye = PlafondsDeDepot::parRequete() + 1_000_000;

        $reponse = $this->call(
            'POST',
            $this->url(),
            [],
            [],
            [],
            ['CONTENT_LENGTH' => (string) $envoye, 'HTTP_ACCEPT' => 'application/json'],
        );

        $reponse->assertStatus(413);

        $message = (string) $reponse->json('message');

        $this->assertStringContainsString(PlafondsDeDepot::enMo($envoye), $message);
        $this->assertStringContainsString(
            PlafondsDeDepot::enMo(PlafondsDeDepot::parRequete()),
            $message,
        );
        $this->assertStringNotContainsString('POST data', $message);

        // Le MEME texte que l'autre chemin du meme refus, celui que
        // MediaController rend quand PHP a vide le corps sans Content-Length.
        // Les deux appellent PlafondsDeDepot::messageCorpsTropLourd ; ce test
        // casse si l'un des deux se met a rediger le sien.
        $this->assertSame(PlafondsDeDepot::messageCorpsTropLourd($envoye), $message);
    }

    /**
     * La DECISION du controleur frontal, eprouvee sans controleur frontal.
     *
     * `depassementAnnonce` est pure et publique pour cette seule raison : le
     * refus qui compte vit dans public/index.php, que la suite ne traverse pas
     * (PHPUnit amorce le noyau directement). Ce qui peut s'y tromper, c'est la
     * comparaison — et c'est elle qui est ici.
     *
     * Le cas « egal au plafond » n'est pas du zele : PHP accepte un corps dont
     * le Content-Length vaut exactement post_max_size, et le refuser ferait
     * mentir le message. Mesure par HTTP reel sous 24M, un corps urlencoded de
     * 25 165 820 o contre un plafond de 25 165 824 o : 422 et pic 101 061 632 o,
     * donc bien traite.
     */
    public function test_le_depassement_annonce_ne_se_declenche_qu_au_dela_du_plafond(): void
    {
        $plafond = PlafondsDeDepot::parRequete();

        $this->assertSame($plafond + 1, PlafondsDeDepot::depassementAnnonce((string) ($plafond + 1)));
        $this->assertNull(PlafondsDeDepot::depassementAnnonce((string) $plafond));
        $this->assertNull(PlafondsDeDepot::depassementAnnonce((string) ($plafond - 1)));

        // Absent ou illisible : c'est ce que `$_SERVER['CONTENT_LENGTH'] ?? null`
        // vaut sur un GET, et sur un POST dont le client annonce n'importe quoi.
        // Refuser sur une annonce non numerique rendrait 413 a des requetes sans
        // corps du tout.
        $this->assertNull(PlafondsDeDepot::depassementAnnonce(null));
        $this->assertNull(PlafondsDeDepot::depassementAnnonce(''));
        $this->assertNull(PlafondsDeDepot::depassementAnnonce('beaucoup'));
    }

    /**
     * post_max_size depasse SANS Content-Length : PHP vide tout.
     *
     * Cas distinct du precedent, et c'est le vicieux : sans longueur annoncee,
     * PHP ne peut pas refuser d'avance. Il lit le corps, constate le
     * depassement, et vide $_POST ET $_FILES. Laravel ne voit alors meme pas le
     * champ `photos` et rend « The photos field is required. ». Mesure par HTTP
     * reel avant correction, dix photos de 2 072 238 o en
     * Transfer-Encoding: chunked sous post_max_size=8M :
     *
     *     422 {"message":"The photos field is required."}
     *
     * Et apres, sous 24M avec dix fichiers de 6 739 621 o :
     *
     *     413 « L'envoi pese 64,28 Mo au total, et le serveur n'accepte pas
     *          plus de 24 Mo par envoi. Envoyez-le en plusieurs fois. »
     *
     * CETTE CITATION ETAIT FAUSSE ICI, et elle l'etait aussi dans
     * PlafondsDeDepot::poidsRefuseParPhp : les deux annoncaient « Les photos
     * envoyees pesent [...] », une redaction anterieure a messageCorpsTropLourd.
     * Celui-ci dit « L'envoi », et volontairement — l'intergiciel qui garde ce
     * plafond garde TOUTES les routes POST, pas le seul depot de photos.
     *
     * Le poids exact vient de l'avertissement que PHP laisse derriere lui ;
     * c'est la seule trace qui subsiste. Mesure de la sonde, meme requete :
     *
     *     error_get_last()['message'] = 'PHP Request Startup: POST
     *     Content-Length of 20724002 bytes exceeds the limit of 8388608 bytes'
     *
     * ── POURQUOI CE TEST N'APPELLE PAS LA ROUTE ───────────────────────────
     *
     * Parce qu'il ne prouverait rien. Le depassement lui-meme n'est pas
     * reproductible dans un test — post_max_size est PHP_INI_PERDIR —, et
     * l'avertissement reproduit par trigger_error ne survit PAS a un passage
     * complet du noyau : mesure, le meme test ecrit avec postJson() rend 422
     * « The photos field is required. », parce qu'error_get_last() a ete
     * ecrase entre-temps.
     *
     * C'est aussi la LIMITE de la detection, et il faut la dire : un
     * avertissement PHP leve pendant l'amorcage masque le signal, et le vendeur
     * relit alors l'ancien message. Le repli est le comportement d'avant, pas
     * une panne — et le cas courant, lui, ne passe pas par ici : l'application
     * mobile annonce un Content-Length, donc ValidatePostSize refuse d'avance.
     */
    public function test_le_poids_refuse_par_php_est_lu_dans_l_avertissement_qu_il_laisse(): void
    {
        // L'avertissement TEL QUE PHP L'ECRIT, copie de la sonde : dix fichiers
        // de 2 072 238 o en chunked sous post_max_size=8M.
        $this->assertSame(
            20_724_002,
            PlafondsDeDepot::poidsRefuseParPhp(
                'PHP Request Startup: POST Content-Length of 20724002 bytes '
                .'exceeds the limit of 8388608 bytes',
            ),
        );
    }

    /**
     * Et l'ancrage ne se declenche pas sur n'importe quel avertissement.
     *
     * « exceeds » seul ne suffit pas : d'autres avertissements de PHP emploient
     * ce verbe, et repondre « vos photos sont trop lourdes » a cause de l'un
     * d'eux serait pire que le message d'origine.
     */
    public function test_un_avertissement_sans_rapport_ne_declenche_pas_le_refus(): void
    {
        $this->assertNull(PlafondsDeDepot::poidsRefuseParPhp(
            'Maximum execution time exceeds something',
        ));

        // Et l'absence d'avertissement — le cas d'une requete reellement vide,
        // ou error_get_last() rend `null`. Mesure de la sonde sur un multipart
        // vide : `null`, la ou le depassement laisse son avertissement.
        $this->assertNull(PlafondsDeDepot::poidsRefuseParPhp(null));
    }

    /**
     * Et une requete REELLEMENT vide garde son message d'origine.
     *
     * C'est la moitie qui compte de la detection : repondre « trop lourd » a
     * une requete sans photo serait un mensonge, et il enverrait le vendeur
     * reduire des images qu'il n'a pas envoyees. Mesure de la sonde sur un
     * multipart vide : error_get_last() vaut `null`, la ou le depassement
     * laisse son avertissement.
     */
    public function test_une_requete_sans_photo_reste_une_erreur_de_champ_manquant(): void
    {
        $this->postJson($this->url(), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');
    }

    // ── Le nombre de photos ──────────────────────────────────────────────

    /**
     * Dix passent, onze non.
     *
     * Dix est le chiffre de l'application (`pickMultiImage(limit: 10)`) et
     * celui sur lequel post_max_size est dimensionne. Le refus est explicite
     * plutot que laisse a max_file_uploads, qui fait DISPARAITRE les fichiers
     * excedentaires sans rien dire (defaut mesure : 20).
     */
    public function test_dix_photos_passent_et_la_onzieme_est_refusee(): void
    {
        $dix = [];
        for ($i = 0; $i < 10; $i++) {
            $dix[] = $this->photo("p{$i}.jpg", 40);
        }

        $this->postJson($this->url(), ['photos' => $dix])->assertCreated();
        $this->assertSame(10, Media::count());

        $onze = $dix;
        $onze[] = $this->photo('p10.jpg', 40);

        $this->postJson($this->url(), ['photos' => $onze])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photos');

        // Rien n'a ete ajoute par la requete refusee.
        $this->assertSame(10, Media::count());
    }

    /**
     * Le DIMENSIONNEMENT lui-meme, et non seulement les messages.
     *
     * QUATRE inegalites, et chacune est un defaut deja vu :
     *
     *   — upload_max_filesize doit depasser MAX_SIZE, sinon PHP devance la
     *     validation et le message perd la taille. C'est l'etat de la
     *     production avant le lot precedent : 2M contre 8192 ko annonces, et
     *     toute photo de telephone refusee en « failed to upload » ;
     *   — post_max_size doit depasser upload_max_filesize, sinon le plus gros
     *     fichier que PHP accepterait est refuse par le corps qui le porte ;
     *   — post_max_size doit tenir DIX photos de la prise de telephone de
     *     REFERENCE, et non dix photos au plafond du produit. La rédaction
     *     precedente exigeait 10 x MAX_SIZE, soit 80 Mo, et c'est ce qui a
     *     donne 92M : un plafond que personne n'atteint et qui a ouvert
     *     l'inegalite suivante. Mesure du besoin reel, par HTTP reel, dix
     *     photos de 2 072 670 o : Content-Length 20 728 232 o, soit 1 532 o
     *     d'enveloppe multipart pour dix fichiers — 201 et dix lignes creees
     *     sous 24M ;
     *   — memory_limit doit tenir QUATRE FOIS post_max_size. C'est le defaut
     *     que ce test existe d'abord pour empecher. PHP remplit $_POST au
     *     demarrage de la requete, a quatre fois le poids du corps pour un
     *     application/x-www-form-urlencoded, et sans qu'aucun code puisse s'y
     *     opposer. Mesures du pic, corps jamais lu par le script :
     *
     *         25 165 820 o -> 101 061 632 o (4,02 x)
     *         33 554 428 o -> 134 616 064 o (4,01 x)
     *         50 331 644 o -> 201 724 928 o (4,01 x)
     *
     *     Sous 92M et memory_limit laisse a 128M, POST /api/login — route NON
     *     AUTHENTIFIEE — rendait 500 AU CORPS VIDE des 33 554 428 o, en
     *     15,76 s, par erreur fatale de memoire.
     */
    public function test_les_plafonds_de_php_sont_dimensionnes_pour_dix_photos(): void
    {
        $this->exigerLesPlafondsDeLImage();

        // 2 072 238 o est la prise de telephone de reference, celle dont le
        // refus a motive le lot precedent ; 2 048 o couvrent l'enveloppe
        // multipart, mesuree a 1 532 o pour dix fichiers.
        $dixPhotosReelles = 10 * 2_072_238 + 2_048;

        $this->assertLessThan(
            PlafondsDeDepot::parRequete(),
            $dixPhotosReelles,
            'post_max_size doit tenir dix photos de telephone ordinaires, '
            .'sinon le corps entier est vide et aucun message par photo n\'est '
            .'possible.',
        );

        $this->assertGreaterThan(
            PlafondsDeDepot::parFichier(),
            PlafondsDeDepot::parRequete(),
            'post_max_size doit depasser upload_max_filesize.',
        );

        $this->assertGreaterThan(
            8192 * 1024,
            PlafondsDeDepot::parFichier(),
            'upload_max_filesize doit depasser MAX_SIZE, sinon PHP devance la '
            .'validation et le message perd la taille.',
        );

        $this->assertGreaterThan(
            4 * PlafondsDeDepot::parRequete(),
            $this->limiteMemoire(),
            'memory_limit doit tenir quatre fois post_max_size : c\'est ce que '
            .'PHP alloue pour un corps urlencoded, avant la premiere ligne de '
            .'code. Sinon n\'importe qui obtient un 500 au corps vide sur une '
            .'route non authentifiee.',
        );
    }

    /**
     * memory_limit en octets, converti ici et non dans le code de production.
     *
     * VignetteApercu en tient sa propre lecture, privee, parce qu'elle doit
     * traiter `memory_limit=-1` pour son garde-fou. Ce test n'a pas ce cas a
     * couvrir : l'image pose une valeur, et une limite absente ferait echouer
     * l'assertion, ce qui est le comportement voulu.
     */
    private function limiteMemoire(): int
    {
        $brut = trim((string) ini_get('memory_limit'));

        $facteur = match (strtoupper(substr($brut, -1))) {
            'K'     => 1024,
            'M'     => 1024 ** 2,
            'G'     => 1024 ** 3,
            default => 1,
        };

        return (int) $brut * $facteur;
    }

    // ── Le refus rendu avant le noyau ────────────────────────────────────

    /**
     * Le plafond garde TOUTES les routes POST, pas le seul depot de photos.
     *
     * Ce n'est pas une precaution : c'etait la panne. Sous post_max_size=92M et
     * memory_limit=128M, POST /api/login — route NON AUTHENTIFIEE — rendait, par
     * HTTP reel :
     *
     *     33 554 428 o urlencoded -> 500 AU CORPS VIDE, 15,76 s
     *     94 371 840 o json       -> 500 AU CORPS VIDE, 87,94 s
     *
     * Ce test tient la moitie qu'un test PEUT tenir : que le refus du corps
     * annonce soit le meme sur une route qui n'a rien a voir avec les photos.
     * L'autre moitie — qu'il arrive AVANT que Laravel lise le corps — se joue
     * dans public/index.php, hors du noyau, et c'est
     * test_le_refus_du_corps_precede_la_capture_de_la_requete qui l'epingle.
     */
    public function test_un_corps_trop_lourd_est_refuse_sur_une_route_sans_photo(): void
    {
        $reponse = $this->call(
            'POST',
            '/api/login',
            [],
            [],
            [],
            [
                'CONTENT_LENGTH' => (string) (PlafondsDeDepot::parRequete() + 1),
                'CONTENT_TYPE'   => 'application/json',
                'HTTP_ACCEPT'    => 'application/json',
            ],
        );

        $reponse->assertStatus(413);

        $this->assertSame(
            PlafondsDeDepot::messageCorpsTropLourd(PlafondsDeDepot::parRequete() + 1),
            (string) $reponse->json('message'),
        );
    }

    /**
     * LE REFUS PRECEDE `Request::capture()`, et l'ordre est tout.
     *
     * Mesure de ce qui arrive quand il ne le precede pas. `Request::capture()`
     * appelle `Illuminate\Http\Request::createFromBase()`, qui json_decode le
     * corps ENTIER des que le type est application/json — avant le noyau, donc
     * avant le premier intergiciel. Et `php artisan serve` garde tout le corps
     * dans le tampon de son SAPI, si bien que php://input le rend meme quand PHP
     * a refuse le corps. Par HTTP reel, post_max_size=24M, memory_limit=192M, un
     * corps JSON de 94 371 840 o sur POST /api/login :
     *
     *     sans refus dans le controleur frontal     -> 500 corps VIDE, 135,67 s
     *     avec un intergiciel en tete de pile       -> 500 corps VIDE, 114,35 s
     *     avec le refus dans le controleur frontal  -> 413, pic 7 911 216 o
     *
     * L'essai avec l'intergiciel a ete fait et abandonne. Un test qui passe par
     * le noyau ne peut PAS distinguer ces trois cas — il voit un 413 dans tous
     * ceux qui en rendent un —, donc celui-ci verifie la seule chose qui les
     * separe : la position du refus dans public/index.php. Il echoue si
     * quelqu'un le deplace apres la capture, ou le retire.
     */
    public function test_le_refus_du_corps_precede_la_capture_de_la_requete(): void
    {
        $frontal = (string) file_get_contents(base_path('public/index.php'));

        // Les DEUX sont cherches dans leur forme executable et non par leur
        // nom : le commentaire qui explique ce refus cite `Request::capture()`
        // plus haut dans le fichier, et chercher le seul nom faisait echouer ce
        // test sur sa propre explication.
        $refus   = strpos($frontal, 'if (($corpsTropLourd = PlafondsDeDepot::depassementAnnonce(');
        $capture = strpos($frontal, 'handleRequest(Request::capture())');

        $this->assertNotFalse(
            $refus,
            'public/index.php ne refuse plus le corps trop lourd : un corps JSON '
            .'plus gros que post_max_size redevient une erreur fatale de memoire.',
        );
        $this->assertNotFalse($capture, 'public/index.php n\'appelle plus handleRequest(Request::capture()).');

        $this->assertLessThan(
            $capture,
            $refus,
            'Le refus doit preceder Request::capture(), qui json_decode le corps '
            .'entier avant le noyau.',
        );
    }

    /**
     * Arrete ce test, BRUYAMMENT, quand ce PHP n'est pas regle comme l'image.
     *
     * Meme choix qu'exigerGd() dans PagesProduitTest, et pour la meme raison :
     * un test saute est un test qui ne prouve rien, et il faut que cela se
     * voie. upload_max_filesize et post_max_size sont des directives
     * PHP_INI_PERDIR — ce test ne peut pas se donner lui-meme le reglage qu'il
     * verifie, il ne peut que constater celui sous lequel il tourne.
     *
     * La CI pose les valeurs de l'image (.github/workflows/tests.yml), donc ce
     * message ne doit apparaitre que sur un poste ; s'il apparait, il dit quoi
     * faire.
     */
    private function exigerLesPlafondsDeLImage(): void
    {
        if (PlafondsDeDepot::parFichier() > 8192 * 1024) {
            return;
        }

        $this->markTestSkipped(sprintf(
            'CE PHP N\'EST PAS REGLE COMME L\'IMAGE DE PRODUCTION : '
            .'upload_max_filesize=%s, post_max_size=%s et memory_limit=%s, la ou l\'image '
            .'pose 9M, 24M et 192M (docker/php/depot-de-photos.ini). Le dimensionnement du '
            .'depot n\'est donc PAS verifie par cette execution — c\'est un trou de '
            .'couverture, pas une dispense, et c\'est precisement le reglage dont les deux '
            .'pannes venaient. La CI les pose (.github/workflows/tests.yml) ; sur un poste, '
            .'relancer avec un php.ini portant ces trois valeurs.',
            ini_get('upload_max_filesize'),
            ini_get('post_max_size'),
            ini_get('memory_limit'),
        ));
    }

    /**
     * Le premier message d'erreur de validation, celui que l'application
     * affiche.
     *
     * C'est ce que fait `_validationMessage()` dans api_exception.dart :
     * premiere valeur de `errors`, premier element. Passer par le meme chemin
     * que l'application evite de verifier un message qu'elle ne montrerait pas.
     */
    private function premierMessage(\Illuminate\Testing\TestResponse $reponse): string
    {
        $erreurs = (array) $reponse->json('errors');
        $premier = reset($erreurs);

        return (string) (is_array($premier) ? ($premier[0] ?? '') : $premier);
    }

    /**
     * Un fichier tel que PHP le laisse quand il depasse upload_max_filesize.
     *
     * Le quatrieme argument est le code d'erreur, et le dernier met
     * UploadedFile en mode test — sans lui, Symfony refuse un fichier qui n'est
     * pas passe par un vrai televersement.
     */
    private function fichierVideParPhp(string $nom): UploadedFile
    {
        $chemin = tempnam(sys_get_temp_dir(), 'vide');
        file_put_contents($chemin, '');

        return new UploadedFile($chemin, $nom, 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);
    }
}
