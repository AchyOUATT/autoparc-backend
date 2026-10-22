<?php

namespace Tests\Feature;

use App\Enums\VehicleStatus;
use App\Models\Accessory;
use App\Models\Brand;
use App\Models\Country;
use App\Models\EngineType;
use App\Models\Manufacturer;
use App\Models\Media;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Partner;
use App\Models\Vehicle;
use App\Models\User;
use App\Models\VehicleModel;
use App\Providers\AppServiceProvider;
use App\Support\VersionPartage;
use App\Support\VignetteApercu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Les pages publiques d'un article, et surtout leurs balises Open Graph.
 *
 * L'application sait partager un article, mais il n'existait aucune page par
 * article : le message ne pouvait porter que le lien du magasin
 * d'applications. Ces pages existent pour une raison precise et verifiable —
 * quand on colle leur adresse dans WhatsApp ou Facebook, le robot va chercher
 * la page et en tire une vignette : photo, titre, prix. C'est ce qui separe
 * « un lien » d'« un partage ».
 *
 * Le robot n'execute PAS de JavaScript. Tout ce qui doit apparaitre dans
 * l'apercu doit se trouver dans le HTML rendu par le serveur, et c'est ce que
 * ces tests verifient, balise par balise : elles ne se voient pas a l'oeil nu
 * dans un navigateur, une balise perdue ne casse rien et ne leve rien — elle
 * fait seulement partir des apercus muets, et personne ne le remarque avant
 * qu'un client ne dise « ton lien n'affiche rien ».
 *
 * Le dernier test est d'une autre nature : il garde la frontiere entre ce que
 * la boutique sait et ce qu'elle montre. Ces pages s'ouvrent sans compte, et
 * un lien partage circule bien au-dela de ceux a qui on l'a envoye.
 */
class PagesProduitTest extends TestCase
{
    use RefreshDatabase;

    /*
     * Valeurs INTERNES, posees sur chaque article fabrique ici.
     *
     * Elles sont choisies improbables — aucune ne peut apparaitre par hasard
     * dans une feuille de style, un prix de vente ou une annee — pour que le
     * test de fuite cherche LES VALEURS dans le HTML, et non des noms de
     * champs. Renommer `cost_price` ne ferait rien a la fuite ; c'est le
     * nombre qui ne doit pas sortir.
     */
    private const PRIX_ACHAT_VEHICULE  = 3_333_111;
    private const PRIX_ACHAT_PIECE     = 1_793;
    private const PRIX_ACHAT_ACCESSOIRE = 7_919;
    private const STOCKAGE_PIECE       = 'B7-RESERVE-42';
    private const STOCKAGE_ACCESSOIRE  = 'C3-FOND-09';
    private const FOURNISSEUR          = 'Sogetrans Import SARL';

    /*
     * Les champs que la surveillance ne couvrait PAS, et que les articles
     * fabriques ici portent desormais.
     *
     * Le relecteur a confirme a la main qu'aucun ne sort aujourd'hui. Le point
     * n'est pas la : les articles fabriques n'avaient ni vin, ni plaque, ni
     * code moteur, ni note de pivot, ni seuil d'alerte — si bien qu'une
     * precision « Plaque » ajoutee demain sans y penser passait les onze tests
     * sans en rougir un seul. Un test de fuite qui surveille moins large que sa
     * frontiere ne garde pas la frontiere, il garde l'illusion.
     *
     * Les coordonnees du fournisseur et la quantite en stock manquaient encore
     * a cette liste ; elles sont juste en dessous.
     *
     * Toutes improbables, pour la raison dite plus haut : c'est la VALEUR qui
     * est cherchee dans le HTML.
     */
    private const VIN              = 'JTDBR32E630XQ7741';
    private const PLAQUE           = '11JJ4471BF';
    private const CODE_MOTEUR      = '2ZRFXE9903';
    private const NOTE_PIVOT       = 'Remise fournisseur 17% negociee hors contrat';
    private const SEUIL_ALERTE     = 4_409;

    /*
     * Les deux categories que la surveillance ANNONCAIT sans les couvrir.
     *
     * Mesure du controle adversarial : la garde rougissait bien sur une plaque
     * et sur un seuil d'alerte injectes, mais restait VERTE sur « Ouedraogo
     * +22670000001 » et sur « Il reste 6 en reserve » — les coordonnees du
     * fournisseur et la quantite en stock, deux categories que la frontiere
     * nommait explicitement (« ni fournisseur », voir l'en-tete de
     * partage/article.blade.php). Rien ne fuyait ; c'etait la surveillance qui
     * couvrait moins large que ce qu'elle gardait.
     *
     * Le nom et le numero gardent les valeurs de la mesure, pour que ce fichier
     * couvre exactement le cas signale. Elles n'apparaissent nulle part dans le
     * gabarit, et le numero de la boutique est un autre (+22675570484, pose par
     * config/boutique.php) : une collision par hasard est impossible.
     *
     * Les quantites, elles, ETAIENT 6 et 3 — c'est-a-dire incherchables : « 6 »
     * se trouve dans une feuille de style, dans une annee, dans un prix. Elles
     * passent donc a des valeurs improbables, pour la raison qui vaut a tous
     * les interdits de ce fichier : c'est LA VALEUR qu'on cherche dans le HTML,
     * pas le nom du champ.
     */
    private const FOURNISSEUR_CONTACT   = 'Ouedraogo';
    private const FOURNISSEUR_TELEPHONE = '+22670000001';
    private const STOCK_PIECE           = 7_307;
    private const STOCK_ACCESSOIRE      = 2_161;

    /** Public, lui : le kilometrage est une precision que la page AFFICHE. */
    private const KILOMETRAGE      = 85_000;

    /*
     * La reference, deterministe.
     *
     * Elle etait en `uniqid()` : une valeur differente a chaque execution,
     * donc INVERIFIABLE — on ne peut pas chercher dans le HTML ce qu'on ne
     * connait pas. Un compteur garde l'unicite que la colonne exige, en
     * laissant le test savoir ce qu'il cherche.
     */
    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$compteur = 0;

        // Les photos vivent sur Cloudflare R2, dont les URL publiques sont
        // absolues : c'est ce qui les rend utilisables telles quelles par
        // og:image, que le robot ne sait pas resoudre relativement. Le disque
        // de test prend donc la meme forme, sinon on ne teste que le reglage
        // local.
        config(['filesystems.disks.public.url' => 'https://pub-essai-partage.r2.dev']);

        // L'adresse des pages vient de la CONFIGURATION et non de la requete
        // (voir ArticleController::adresse). La poser ici rend og:url,
        // <link rel="canonical"> et le lien WhatsApp verifiables au caractere.
        config(['app.url' => 'https://partage-essai.example']);
    }

    /** Une reference unique ET connue du test. */
    private function reference(string $prefixe): string
    {
        return $prefixe.'-FUITE-'.str_pad((string) ++self::$compteur, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Le contenu d'une balise meta, isole du reste de la page.
     *
     * Plusieurs verifications portent sur ce que le DESTINATAIRE lit — og:title,
     * og:image — et chercher la chaine dans le document entier les rend fausses
     * pour de mauvaises raisons : le lien WhatsApp pre-rempli contient
     * « Bonjour », donc « jour », et son adresse encodee contient « %2F », et
     * product:price:amount porte legitimement le montant brut « 3000 » que
     * l'affichage, lui, ne doit jamais montrer. Une assertion qui echoue sur le
     * mot « Bonjour » n'apprend rien et finit par etre relachee.
     */
    private function balise(string $html, string $propriete, string $attribut = 'property'): string
    {
        preg_match(
            '/<meta '.preg_quote($attribut, '/').'="'.preg_quote($propriete, '/').'" content="([^"]*)">/',
            $html,
            $trouve,
        );

        return $trouve[1] ?? '';
    }

    /** Le prix tel qu'il est AFFICHE dans le corps de la page. */
    private function prixAffiche(string $html): string
    {
        preg_match('/<p class="prix">([^<]*)<\/p>/', $html, $trouve);

        return $trouve[1] ?? '';
    }

    /* ------------------------------------------------------------------ */
    /* Fabrique                                                           */
    /* ------------------------------------------------------------------ */

    /** Un vehicule publie, donc partageable. */
    private function vehicule(array $attributs = []): Vehicle
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'toyota'],
            ['name' => 'Toyota', 'is_active' => true],
        );

        $modele = VehicleModel::firstOrCreate(
            ['slug' => 'corolla'],
            ['brand_id' => $marque->id, 'name' => 'Corolla', 'is_active' => true],
        );

        $motorisation = EngineType::firstOrCreate(
            ['code' => 'petrol'],
            ['label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
        );

        $vehicule = Vehicle::create(array_merge([
            'reference'           => $this->reference('VH'),
            // `registered` et non `unregistered` : c'est ce qui permet a la
            // fiche de porter une PLAQUE et un CODE MOTEUR, que le test de
            // fuite surveille desormais. Le detail d'immatriculation est cree
            // plus bas, avec un kilometrage plausible — `mileage_km` est
            // `default(0)` en base, et un detail cree sans lui ferait afficher
            // « 0 km » dans les precisions de toutes les pages du test.
            'registration_status' => 'registered',
            'vin'                 => self::VIN,
            'engine_code'         => self::CODE_MOTEUR,
            'brand_id'            => $marque->id,
            'vehicle_model_id'    => $modele->id,
            'engine_type_id'      => $motorisation->id,
            'manufacturing_year'  => 2015,
            'condition'           => 'used',
            'status'              => 'in_stock',
            'availability'        => 'sale',
            'purchase_price'      => self::PRIX_ACHAT_VEHICULE,
            'sale_price'          => 4_500_000,
            'currency'            => 'XOF',
            'partner_id'          => $this->fournisseur()->id,
            'published_at'        => now(),
        ], $attributs));

        // `registration_country_id` est contraint et sans defaut : le detail ne
        // peut pas exister sans pays.
        $vehicule->registrationDetail()->create([
            'plate_number'            => self::PLAQUE,
            'registration_country_id' => Country::firstOrCreate(
                ['iso2' => 'BF'],
                ['name' => 'Burkina Faso', 'iso3' => 'BFA'],
            )->id,
            'mileage_km'              => self::KILOMETRAGE,
        ]);

        return $vehicule->refresh();
    }

    private function piece(array $attributs = []): Part
    {
        $categorie = PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres']);

        $piece = Part::create(array_merge([
            'sku'                    => $this->reference('PRT'),
            'name'                   => 'Filtre à huile',
            'part_category_id'       => $categorie->id,
            'manufacturer_id'        => Manufacturer::firstOrCreate(['slug' => 'bosch'], ['name' => 'Bosch'])->id,
            'type'                   => 'aftermarket',
            'condition'              => 'new',
            'cost_price'             => self::PRIX_ACHAT_PIECE,
            'selling_price'          => 3_000,
            'currency'               => 'XOF',
            'vat_rate'               => 18.00,
            'stock_quantity'         => self::STOCK_PIECE,
            'stock_alert_threshold'  => self::SEUIL_ALERTE,
            'storage_location'       => self::STOCKAGE_PIECE,
            'is_active'              => true,
            'is_available'           => true,
        ], $attributs));

        $piece->partners()->attach($this->fournisseur()->id, [
            'role'  => 'fournisseur',
            'notes' => self::NOTE_PIVOT,
        ]);

        return $piece;
    }

    private function accessoire(array $attributs = []): Accessory
    {
        $accessoire = Accessory::create(array_merge([
            'sku'                   => $this->reference('ACC'),
            'name'                  => 'Caméra de recul',
            'category'              => 'securite',
            'manufacturer_id'       => Manufacturer::firstOrCreate(['slug' => 'bosch'], ['name' => 'Bosch'])->id,
            'cost_price'            => self::PRIX_ACHAT_ACCESSOIRE,
            'selling_price'         => 12_500,
            'currency'              => 'XOF',
            'vat_rate'              => 18.00,
            'stock_quantity'        => self::STOCK_ACCESSOIRE,
            'stock_alert_threshold' => self::SEUIL_ALERTE,
            'storage_location'      => self::STOCKAGE_ACCESSOIRE,
            'is_active'             => true,
            'is_available'          => true,
        ], $attributs));

        $accessoire->partners()->attach($this->fournisseur()->id, [
            'role'  => 'fournisseur',
            'notes' => self::NOTE_PIVOT,
        ]);

        return $accessoire;
    }

    private function fournisseur(): Partner
    {
        return Partner::firstOrCreate(
            ['company_name' => self::FOURNISSEUR],
            [
                // Les coordonnees passent par des constantes pour une seule
                // raison : le test de fuite peut desormais les chercher. Elles
                // etaient ecrites en clair ici, donc invisibles pour lui.
                'contact_name' => self::FOURNISSEUR_CONTACT,
                'phone'        => self::FOURNISSEUR_TELEPHONE,
                'is_active'    => true,
            ],
        );
    }

    /** On passe par la relation : mediable_type n'est pas assignable en masse. */
    private function photoSur(
        object $article,
        string $chemin,
        bool $couverture = false,
        string $disque = 'public',
        array $apercu = [],
    ): Media {
        return $article->media()->create(array_merge([
            'collection' => 'gallery',
            'path'       => $chemin,
            'disk'       => $disque,
            'is_cover'   => $couverture,
        ], $apercu));
    }

    /**
     * Arrete le test, BRUYAMMENT, quand GD n'est pas la.
     *
     * Un test saute est un test qui ne prouve rien, et c'est pire quand il
     * couvre la piece la plus fragile du lot : la generation de variante est le
     * seul endroit ou une photo televersee peut faire mourir la requete. La
     * CI installe donc gd (voir .github/workflows/tests.yml) et ce message ne
     * doit plus apparaitre nulle part — s'il apparait, il dit quoi faire.
     */
    private function exigerGd(): void
    {
        if (VignetteApercu::disponible()) {
            return;
        }

        $this->markTestSkipped(
            'GD ABSENT DE CE PHP : la generation de variante n\'est PAS verifiee par cette '
            .'execution. C\'est un trou de couverture, pas une dispense. La CI installe gd '
            .'(.github/workflows/tests.yml) et le Dockerfile de production aussi ; sur un poste, '
            .'relancer avec un php.ini portant extension=gd.'
        );
    }

    /**
     * Execute un travail avec la MEMOIRE LIBRE d'une requete de depot reelle.
     *
     * Ce n'est pas un contournement du garde-fou de VignetteApercu, c'est la
     * seule facon de le soumettre a la bonne valeur. Le garde-fou compare le
     * besoin a ce qui RESTE, et ce qui reste dans un processus PHPUnit n'a rien
     * a voir avec ce qui reste dans une requete. Mesure du socle deja consomme,
     * relevee sous memory_limit=128M :
     *
     *     requete HTTP de depot, noyau amorce, multipart de 2,7 Mo -> 26,0 Mo,
     *         donc 102,0 Mo libres
     *
     * CES 102 Mo SONT DESORMAIS UN PLANCHER, pas la valeur de production.
     * docker/php/depot-de-photos.ini pose memory_limit=192M, ce qui laisse
     * ~166 Mo a la meme requete — le socle, lui, ne depend pas de la limite
     * (mesure, GET /up sous 192M : pic 23 807 160 o ; POST /api/login avec un
     * corps de 63 o : 26 566 608 o). Garder 102 Mo rend donc ces tests PLUS
     * serres que la production, ce qui est le bon sens de l'ecart : un
     * renoncement ici se verrait, un renoncement en ligne non.
     *
     * Le socle d'un processus PHPUnit arrive ici dans la suite complete n'a pas
     * ete mesure directement, mais il se DEDUIT d'un refus observe : la
     * generation d'une image de 2400x1600, qui demande 19 Mo, a ete refusee a
     * cet endroit de la suite. Le garde-fou n'accordant que quatre cinquiemes
     * du reste, cela place le socle au-dessus de 104 Mo, soit moins de 24 Mo
     * libres.
     *
     * Sous cette valeur, la generation renonce — correctement, c'est son
     * travail — et les tests qui mesurent la variante PRODUITE n'auraient plus
     * rien a mesurer. Ils tourneraient sous une contrainte que la production ne
     * connait pas, et leur resultat dependrait de la memoire laissee par les
     * tests precedents, c'est-a-dire de leur ordre.
     *
     * La limite est donc posee a « usage courant + les 102 Mo d'une requete de
     * depot », puis rendue telle qu'elle etait.
     */
    private function avecLaMemoireDUnDepot(callable $travail): mixed
    {
        $origine = (string) ini_get('memory_limit');

        ini_set('memory_limit', (string) (memory_get_usage(true) + 102 * 1024 * 1024));

        try {
            return $travail();
        } finally {
            ini_set('memory_limit', $origine);
        }
    }

    /**
     * Un JPEG de la taille demandee, detaille, et PEU COUTEUX a fabriquer.
     *
     * `imagecreate` et non `imagecreatetruecolor` : la premiere alloue un octet
     * par pixel, la seconde quatre. La difference decide si la suite tourne.
     * Mesure avec la fabrication en couleurs vraies, GD charge et
     * memory_limit=128M — ce que l'image posait alors ; elle pose 192M depuis,
     * et le choix de la palette reste le bon pour la meme raison, la
     * fabrication de l'entree n'ayant aucune raison de couter quatre fois le
     * necessaire :
     *
     *     Fatal error: Allowed memory size of 134217728 bytes exhausted
     *     (tried to allocate 16384 bytes) in app/Support/VignetteApercu.php:224
     *     Fatal error: Premature end of PHP process when running
     *     Tests\Feature\PagesProduitTest::test_la_variante_est_bornee_et_legere
     *
     * Soit la suite ENTIERE interrompue : 36 Mo partaient dans la seule
     * fabrication de l'image d'entree, avant que le code mesure ne commence.
     * Le test se payait la panne qu'il est charge de surveiller.
     *
     * Les 256 couleurs d'une palette suffisent au detail qui compte ici : le
     * JPEG produit doit rester lourd, pour que la variante prouve quelque chose
     * en etant legere.
     */
    private function jpegDePhoto(int $largeur, int $hauteur, int $qualite = 90): string
    {
        $image = imagecreate($largeur, $hauteur);

        $couleurs = [];

        for ($i = 0; $i < 256; $i++) {
            $couleurs[] = imagecolorallocate($image, ($i * 7) % 256, ($i * 29) % 256, ($i * 61) % 256);
        }

        for ($y = 0; $y < $hauteur; $y += 4) {
            for ($x = 0; $x < $largeur; $x += 4) {
                imagefilledrectangle(
                    $image, $x, $y, $x + 3, $y + 3,
                    $couleurs[($x * 7 + $y * 3) % 256],
                );
            }
        }

        ob_start();
        imagejpeg($image, null, $qualite);
        $octets = (string) ob_get_clean();
        imagedestroy($image);

        return $octets;
    }

    /* ------------------------------------------------------------------ */
    /* Les trois routes repondent                                          */
    /* ------------------------------------------------------------------ */

    public function test_les_trois_routes_rendent_la_page_d_un_article_actif(): void
    {
        $this->get('/v/'.$this->vehicule()->id)->assertOk();
        $this->get('/p/'.$this->piece()->id)->assertOk();
        $this->get('/a/'.$this->accessoire()->id)->assertOk();
    }

    /**
     * La bande d'installation precede la photo, sur les trois types.
     *
     * C'EST LA POSITION QUI EST TESTEE, pas la presence. Le lien vers
     * l'application existait deja avant ce test — il etait simplement DERNIER,
     * apres la photo, le prix, les precisions et deux boutons de contact, donc
     * invisible pour qui ne defile pas. Un test de presence serait reste vert
     * pendant tout le temps ou le defaut existait.
     *
     * La comparaison porte sur la position des deux chaines dans le HTML rendu
     * et non sur une structure de noeuds : c'est l'ordre d'apparition qui
     * decide ce que le visiteur voit en ouvrant la page, et c'est la seule
     * chose qu'un remaniement de gabarit puisse casser sans le vouloir.
     */
    public function test_la_bande_d_installation_precede_la_photo(): void
    {
        foreach ([['v', $this->vehicule()], ['p', $this->piece()], ['a', $this->accessoire()]] as [$chemin, $article]) {
            $html = $this->get("/{$chemin}/{$article->id}")->assertOk()->getContent();

            $bande = strpos($html, 'class="bandeau"');

            $this->assertNotFalse($bande, "La bande d'installation est absente de /{$chemin}.");

            // L'ANCRE EST `class="corps"`, ET CE CHOIX EST LE FRUIT D'UN ECHEC.
            //
            // La premiere version comparait a `class="photo"`, sous un
            // `if ($photo !== false)` — parce que la photo est conditionnelle.
            // Or les articles de ce banc n'en ont pas : la branche ne
            // s'executait JAMAIS et le test restait vert. Verifie par mutation,
            // la seule facon de s'en apercevoir : bande deplacee APRES
            // `@yield('contenu')`, test toujours au vert. Une garde inerte est
            // pire qu'absente, elle fait croire que le defaut est couvert.
            //
            // `class="corps"` est rendu inconditionnellement et contient le
            // titre, donc tout ce que le visiteur lit. La bande doit le
            // preceder, photo ou pas.
            $corps = strpos($html, 'class="corps"');

            $this->assertNotFalse($corps, "Le corps de la page est absent de /{$chemin}.");

            $this->assertLessThan(
                $corps,
                $bande,
                "Sur /{$chemin}, la bande d'installation est passee APRES le contenu : ".
                'un visiteur qui ne defile pas ne saura plus que l\'application existe.'
            );

            // Le bouton du bas reste : la bande ne le remplace pas, elle le
            // double en haut. Les deux menent au magasin, et le contact reste
            // libre entre les deux.
            $this->assertStringContainsString('class="action magasin"', $html);
        }
    }

    public function test_un_article_retire_du_catalogue_rend_404(): void
    {
        // Une page partagee ne doit pas survivre au retrait de l'article : le
        // lien continue de circuler longtemps apres l'envoi du message.
        //
        // POUR UN VEHICULE, LE RETRAIT EST LE STATUT, ET NON `published_at`.
        // Ce test exigeait l'inverse, et il avait tort : personne ne pose
        // jamais `published_at`. L'action existe cote serveur
        // (POST /staff/vehicles/{id}/publish) mais aucun ecran de
        // l'application ne l'appelle, donc tout vehicule enregistre depuis
        // l'application l'a a null. Mesure en production : la seule annonce du
        // parc rendait 404 et son bouton de partage etait masque — la fonction
        // etait morte pour tous les vehicules.
        //
        // Un vehicule NON PUBLIE garde donc sa page, parce que le catalogue le
        // montre ; c'est la regle de catalogueMontre(), et le cas est epingle
        // ici pour qu'on ne la reinverse pas sans le vouloir.
        $this->get('/v/'.$this->vehicule(['published_at' => null])->id)->assertOk();

        // VIN distinct : la fabrique en pose un fixe, et ce test est le seul a
        // demander DEUX vehicules.
        $this->get('/v/'.$this->vehicule([
            'status' => VehicleStatus::Sold,
            'vin' => 'JTDBR32E630XQ9999',
        ])->id)->assertNotFound();
        $this->get('/p/'.$this->piece(['is_active' => false])->id)->assertNotFound();
        $this->get('/a/'.$this->accessoire(['is_active' => false])->id)->assertNotFound();
    }

    public function test_un_identifiant_inexistant_rend_404(): void
    {
        $this->get('/v/999999')->assertNotFound();
        $this->get('/p/999999')->assertNotFound();
        $this->get('/a/999999')->assertNotFound();
    }

    /* ------------------------------------------------------------------ */
    /* Aucun cookie : ces pages n'ont aucun etat a retenir                 */
    /* ------------------------------------------------------------------ */

    public function test_aucune_reponse_ne_pose_de_cookie(): void
    {
        // Ces routes vivaient dans le groupe 'web', donc derriere StartSession
        // et AddQueuedCookiesToResponse. Mesure sur GET /v/1 :
        // « cache-control: max-age=300, public » ET
        // « set-cookie: XSRF-TOKEN=... | laravel-session=...; httponly ».
        //
        // « public » autorise tout cache partage — mandataire d'entreprise,
        // cache d'operateur, Cloudflare — a stocker la reponse et a la
        // resservir a quelqu'un d'autre, cookie de session compris.
        //
        // Et comme SESSION_DRIVER vaut `database` par defaut sans que
        // render.yaml ne le pose, chaque visite anonyme ecrivait une ligne dans
        // `sessions` : un lien qui tourne dans un groupe de 200 personnes, ce
        // sont 200 lignes sur le Postgres gratuit, pour des pages qui n'ont
        // aucun etat a retenir.
        //
        // Ce test est la pour que le defaut ne revienne pas au premier refactor
        // de routes : il suffirait de redeclarer ces trois lignes dans
        // routes/web.php pour tout reproduire, sans qu'aucun autre test ne
        // bronche.
        foreach ([
            '/v/'.$this->vehicule()->id,
            '/p/'.$this->piece()->id,
            '/a/'.$this->accessoire()->id,
        ] as $adresse) {
            $reponse = $this->get($adresse)->assertOk();

            $this->assertSame(
                [],
                $reponse->headers->getCookies(),
                "La page {$adresse} pose un cookie : elle est repassee dans le groupe 'web'.",
            );

            $this->assertEmpty(
                $reponse->headers->all('set-cookie'),
                "La page {$adresse} porte un en-tete Set-Cookie.",
            );
        }
    }

    public function test_la_mise_en_cache_publique_reste_sure_faute_de_cookie(): void
    {
        // « public » n'est defendable que parce qu'il n'y a plus rien de
        // personnel dans la reponse. Les deux assertions tiennent ENSEMBLE :
        // separees, on pourrait rendre « public » a une reponse qui pose a
        // nouveau un cookie, et c'est exactement le defaut mesure.
        $reponse = $this->get('/v/'.$this->vehicule()->id)->assertOk();

        $this->assertStringContainsString('public', (string) $reponse->headers->get('Cache-Control'));
        $this->assertSame([], $reponse->headers->getCookies());
    }

    /* ------------------------------------------------------------------ */
    /* L'adresse de la page vient de la configuration                      */
    /* ------------------------------------------------------------------ */

    public function test_og_url_ne_suit_pas_l_hote_fourni_par_le_client(): void
    {
        // url()->current() suivait l'hote de la requete, et bootstrap/app.php
        // pose trustProxies(at: '*') — assume et commente pour $request->ip() —,
        // ce qui fait aussi confiance a X-Forwarded-Host.
        //
        // Mesure : GET /v/1 avec « X-Forwarded-Host: pharmacie-cadeaux.example »
        // rendait <meta property="og:url"
        // content="http://pharmacie-cadeaux.example/v/1">, et le lien WhatsApp
        // pre-rempli portait la meme adresse. Combine a une reponse mise en
        // cache, l'apercu empoisonne devenait stockable et resservable.
        $vehicule = $this->vehicule();

        $html = $this->withHeaders(['X-Forwarded-Host' => 'pharmacie-cadeaux.example'])
            ->get('/v/'.$vehicule->id)
            ->assertOk()
            ->getContent();

        // og:url porte desormais le marqueur de version — l'adresse marquee,
        // pas l'adresse nue. Voir VersionDePartageTest pour le contrat ; ce
        // test-ci ne regarde que l'HOTE, qui doit rester celui de la
        // configuration quoi qu'arrive. Le marqueur est compose ici plutot
        // qu'ecrit en dur : il depend de `updated_at`, que la fabrique pose a
        // l'instant du test.
        $attendu = 'https://partage-essai.example/v/'.$vehicule->id
            .'?m='.VersionPartage::pour($vehicule->updated_at);

        $this->assertStringContainsString(
            '<meta property="og:url" content="'.$attendu.'">',
            $html,
        );

        // Nulle part : ni dans og:url, ni dans le canonique, ni dans le texte
        // pre-rempli du lien WhatsApp — que le destinataire renverrait.
        $this->assertStringNotContainsString('pharmacie-cadeaux', $html);
    }

    public function test_un_lien_canonique_designe_l_adresse_construite(): void
    {
        // /v/1?utm_source=x et /v/1 etaient deux pages pour l'indexation, alors
        // qu'une page de partage n'en est qu'une — et un lien colle dans une
        // conversation gagne des parametres de suivi en route.
        $vehicule = $this->vehicule();
        $attendu  = 'https://partage-essai.example/v/'.$vehicule->id;

        $html = $this->get('/v/'.$vehicule->id.'?utm_source=whatsapp&utm_campaign=mars')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('<link rel="canonical" href="'.$attendu.'">', $html);

        // og:url, lui, porte le marqueur de version : c'est ce qui fait
        // re-cler la vignette chez Facebook apres une modification. Le
        // canonique au-dessus reste NU pour la raison inverse — un article est
        // UNE page pour un moteur de recherche. La difference entre les deux
        // balises est mesuree par VersionDePartageTest ; ici on verifie
        // seulement que les parametres de SUIVI, eux, ne survivent ni dans
        // l'une ni dans l'autre.
        $this->assertStringContainsString(
            '<meta property="og:url" content="'.$attendu
                .'?m='.VersionPartage::pour($vehicule->updated_at).'">',
            $html,
        );
        $this->assertStringNotContainsString('utm_source', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Le statut : un vehicule VENDU n'a plus de page                      */
    /* ------------------------------------------------------------------ */

    /**
     * Un test PAR STATUT, et c'est voulu : la liste de l'enum est la frontiere
     * exacte du correctif, et un statut ajoute demain doit faire discuter ce
     * tableau plutot que de passer inapercu.
     *
     * Le retrait retenu a la livraison etait `published_at`, mais le vrai geste
     * de sortie du parc est la vente : SaleController fait
     * update(['status' => VehicleStatus::Sold]) et ne touche jamais
     * published_at. Mesure, un vehicule publie, par statut : in_stock 200,
     * reserved 200, sold 200, rented 200, maintenance 200, in_transit 200,
     * archived 200 — et le HTML du vendu portait og:title « ... — 4 500 000
     * FCFA ». La voiture part lundi, le lien continue de circuler, et jeudi un
     * client ecrit pour une Corolla qui n'est plus la.
     *
     * @return array<string, array{string, int}>
     */
    public static function statuts(): array
    {
        return [
            'in_stock'    => ['in_stock', 200],
            'reserved'    => ['reserved', 200],
            'rented'      => ['rented', 200],
            'maintenance' => ['maintenance', 200],
            'in_transit'  => ['in_transit', 200],
            // Les deux seuls retraits : le vehicule a quitte le parc.
            'sold'        => ['sold', 404],
            'archived'    => ['archived', 404],
        ];
    }

    #[DataProvider('statuts')]
    public function test_la_page_d_un_vehicule_suit_son_statut(string $statut, int $attendu): void
    {
        $vehicule = $this->vehicule(['status' => $statut]);

        $this->get('/v/'.$vehicule->id)->assertStatus($attendu);
    }

    public function test_le_prix_d_un_vehicule_vendu_ne_circule_plus(): void
    {
        // Le 404 ne suffit pas a dire ce qui compte : c'est le PRIX qui ne doit
        // plus partir dans une conversation. Mesure avant correction, le HTML du
        // vendu portait <meta property="og:title"
        // content="Toyota Corolla 2015 — 4 500 000 FCFA">.
        $vendu = $this->vehicule(['status' => 'sold']);

        $html = $this->get('/v/'.$vendu->id)->assertNotFound()->getContent();

        $this->assertStringNotContainsString('4'."\u{A0}".'500'."\u{A0}".'000', $html);
        $this->assertStringNotContainsString('og:title', $html);
    }

    /* ------------------------------------------------------------------ */
    /* La regle des pages, et l'asymetrie qu'elle produit                  */
    /*                                                                     */
    /* LA REGLE : une page de partage existe SI ET SEULEMENT SI le          */
    /* catalogue de l'application montre l'article. C'est elle qui garantit */
    /* que « Ouvrir dans AutoParc » ne soit jamais une impasse — le         */
    /* visiteur arrive ici PARCE QU'IL N'A PAS l'application, et l'envoyer  */
    /* l'installer pour un article que ses ecrans ne listent pas est le     */
    /* pire resultat possible.                                             */
    /*                                                                     */
    /* Les deux tests qui suivent paraissent se contredire : un accessoire  */
    /* indisponible rend 404, une piece indisponible rend 200. Ce n'est pas */
    /* un defaut, et il ne faut PAS « corriger par symetrie ». L'asymetrie  */
    /* vient des CATALOGUES, pas des pages :                               */
    /*   — AccessoryController::catalog filtre active() ET is_available ;   */
    /*   — PartController::index filtre active(), mais is_available n'y est */
    /*     qu'un filtre OPTIONNEL (`in_stock`).                             */
    /* La regle est unique, elle est ecrite une fois dans                   */
    /* ArticleController::catalogueMontre, et c'est elle qui produit les    */
    /* deux comportements. Changer l'un des deux ici reviendrait a faire    */
    /* mentir la page sur ce que l'application montre.                      */
    /* ------------------------------------------------------------------ */

    public function test_un_accessoire_indisponible_n_a_plus_de_page(): void
    {
        // AccessoryController::catalog filtre active() ET
        // where('is_available', true), et la route des categories applique
        // explicitement les memes deux conditions, avec ce commentaire : « sans
        // quoi une pastille promettrait des articles que l'ecran ne montre
        // pas ». La page de partage ne regardait que is_active.
        //
        // Mesure : accessoire is_available=false, stock_quantity=0 -> 200. Le
        // bouton « Ouvrir dans AutoParc » conduisait alors a une application ou
        // l'article est introuvable — l'impasse que ces pages existent pour
        // supprimer, a l'envers.
        $this->get('/a/'.$this->accessoire([
            'is_available'   => false,
            'stock_quantity' => 0,
        ])->id)->assertNotFound();

        $this->get('/a/'.$this->accessoire(['is_active' => false])->id)->assertNotFound();

        // Et la page reste la quand les deux conditions sont tenues, sans quoi
        // ce test passerait aussi en rendant 404 a tout le monde.
        $this->get('/a/'.$this->accessoire()->id)->assertOk();
    }

    public function test_une_piece_en_rupture_garde_sa_page(): void
    {
        // Volontaire, et herite du catalogue : voir la regle enoncee au-dessus.
        // Ce test fixe la difference avec l'accessoire pour qu'elle ne soit pas
        // « corrigee » par symetrie.
        $this->get('/p/'.$this->piece([
            'is_available'   => false,
            'stock_quantity' => 0,
        ])->id)->assertOk();
    }

    public function test_une_piece_servie_en_rupture_le_dit_et_ne_promet_pas_de_vente(): void
    {
        // Le 200 n'est que la moitie de la regle. Servir sans prevenir fait
        // ecrire un client pour un article qui n'est pas la : l'impasse de
        // « Ouvrir dans AutoParc », par l'autre bout.
        $piece = $this->piece(['is_available' => false, 'stock_quantity' => 0]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        // 1. La page le dit, en clair, dans son corps.
        $this->assertStringContainsString('Rupture de stock', $html);

        // 2. L'apercu ne promet plus de vente immediate. C'est og:title qui
        //    s'affiche en gras dans la conversation, et un prix y est une
        //    promesse — « Filtre à huile — 3 000 FCFA » fait ecrire, attendre,
        //    puis repartir.
        $titre = $this->balise($html, 'og:title');

        $this->assertSame('Filtre à huile — Rupture de stock', $titre);
        $this->assertStringNotContainsString('FCFA', $titre, 'og:title promet encore un prix.');

        // 3. Le prix reste AFFICHE dans le corps : c'est lui qui decide
        //    d'attendre le reapprovisionnement ou de chercher ailleurs. Le
        //    taire obligerait a ouvrir une conversation pour l'apprendre.
        $this->assertSame('3'."\u{A0}".'000'."\u{A0}".'FCFA', $this->prixAffiche($html));

        // 4. Et la moitie lue par une machine dit la meme chose. og:type vaut
        //    « product » et la page porte product:price:amount : sans cette
        //    balise, un agregateur lit un prix et en conclut un article a
        //    vendre, pendant que la page dit le contraire a l'humain.
        $this->assertSame('out of stock', $this->balise($html, 'product:availability'));

        // Et la page d'une piece DISPONIBLE ne porte rien de tout cela, sans
        // quoi ce test passerait aussi en marquant tout le catalogue en rupture.
        $disponible = $this->get('/p/'.$this->piece()->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('Rupture de stock', $disponible);
        $this->assertSame('', $this->balise($disponible, 'product:availability'));
        $this->assertStringContainsString('FCFA', $this->balise($disponible, 'og:title'));
    }

    /* ------------------------------------------------------------------ */
    /* Les balises de l'apercu                                             */
    /* ------------------------------------------------------------------ */

    public function test_og_title_porte_le_nom_et_le_prix(): void
    {
        // C'est la ligne en gras de la conversation, et la seule qui dise au
        // destinataire si l'article l'interesse sans ouvrir la page.
        $html = $this->get('/v/'.$this->vehicule()->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="Toyota Corolla 2015 — 4'."\u{A0}".'500'."\u{A0}".'000'."\u{A0}".'FCFA">',
            $html,
        );

        $htmlPiece = $this->get('/p/'.$this->piece()->id)->assertOk()->getContent();

        $this->assertStringContainsString('og:title', $htmlPiece);
        $this->assertStringContainsString('Filtre à huile', $htmlPiece);
        $this->assertStringContainsString('3'."\u{A0}".'000'."\u{A0}".'FCFA', $htmlPiece);
    }

    public function test_og_image_porte_l_url_absolue_de_la_photo_de_couverture(): void
    {
        $piece = $this->piece();

        // Deux photos, la couverture n'etant pas la premiere : og:image doit
        // suivre `is_cover` et non l'ordre d'insertion.
        $this->photoSur($piece, 'parts/filtre-1.jpg');
        $this->photoSur($piece, 'parts/filtre-couverture.jpg', couverture: true);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:image" content="https://pub-essai-partage.r2.dev/parts/filtre-couverture.jpg">',
            $html,
        );
    }

    public function test_sans_photo_la_balise_og_image_est_absente(): void
    {
        // Absente, et non vide : une balise vide ou pointant un fichier qui
        // n'existe pas donne une vignette cassee dans la conversation, ce qui
        // fait passer la page pour une page en panne. Pire que pas d'image.
        $html = $this->get('/a/'.$this->accessoire()->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('og:image', $html);
    }

    public function test_sans_photo_la_carte_twitter_ne_promet_pas_de_grande_image(): void
    {
        // twitter:card="summary_large_image" etait rendu MEME sans og:image :
        // la carte promet une grande image qui n'existe pas, et le lecteur
        // affiche un cadre vide au lieu du resume compact qu'il aurait choisi
        // de lui-meme.
        $sansPhoto = $this->get('/a/'.$this->accessoire()->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('twitter:card', $sansPhoto);

        $piece = $this->piece();
        $this->photoSur($piece, 'parts/filtre.jpg', couverture: true);

        $avecPhoto = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta name="twitter:card" content="summary_large_image">',
            $avecPhoto,
        );
    }

    public function test_og_image_encode_le_chemin(): void
    {
        // Blade echappe le HTML, pas l'URL. Mesure, avec
        // path='vehicles/corolla couv (1).jpg' :
        // content="https://pub-....r2.dev/vehicles/corolla couv (1).jpg" — deux
        // espaces brutes dans un attribut d'URL, aucune vignette.
        //
        // Non atteignable aujourd'hui, MediaController nommant en Str::uuid(),
        // mais la colonne est remplie par l'application et des lignes anciennes
        // survivent aux changements de reglage.
        $piece = $this->piece();
        $this->photoSur($piece, 'parts/corolla couv (1).jpg', couverture: true);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:image" content="https://pub-essai-partage.r2.dev/parts/corolla%20couv%20%281%29.jpg">',
            $html,
        );

        // Les « / » restent des separateurs : rawurlencode sur le chemin entier
        // les transformerait en %2F et l'adresse ne designerait plus rien. La
        // verification porte sur la BALISE seule — le lien WhatsApp, lui, porte
        // une adresse entierement encodee, %2F compris, et c'est normal.
        $this->assertStringNotContainsString('%2F', $this->balise($html, 'og:image'));
    }

    /* ------------------------------------------------------------------ */
    /* La variante d'image servie a og:image                               */
    /* ------------------------------------------------------------------ */

    public function test_og_image_sert_la_variante_et_declare_ses_dimensions(): void
    {
        // Sans og:image:width/height/type, le robot doit telecharger l'image
        // ENTIERE avant de composer la vignette, au premier appel — celui qui a
        // deja le reveil de l'instance Render devant lui.
        $piece = $this->piece();

        $this->photoSur($piece, 'parts/original.jpg', couverture: true, apercu: [
            'mime_type'      => 'image/jpeg',
            'preview_path'   => 'parts/apercus/original.jpg',
            'preview_width'  => 840,
            'preview_height' => 630,
        ]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        // La VARIANTE, pas l'original : c'est tout l'objet du correctif.
        $this->assertStringContainsString(
            '<meta property="og:image" content="https://pub-essai-partage.r2.dev/parts/apercus/original.jpg">',
            $html,
        );
        $this->assertStringNotContainsString('r2.dev/parts/original.jpg', $html);

        $this->assertStringContainsString('<meta property="og:image:width" content="840">', $html);
        $this->assertStringContainsString('<meta property="og:image:height" content="630">', $html);
        $this->assertStringContainsString('<meta property="og:image:type" content="image/jpeg">', $html);
    }

    public function test_sans_variante_l_apercu_retombe_sur_l_original(): void
    {
        // Les media deja en base n'ont pas de variante, et doivent garder
        // l'apercu qu'ils avaient — mediocre, mais existant. Sans ce repli, la
        // livraison de la variante aurait fait disparaitre la vignette de tout
        // le stock actuel. La commande `media:apercus` les rattrape.
        $piece = $this->piece();

        $this->photoSur($piece, 'parts/ancienne.jpg', couverture: true, apercu: [
            'mime_type' => 'image/png',
        ]);

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:image" content="https://pub-essai-partage.r2.dev/parts/ancienne.jpg">',
            $html,
        );

        // Le type est connu par la colonne `mime_type`, les dimensions non :
        // les lire exigerait de telecharger le fichier a chaque rendu, et une
        // dimension annoncee fausse vaut moins que pas de dimension.
        $this->assertStringContainsString('<meta property="og:image:type" content="image/png">', $html);
        $this->assertStringNotContainsString('og:image:width', $html);
        $this->assertStringNotContainsString('og:image:height', $html);
    }

    public function test_un_media_sur_le_disque_s3_rend_l_adresse_publique(): void
    {
        // La fabrique ecrivait ses media avec disk='public' et une URL
        // detournee, alors que la PRODUCTION ecrit disk='s3' (MEDIA_DISK). Le
        // chemin d'URL teste n'etait donc pas celui qui tourne en ligne : les
        // deux pilotes ne composent pas l'adresse de la meme facon, et c'est
        // precisement la ou og:image se joue.
        config(['filesystems.disks.s3.url' => 'https://pub-essai-s3.r2.dev']);
        Storage::forgetDisk('s3');

        $piece = $this->piece();
        $this->photoSur($piece, 'parts/photo s3.jpg', couverture: true, disque: 's3');

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:image" content="https://pub-essai-s3.r2.dev/parts/photo%20s3.jpg">',
            $html,
        );
    }

    public function test_sans_adresse_publique_le_disque_s3_n_emet_pas_d_og_image(): void
    {
        // Cas de bord MESURE : MEDIA_DISK=s3 et AWS_URL vide. Le pilote s3 se
        // rabat alors sur AWS_ENDPOINT et rend
        // « https://autoparc.abc.r2.cloudflarestorage.com/autoparc/... », le
        // point d'acces PRIVE de l'API R2 — qui repond 403 a toute demande non
        // signee, donc a tout robot.
        //
        // La balise est omise plutot que de pointer une image interdite, et une
        // trace part dans le journal : une page de partage sans vignette est une
        // page de partage sans raison d'etre, ce n'est pas une situation a
        // traverser en silence. Ce test echoue dans l'ancienne implementation,
        // qui emettait l'adresse privee.
        config([
            'filesystems.disks.s3.url'      => null,
            'filesystems.disks.s3.endpoint' => 'https://autoparc.abc.r2.cloudflarestorage.com',
            'filesystems.disks.s3.bucket'   => 'autoparc',
        ]);
        Storage::forgetDisk('s3');

        $piece = $this->piece();
        $this->photoSur($piece, 'parts/photo.jpg', couverture: true, disque: 's3');

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('cloudflarestorage.com', $html);
        $this->assertStringNotContainsString('og:image', $html);
    }

    public function test_la_variante_est_bornee_et_legere(): void
    {
        // Le coeur du defaut : MediaController ecrit le fichier tel qu'il
        // arrive, jusqu'a 8192 ko, et les photos du parc sont des prises de
        // telephone qui pesent des megaoctets. WhatsApp refuse la vignette
        // au-dela de quelques centaines de ko et se rabat sur titre +
        // description.
        //
        // Les 8192 ko ne mordaient PAS avant docker/php/depot-de-photos.ini :
        // PHP plafonnait le depot a son defaut compile de 2M et refusait tout
        // au-dela (mesure par HTTP reel, 2 255 773 o -> 422). Le stock deja en
        // base pese donc au plus 2 Mo par photo ; ce test, lui, travaille sur ce
        // que le depot accepte DESORMAIS.
        //
        $this->exigerGd();

        Storage::fake('photos');

        // Une photo de telephone plausible : 4000x3000, du detail partout pour
        // qu'elle ne se comprime pas a rien. Un aplat ne dirait rien du cas
        // reel.
        $octets = $this->jpegDePhoto(4000, 3000);

        Storage::disk('photos')->put('vehicles/9/photo.jpg', $octets);

        // La memoire libre d'une requete de depot, et non celle que la suite a
        // laissee : une variante de 12 Mpx demande 52 Mo, qu'une requete reelle
        // a et qu'un processus PHPUnit arrive ici n'a plus. Le detail est dans
        // avecLaMemoireDUnDepot(). Le chemin a memoire serree est tenu par
        // test_le_depot_aboutit_meme_quand_la_variante_ne_tient_pas_en_memoire ;
        // ici on mesure ce que vaut la variante QUAND elle est produite : sa
        // borne et son poids.
        $apercu = $this->avecLaMemoireDUnDepot(
            fn () => VignetteApercu::generer('photos', 'vehicles/9/photo.jpg'),
        );

        $this->assertNotNull($apercu, 'Aucune variante produite alors que GD est disponible.');

        // Bornee au cadre que WhatsApp et Facebook recadrent sans marge.
        $this->assertLessThanOrEqual(VignetteApercu::LARGEUR_MAX, $apercu['largeur']);
        $this->assertLessThanOrEqual(VignetteApercu::HAUTEUR_MAX, $apercu['hauteur']);

        // Le rapport de l'original est conserve : un recadrage deformerait la
        // voiture dans l'apercu.
        $this->assertSame(
            round(4000 / 3000, 2),
            round($apercu['largeur'] / $apercu['hauteur'], 2),
        );

        // Les dimensions enregistrees sont celles du fichier ECRIT : ce sont
        // elles que la page declare, et une dimension fausse vaut moins que pas
        // de dimension.
        $ecrit = Storage::disk('photos')->get($apercu['chemin']);
        $reel  = getimagesizefromstring($ecrit);

        $this->assertSame($apercu['largeur'], $reel[0]);
        $this->assertSame($apercu['hauteur'], $reel[1]);

        // Toujours du JPEG, quel que soit le format d'entree : c'est ce qui
        // rend og:image:type deterministe.
        $this->assertSame(VignetteApercu::TYPE, $reel['mime']);

        // Le poids, qui est la raison d'etre de toute cette classe. 300 ko est
        // genereux : la mesure sur une photo de 6 114 957 octets donne
        // 78 493 octets. Le seuil garde la propriete qui compte — rester dans
        // ce que WhatsApp accepte — sans se briser au premier reglage de
        // qualite.
        $poids = strlen($ecrit);

        $this->assertLessThan(300 * 1024, $poids, "La variante pese {$poids} octets.");
        $this->assertLessThan(strlen($octets) / 4, $poids);
    }

    public function test_le_depot_d_une_photo_enregistre_sa_variante(): void
    {
        // La generation a l'upload est ce qui alimente og:image pour toutes les
        // photos a venir ; `media:apercus` ne rattrape que le stock existant.
        // Sans ce test, la chaine pourrait se couper cote depot sans qu'aucune
        // page ne change — les articles deja en base continueraient de servir
        // leur original, et le defaut reviendrait a l'identique sans bruit.
        //
        // Les tests de StockagePhotosTest envoient un fichier FACTICE, par choix
        // assume (« ->image() exigerait GD, absent de certains postes ») : la
        // variante n'y est donc jamais produite, et ce chemin-la restait
        // entierement non verifie.
        $this->exigerGd();

        Storage::fake('public');
        config(['media.disk' => 'public']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $piece = $this->piece();

        $octets = $this->jpegDePhoto(2400, 1600, 92);

        // La memoire libre d'une requete de depot : voir avecLaMemoireDUnDepot().
        // Sans cela, le resultat de ce test dependrait de ce que les tests
        // precedents ont laisse en memoire, c'est-a-dire de leur ordre.
        $this->avecLaMemoireDUnDepot(fn () => $this->postJson("/api/parts/{$piece->id}/media", [
            'photos' => [UploadedFile::fake()->createWithContent('photo.jpg', $octets)],
        ])->assertCreated());

        $media = Media::firstOrFail();

        $this->assertNotNull($media->preview_path, 'Le depot n\'a pas enregistre de variante.');
        $this->assertNotSame($media->path, $media->preview_path);
        Storage::disk('public')->assertExists($media->preview_path);

        $this->assertLessThanOrEqual(VignetteApercu::LARGEUR_MAX, $media->preview_width);
        $this->assertLessThanOrEqual(VignetteApercu::HAUTEUR_MAX, $media->preview_height);

        // La variante est plus legere que l'original — c'est toute la raison de
        // son existence : WhatsApp refuse la vignette au-dela de quelques
        // centaines de ko.
        $this->assertLessThan(
            Storage::disk('public')->size($media->path),
            Storage::disk('public')->size($media->preview_path),
        );

        // Et la page de partage sert bien la variante, pas l'original.
        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString($media->preview_path, $this->balise($html, 'og:image'));
    }

    public function test_une_image_deja_petite_n_est_pas_agrandie(): void
    {
        $this->exigerGd();

        Storage::fake('photos');

        $petite = imagecreatetruecolor(300, 200);
        imagefill($petite, 0, 0, imagecolorallocate($petite, 10, 120, 200));
        ob_start();
        imagejpeg($petite, null, 90);
        $octets = (string) ob_get_clean();
        imagedestroy($petite);

        Storage::disk('photos')->put('parts/petite.jpg', $octets);

        $apercu = VignetteApercu::generer('photos', 'parts/petite.jpg');

        // Etirer une petite photo a 1200 de large ne lui ajoute aucun detail et
        // multiplie son poids.
        $this->assertSame(300, $apercu['largeur']);
        $this->assertSame(200, $apercu['hauteur']);
    }

    public function test_le_depot_aboutit_meme_quand_la_variante_ne_tient_pas_en_memoire(): void
    {
        // LE cas qui peut tuer un televersement. GD decompresse a quatre octets
        // par pixel, et un depassement de memoire en PHP n'est pas une
        // exception : il ne se rattrape pas, la requete meurt, et l'utilisateur
        // voit un echec sans explication sur le geste central de l'application.
        //
        // Mesure du pic de generer(), relevee sous memory_limit=128M. Ces pics
        // ne dependent PAS de la limite — ils ne dependent que des trois termes
        // de octetsRequis —, et l'image pose 192M depuis
        // docker/php/depot-de-photos.ini : ce que la limite change, c'est le
        // verdict du garde-fou, pas le besoin. Le poids d'entree est cite parce
        // qu'il est l'un de ces trois termes : un pic sans lui ne se compare a
        // rien.
        //
        //      3,00 Mpx, entree  1 697 851 o -> variante,    pic  46,0 Mo
        //     12,19 Mpx, entree  6 901 583 o -> variante,    pic  88,0 Mo
        //     16,00 Mpx, entree  9 057 386 o -> variante,    pic 102,0 Mo
        //     20,00 Mpx, entree 11 325 171 o -> renoncement, pic  38,0 Mo
        //     27,00 Mpx, entree 15 284 506 o -> renoncement, pic  42,0 Mo
        //
        // Ce test tient la seule propriete qui compte pour l'utilisateur : la
        // PHOTO EST ENREGISTREE quoi qu'il arrive. Une photo refusee a l'envoi
        // serait un defaut pire que l'absence de vignette.
        $this->exigerGd();

        Storage::fake('public');
        config(['media.disk' => 'public']);

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $piece = $this->piece();

        $octets = $this->jpegDePhoto(2400, 1600);

        $this->assertLessThan(8192 * 1024, strlen($octets), 'L\'entree du test depasse MAX_SIZE.');

        // La memoire est RESSERREE pour la duree du depot, plutot que l'image
        // grossie jusqu'au plafond. Les deux mettent le garde-fou a l'epreuve ;
        // seul celui-ci ne demande pas d'allouer 80 Mo dans un processus
        // PHPUnit qui en porte deja une bonne part — c'est-a-dire de risquer
        // dans le test la panne meme que le test surveille.
        $limiteOrigine = (string) ini_get('memory_limit');
        $etroite       = memory_get_usage(true) + 16 * 1024 * 1024;

        // Le refus est etabli AVANT le depot, par le calcul : si cette
        // assertion tombe, le test ne prouverait plus rien de la suite.
        $this->assertFalse(VignetteApercu::tientDansLaMemoire(
            VignetteApercu::octetsRequis(2400, 1600, 945, 630, strlen($octets)),
            (int) (16 * 1024 * 1024 * 0.8),
        ));

        ini_set('memory_limit', (string) $etroite);

        try {
            $this->postJson("/api/parts/{$piece->id}/media", [
                'photos' => [UploadedFile::fake()->createWithContent('enorme.jpg', $octets)],
            ])->assertCreated();
        } finally {
            ini_set('memory_limit', $limiteOrigine);
        }

        $media = Media::firstOrFail();

        // L'original est la, servi tel quel.
        Storage::disk('public')->assertExists($media->path);
        $this->assertNull($media->preview_path, 'Une variante a ete produite sans la memoire pour la tenir.');

        // Et la page de partage sert l'original plutot que rien : un apercu
        // mediocre vaut mieux qu'un apercu muet.
        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString($media->path, $this->balise($html, 'og:image'));
    }

    public function test_la_generation_renonce_quand_la_memoire_restante_ne_suffit_pas(): void
    {
        // Le garde-fou calcule, verifie sans fabriquer d'image — c'est tout
        // l'interet de l'avoir isole : mesurer le refus par l'exemple
        // demanderait d'approcher la limite, donc de risquer dans le test la
        // panne que le test surveille.
        //
        // Les quatre octets par pixel de GD, deux images a la fois, plus le
        // JPEG d'entree. Verification du modele contre la mesure du pic reel :
        // 65,2 Mo calcules contre 66,0 Mo mesures a 16 Mpx.
        $besoin = VignetteApercu::octetsRequis(4000, 4000, 630, 630, 2_739_771);

        $this->assertGreaterThan(60 * 1024 * 1024, $besoin);
        $this->assertLessThan(70 * 1024 * 1024, $besoin);

        // Ce qui reste d'une limite de 128 Mo quand 80 Mo sont deja pris : la
        // generation DOIT renoncer, et rendre la main au lieu de tuer la
        // requete.
        $this->assertFalse(VignetteApercu::tientDansLaMemoire($besoin, 38 * 1024 * 1024));

        // Et accepter quand la place est la.
        $this->assertTrue(VignetteApercu::tientDansLaMemoire($besoin, 90 * 1024 * 1024));

        // `-1` est l'absence de limite (memory_limit=-1) : il n'y a alors rien
        // a verifier, et le plafond de pixels reste seul a borner le travail.
        $this->assertTrue(VignetteApercu::tientDansLaMemoire($besoin, -1));
    }

    public function test_le_rattrapage_avance_malgre_une_ligne_irreparable(): void
    {
        // Mesure du defaut, trois demarrages d'affilee avec deux lignes
        // irreparables sur les plus petits identifiants :
        //
        //     demarrage 1 -> « 0 variante(s) generee(s) », 0 ligne sur 5
        //     demarrage 2 -> « 0 variante(s) generee(s) », 0 ligne sur 5
        //     demarrage 3 -> « 0 variante(s) generee(s) », 0 ligne sur 5
        //
        // En ligne, cela donne : aucune vignette, jamais, et un journal de
        // deploiement qui se lit comme un deploiement sain. Ce test echoue si
        // le marquage des echecs ou le tri par nombre d'essais disparait.
        $this->exigerGd();

        Storage::fake('photos');

        $piece = $this->piece();

        // Les deux premieres lignes n'ont pas de fichier derriere : aucune
        // execution ne saura jamais les traiter. C'est l'etat que render.yaml
        // documente — le disque de Render est ephemere, et des lignes `media`
        // ont survecu a leurs fichiers.
        $this->photoSur($piece, 'absente-1.jpg', disque: 'photos');
        $this->photoSur($piece, 'absente-2.jpg', disque: 'photos');

        $petite = $this->jpegDePhoto(600, 400);

        foreach (['saine-1.jpg', 'saine-2.jpg'] as $nom) {
            Storage::disk('photos')->put($nom, $petite);
            $this->photoSur($piece, $nom, disque: 'photos');
        }

        // --limit=2, soit exactement de quoi se faire bloquer par les deux
        // lignes irreparables si rien n'est marque.
        $this->artisan('media:apercus', ['--limit' => 2, '--disk' => 'photos'])
            ->assertExitCode(1);   // rien n'a pu etre fait : ce n'est pas « rien a faire »

        $this->assertSame(0, Media::whereNotNull('preview_path')->count());
        $this->assertSame(2, Media::where('preview_attempts', '>', 0)->count());

        // LE DEMARRAGE SUIVANT AVANCE. C'est toute la correction.
        $this->artisan('media:apercus', ['--limit' => 2, '--disk' => 'photos'])
            ->assertExitCode(0);

        $this->assertSame(
            2,
            Media::whereNotNull('preview_path')->count(),
            'Le rattrapage n\'a pas avance : il a rejoue les memes echecs.',
        );
    }

    public function test_la_couverture_est_la_premiere_photo_exploitable(): void
    {
        // Deux corruptions mesurees, opposees et toutes deux fausses, sur une
        // galerie dont la premiere ligne est inexploitable et la suivante
        // valable :
        //
        //     path = ''  -> aucune balise og:image dans la page
        //     path = '0' -> og:image="http://localhost:8000/storage/0",
        //                   twitter:card="summary_large_image"
        //
        // Le premier perd la vignette qu'il y avait ; le second en annonce une
        // qui rend 404, en grand format, ce qui fait passer la page pour une
        // page en panne. `'0'` est la corruption que config/filesystems.php
        // documente comme ayant deja eu lieu : storeAs() rendait `false` sur
        // une ecriture refusee, et `false` s'ecrit `0` dans la colonne.
        foreach (['', '0'] as $cheminFautif) {
            $piece = $this->piece();

            $this->photoSur($piece, $cheminFautif);
            $this->photoSur($piece, 'parts/valable.jpg');

            $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

            $this->assertStringContainsString(
                'parts/valable.jpg',
                $this->balise($html, 'og:image'),
                "Couverture fautive avec path='{$cheminFautif}'.",
            );
        }
    }

    public function test_une_couverture_declaree_inexploitable_cede_la_place(): void
    {
        // Le drapeau `is_cover` ne suffit pas a faire une couverture : c'est
        // l'ordre des deux operations qui compte, filtrer PUIS choisir. Avec
        // `firstWhere('is_cover')` applique a la galerie entiere, une
        // couverture declaree mais vide gagnait contre une photo valable.
        $piece = $this->piece();

        $this->photoSur($piece, '', couverture: true);
        $this->photoSur($piece, 'parts/valable.jpg');

        $html = $this->get('/p/'.$piece->id)->assertOk()->getContent();

        $this->assertStringContainsString('parts/valable.jpg', $this->balise($html, 'og:image'));
    }

    /* ------------------------------------------------------------------ */
    /* Le prix                                                             */
    /* ------------------------------------------------------------------ */

    public function test_le_prix_est_formate_avec_l_espace_des_milliers(): void
    {
        $html = $this->get('/v/'.$this->vehicule(['sale_price' => 3_000])->id)
            ->assertOk()
            ->getContent();

        // Espaces insecables : sur un ecran de telephone, une coupure de ligne
        // transformerait « 3 000 FCFA » en un « 3 » seul suivi de « 000 FCFA ».
        $this->assertStringContainsString('3'."\u{A0}".'000'."\u{A0}".'FCFA', $html);

        // Le brut est interdit LA OU UN HUMAIN LIT : og:title et la ligne de
        // prix. Il est au contraire exige dans product:price:amount, qu'une
        // machine lit et pour qui « 3 000 » n'est pas un nombre — d'ou deux
        // assertions ciblees et non une recherche dans tout le document, qui se
        // contrediraient.
        foreach ([$this->balise($html, 'og:title'), $this->prixAffiche($html)] as $lu) {
            $this->assertStringNotContainsString('3000', $lu);
            $this->assertStringNotContainsString('3000.00', $lu);
        }

        $this->assertSame('3000', $this->balise($html, 'product:price:amount'));
    }

    public function test_un_prix_nul_est_traite_comme_absent(): void
    {
        // prixFormate() ne testait que null. Mesure : sale_price=0 rendait
        // og:title « Toyota Corolla 2015 — 0 FCFA », c'est-a-dire une voiture
        // annoncee gratuite dans la conversation. Un zero en base ne veut pas
        // dire « gratuit », il veut dire « pas renseigne » : le formulaire met 0
        // par defaut dans un champ numerique vide.
        $html = $this->get('/v/'.$this->vehicule(['sale_price' => 0])->id)
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="Toyota Corolla 2015 — Prix sur demande">',
            $html,
        );
        $this->assertStringNotContainsString('0'."\u{A0}".'FCFA', $html);

        // Et product:price:amount ne s'invente pas un montant : annoncer
        // « 0 » a un lecteur de fiche produit est la meme erreur, en machine.
        $this->assertStringNotContainsString('product:price', $html);
    }

    public function test_un_prix_qui_s_arrondit_a_zero_est_traite_comme_absent(): void
    {
        // Un `<= 0` sur la valeur brute ne suffisait pas : 0,4 est strictement
        // positif, et number_format le rend « 0 FCFA » — l'affichage meme que
        // le correctif precedent supprime, reintroduit par l'arrondi. Mesure du
        // formatage avant ce garde-fou : prix=0.4 -> « 0 FCFA ».
        //
        // La regle tenue est celle qui se verifie a l'ecran : la page n'ecrit
        // jamais « 0 FCFA ».
        $html = $this->get('/v/'.$this->vehicule(['sale_price' => 0.4])->id)
            ->assertOk()
            ->getContent();

        $this->assertSame('Prix sur demande', $this->prixAffiche($html));
        $this->assertStringNotContainsString('0'."\u{A0}".'FCFA', $html);

        // La balise produit suit le MEME critere : sans quoi la machine lisait
        // « 0 » pendant que l'humain lisait « Prix sur demande ».
        $this->assertStringNotContainsString('product:price', $html);
    }

    public function test_le_tarif_de_location_porte_le_titre_faute_de_prix_de_vente(): void
    {
        // Mesure : availability=rent avec rental_daily_rate=25000 rendait
        // « Toyota Corolla 2015 — Prix sur demande », et les 25 000 FCFA
        // n'apparaissaient NULLE PART dans la page. Le tarif n'est ni interne ni
        // confidentiel, et c'est la seule information qui decide le
        // destinataire.
        $location = $this->vehicule([
            'availability'      => 'rent',
            'sale_price'        => null,
            'rental_daily_rate' => 25_000,
        ]);

        $html = $this->get('/v/'.$location->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="Toyota Corolla 2015 — 25'."\u{A0}".'000'
            ."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'jour">',
            $html,
        );
        $this->assertStringNotContainsString('Prix sur demande', $html);

        // product:price:amount designe ce qu'on paie pour ACQUERIR l'article :
        // y mettre un tarif journalier annoncerait une Corolla a 25 000 FCFA.
        $this->assertStringNotContainsString('product:price', $html);
    }

    public function test_un_vehicule_a_vendre_garde_son_prix_de_vente_au_titre(): void
    {
        // Les deux tarifs coexistent sur un vehicule « both », et c'est la vente
        // qui doit porter le titre : c'est l'operation que la page cherche a
        // declencher.
        $html = $this->get('/v/'.$this->vehicule([
            'availability'      => 'both',
            'sale_price'        => 4_500_000,
            'rental_daily_rate' => 25_000,
        ])->id)->assertOk()->getContent();

        $this->assertStringContainsString(
            '<meta property="og:title" content="Toyota Corolla 2015 — 4'."\u{A0}".'500'."\u{A0}".'000'."\u{A0}".'FCFA">',
            $html,
        );
    }

    public function test_un_tarif_mensuel_seul_est_affiche_avec_son_unite(): void
    {
        $html = $this->get('/v/'.$this->vehicule([
            'availability' => 'rent',
            'sale_price' => null,
            'rental_daily_rate' => null,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $tarif = '600'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'mois';
        $this->assertSame($tarif, $this->prixAffiche($html));
        $this->assertSame('Toyota Corolla 2015 — '.$tarif, $this->balise($html, 'og:title'));
        $this->assertStringNotContainsString('Prix sur demande', $html);
        $this->assertStringNotContainsString('product:price', $html);
        $this->assertStringNotContainsString('class="tarif-location"', $html, 'Ne pas repeter le seul tarif.');
    }

    public function test_les_tarifs_journalier_et_mensuel_sont_visibles_sans_confondre_leurs_periodes(): void
    {
        $html = $this->get('/v/'.$this->vehicule([
            'availability' => 'rent',
            'sale_price' => null,
            'rental_daily_rate' => 25_000,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $journalier = '25'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'jour';
        $mensuel = '600'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'mois';
        $this->assertSame($journalier, $this->prixAffiche($html));
        $this->assertSame('Toyota Corolla 2015 — '.$journalier, $this->balise($html, 'og:title'));
        $this->assertStringContainsString('<p class="tarif-location">Location : '.$mensuel.'</p>', $html);
        $this->assertStringNotContainsString('product:price', $html);
    }

    public function test_un_prix_de_vente_residuel_ne_masque_pas_la_location_mensuelle(): void
    {
        $html = $this->get('/v/'.$this->vehicule([
            'availability' => 'rent',
            'sale_price' => 4_500_000,
            'rental_daily_rate' => null,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $mensuel = '600'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'mois';
        $this->assertSame($mensuel, $this->prixAffiche($html));
        $this->assertSame('Toyota Corolla 2015 — '.$mensuel, $this->balise($html, 'og:title'));
        $this->assertStringNotContainsString('product:price', $html);
        $this->assertStringNotContainsString('4'."\u{A0}".'500'."\u{A0}".'000', $html);
    }

    public function test_un_tarif_journalier_a_zero_ne_masque_pas_le_tarif_mensuel(): void
    {
        $html = $this->get('/v/'.$this->vehicule([
            'availability' => 'rent',
            'sale_price' => null,
            'rental_daily_rate' => 0,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $tarif = '600'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'mois';
        $this->assertSame($tarif, $this->prixAffiche($html));
        $this->assertStringNotContainsString('jour', $this->balise($html, 'og:title'));
    }

    public function test_une_offre_mixte_conserve_la_vente_et_montre_les_tarifs_de_location(): void
    {
        $html = $this->get('/v/'.$this->vehicule([
            'availability' => 'both',
            'sale_price' => 4_500_000,
            'rental_daily_rate' => 25_000,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $vente = '4'."\u{A0}".'500'."\u{A0}".'000'."\u{A0}".'FCFA';
        $this->assertSame($vente, $this->prixAffiche($html));
        $this->assertSame('Toyota Corolla 2015 — '.$vente, $this->balise($html, 'og:title'));
        $this->assertSame('4500000', $this->balise($html, 'product:price:amount'));

        foreach ([
            '25'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'jour',
            '600'."\u{A0}".'000'."\u{A0}".'FCFA'."\u{A0}".'/'."\u{A0}".'mois',
        ] as $tarif) {
            $this->assertStringContainsString('<p class="tarif-location">Location : '.$tarif.'</p>', $html);
        }
    }

    public function test_un_tarif_de_location_ferme_n_est_pas_annonce(): void
    {
        // Un vehicule « sale » peut porter un tarif residuel en base ; annoncer
        // une location que le parc refuse ferait ecrire un client pour un service
        // qu'on ne rend pas.
        $html = $this->get('/v/'.$this->vehicule([
            'availability'      => 'sale',
            'sale_price'        => null,
            'rental_daily_rate' => 25_000,
            'rental_monthly_rate' => 600_000,
        ])->id)->assertOk()->getContent();

        $this->assertSame(
            'Toyota Corolla 2015 — Prix sur demande',
            $this->balise($html, 'og:title'),
        );
        $this->assertSame('Prix sur demande', $this->prixAffiche($html));

        // Le montant ne doit apparaitre nulle part — ni en balise, ni dans le
        // corps. « jour », en revanche, se cherche dans la BALISE seule : le
        // lien WhatsApp pre-rempli commence par « Bonjour ».
        $this->assertStringNotContainsString('25'."\u{A0}".'000', $html);
        $this->assertStringNotContainsString('600'."\u{A0}".'000', $html);
        $this->assertStringNotContainsString('jour', $this->balise($html, 'og:title'));
        $this->assertStringNotContainsString('mois', $this->balise($html, 'og:title'));
    }

    public function test_le_prix_est_declare_en_balises_produit(): void
    {
        // og:type vaut « product » sans que product:price:amount ni
        // product:price:currency ne soient rendus, alors que le prix est deja
        // calcule : la page se declarait fiche produit sans en porter le prix.
        $html = $this->get('/v/'.$this->vehicule()->id)->assertOk()->getContent();

        // Valeur BRUTE : sans espace insecable ni separateur de milliers, car
        // elle est lue par une machine pour qui « 4 500 000 » n'est pas un
        // nombre.
        $this->assertStringContainsString(
            '<meta property="product:price:amount" content="4500000">',
            $html,
        );
        $this->assertStringContainsString(
            '<meta property="product:price:currency" content="XOF">',
            $html,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Le reste de la page                                                 */
    /* ------------------------------------------------------------------ */

    public function test_le_lien_whatsapp_porte_le_numero_de_la_configuration(): void
    {
        // Le numero vient de config/boutique.php, pilote par l'environnement :
        // on le change dans le tableau de bord de Render, pas dans le code.
        config(['boutique.whatsapp' => '22611223344', 'boutique.telephone' => '+22611223344']);

        $html = $this->get('/p/'.$this->piece()->id)->assertOk()->getContent();

        $this->assertStringContainsString('https://wa.me/22611223344?text=', $html);
        $this->assertStringContainsString('href="tel:+22611223344"', $html);

        // Le message est pre-rempli du nom de l'article : sans cela le vendeur
        // recoit « bonjour » et doit demander de quoi on parle.
        $this->assertStringContainsString(rawurlencode('Filtre à huile'), $html);
    }

    public function test_la_page_ne_contient_aucune_balise_script(): void
    {
        // Le robot n'execute rien, et la page s'ouvre sur un reseau mobile
        // faible : un script n'ajouterait que du poids et un risque de page
        // blanche. S'il en apparait un, c'est que l'apercu depend desormais de
        // quelque chose que le robot ne lira jamais.
        foreach ([
            '/v/'.$this->vehicule()->id,
            '/p/'.$this->piece()->id,
            '/a/'.$this->accessoire()->id,
        ] as $adresse) {
            $html = $this->get($adresse)->assertOk()->getContent();

            $this->assertFalse(
                stripos($html, '<script') !== false,
                "La page {$adresse} porte une balise script.",
            );
        }
    }

    public function test_une_en_tete_de_cache_courte_accompagne_la_page(): void
    {
        // Le robot demande la page, puis l'humain qui a recu le lien la demande
        // a son tour quelques secondes plus tard — et l'instance gratuite de
        // Render est lente a se reveiller. Courte neanmoins : un prix corrige
        // ne doit pas rester faux dans les apercus, qui se recopient.
        $entete = $this->get('/v/'.$this->vehicule()->id)
            ->assertOk()
            ->headers->get('Cache-Control');

        $this->assertStringContainsString('max-age=300', $entete);
        $this->assertStringContainsString('public', $entete);
    }

    public function test_un_plafond_de_debit_protege_les_trois_routes(): void
    {
        // Le groupe 'api' porte throttle:api ; le groupe 'web' n'en avait aucun,
        // et ce sont les premiers points d'entree publics qui interrogent la
        // base hors de l'API. Les identifiants sont sequentiels donc
        // enumerables : mesure avec CACHE_STORE=array, /v fait 7 requetes SQL —
        // une boucle de /v/1 a /v/20000 represente 140 000 requetes SQL sur
        // l'instance gratuite.
        //
        // Ce test-ci verifie la PRESENCE et la hauteur du plafond annonce aux
        // clients. Son DECLENCHEMENT, et surtout le fait qu'on ne puisse plus
        // s'en echapper par un en-tete, sont l'affaire du test suivant.
        //
        // La hauteur est lue depuis la constante et non recopiee : les deux
        // limites superposees valent le meme chiffre, et un test qui ecrirait
        // « 300 » cesserait de prouver quoi que ce soit le jour ou il change.
        foreach ([
            '/v/'.$this->vehicule()->id,
            '/p/'.$this->piece()->id,
            '/a/'.$this->accessoire()->id,
        ] as $adresse) {
            $reponse = $this->get($adresse)->assertOk();

            $this->assertSame(
                (string) AppServiceProvider::PARTAGE_PAR_MINUTE,
                $reponse->headers->get('X-RateLimit-Limit'),
                "La page {$adresse} n'est pas plafonnee.",
            );
        }
    }

    public function test_le_plafond_ne_s_echappe_pas_par_un_en_tete(): void
    {
        // LE DEFAUT MESURE. `by($request->ip())` lit l'adresse que l'appelant
        // ANNONCE, puisque bootstrap/app.php pose trustProxies(at: '*'). Avec
        // un X-Forwarded-For different a chaque requete :
        //
        //     315 requetes -> HTTP 200 partout, X-RateLimit-Remaining : 299
        //
        // Remaining ne descendait jamais. Le plafond ne plafonnait que les
        // clients honnetes.
        //
        // On enumere pour de vrai, et sur un identifiant INEXISTANT : c'est
        // exactement le trafic contre lequel le plafond existe, et un 404
        // n'appelle aucun rendu de vue — la boucle reste donc dans la seconde
        // alors qu'elle fait 300 requetes. Le comptage a lieu dans
        // ThrottleRequests, avant le controleur : un 404 compte comme un 200.
        $plafond = AppServiceProvider::PARTAGE_PAR_MINUTE;

        // D'abord le contraire, pour que ce test ne puisse pas passer en
        // refusant tout : sous le plafond, la page repond.
        $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '197.158.77.4'])
            ->get('/p/'.$this->piece()->id)
            ->assertOk();

        for ($i = 1; $i < $plafond; $i++) {
            $statut = $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '41.202.'.intdiv($i, 256).'.'.($i % 256)])
                ->get('/v/999999')
                ->getStatusCode();

            $this->assertSame(404, $statut, "requete {$i} : le plafond s'est declenche trop tot.");
        }

        // Une adresse encore jamais vue, et un article qui EXISTE : refuse
        // quand meme. C'est le seau global, celui dont aucun en-tete ne sort.
        $vehicule = $this->vehicule();

        $this->withServerVariables(['HTTP_X_FORWARDED_FOR' => '197.158.77.3'])
            ->get('/v/'.$vehicule->id)
            ->assertStatus(429);
    }

    /* ------------------------------------------------------------------ */
    /* La frontiere                                                        */
    /* ------------------------------------------------------------------ */

    public function test_aucune_donnee_interne_ne_fuit_dans_la_page(): void
    {
        $vehicule   = $this->vehicule();
        $piece      = $this->piece();
        $accessoire = $this->accessoire();

        $this->photoSur($piece, 'parts/filtre-1.jpg', couverture: true);

        $pages = [
            '/v/'.$vehicule->id   => [self::PRIX_ACHAT_VEHICULE, 4_500_000 - self::PRIX_ACHAT_VEHICULE],
            '/p/'.$piece->id      => [self::PRIX_ACHAT_PIECE, 3_000 - self::PRIX_ACHAT_PIECE],
            '/a/'.$accessoire->id => [self::PRIX_ACHAT_ACCESSOIRE, 12_500 - self::PRIX_ACHAT_ACCESSOIRE],
        ];

        $references = [$vehicule->reference, $piece->sku, $accessoire->sku];

        foreach ($pages as $adresse => $nombres) {
            $html = $this->get($adresse)->assertOk()->getContent();

            // Prix d'achat et marge : on cherche les NOMBRES, sous toutes les
            // ecritures qu'une page peut en donner — brute, decimale, ou
            // groupee par milliers. Chercher « cost_price » ne prouverait rien :
            // la fuite n'a pas besoin du nom du champ pour avoir lieu.
            foreach ($nombres as $nombre) {
                foreach ($this->ecritures($nombre) as $ecriture) {
                    $this->assertStringNotContainsString(
                        $ecriture,
                        $html,
                        "{$adresse} laisse voir {$ecriture} — prix d'achat ou marge.",
                    );
                }
            }

            // Emplacement de stockage et fournisseur, cherches par leur valeur
            // pour la meme raison.
            $this->assertStringNotContainsString(self::STOCKAGE_PIECE, $html, "{$adresse} : emplacement de stockage.");
            $this->assertStringNotContainsString(self::STOCKAGE_ACCESSOIRE, $html, "{$adresse} : emplacement de stockage.");
            $this->assertStringNotContainsString(self::FOURNISSEUR, $html, "{$adresse} : fournisseur.");

            // Les COORDONNEES du fournisseur, et pas seulement sa raison
            // sociale. La garde restait verte sur « Ouedraogo +22670000001 » :
            // un nom de contact et un numero sont ce qu'un concurrent cherche
            // en premier, et un acheteur qui les lit s'approvisionne a la
            // source. Le numero sous ses deux ecritures — la colonne porte le
            // « + », un affichage peut le perdre.
            $this->assertStringNotContainsString(
                self::FOURNISSEUR_CONTACT,
                $html,
                "{$adresse} : nom du contact fournisseur.",
            );

            foreach ([self::FOURNISSEUR_TELEPHONE, ltrim(self::FOURNISSEUR_TELEPHONE, '+')] as $numero) {
                $this->assertStringNotContainsString(
                    $numero,
                    $html,
                    "{$adresse} laisse voir {$numero} — telephone du fournisseur.",
                );
            }

            // La QUANTITE en stock. Le seuil d'alerte etait surveille, pas
            // elle : la garde restait verte sur « Il reste 6 en reserve ». Un
            // acheteur qui lit le nombre d'unites sait s'il peut negocier ou
            // s'il doit se presser, et le vendeur perd cette asymetrie.
            foreach ([self::STOCK_PIECE, self::STOCK_ACCESSOIRE] as $quantite) {
                foreach ($this->ecritures($quantite) as $ecriture) {
                    $this->assertStringNotContainsString(
                        $ecriture,
                        $html,
                        "{$adresse} laisse voir {$ecriture} — quantite en stock.",
                    );
                }
            }

            // Les champs que la surveillance ne couvrait pas. Aucun ne sort
            // aujourd'hui ; le test est la pour qu'une precision « Plaque »
            // ajoutee demain sans y penser ne passe plus en silence.
            $this->assertStringNotContainsString(self::VIN, $html, "{$adresse} : numero de chassis.");
            $this->assertStringNotContainsString(self::PLAQUE, $html, "{$adresse} : plaque d'immatriculation.");
            $this->assertStringNotContainsString(self::CODE_MOTEUR, $html, "{$adresse} : code moteur.");
            $this->assertStringNotContainsString(self::NOTE_PIVOT, $html, "{$adresse} : note de pivot partenaire.");

            // Le seuil d'alerte de stock dit combien il reste avant rupture :
            // c'est une information de gestion, et un acheteur qui la lit sait
            // quand presser le vendeur.
            foreach ($this->ecritures(self::SEUIL_ALERTE) as $ecriture) {
                $this->assertStringNotContainsString(
                    $ecriture,
                    $html,
                    "{$adresse} laisse voir {$ecriture} — seuil d'alerte de stock.",
                );
            }

            // La reference interne de stock, desormais deterministe donc
            // verifiable — elle etait en uniqid() et ne pouvait pas etre
            // cherchee.
            foreach ($references as $reference) {
                $this->assertStringNotContainsString(
                    $reference,
                    $html,
                    "{$adresse} laisse voir {$reference} — reference interne.",
                );
            }
        }
    }

    /**
     * Les ecritures possibles d'un nombre dans une page.
     *
     * Un montant peut sortir brut (`1793`), tel que la colonne decimale le
     * rend (`1793.00`), ou mis en forme a la francaise avec l'une ou l'autre
     * des trois separations de milliers qu'on rencontre. Un seul de ces
     * formats dans le HTML est une fuite.
     *
     * @return array<int, string>
     */
    private function ecritures(int|float $nombre): array
    {
        return array_unique([
            (string) (int) $nombre,
            number_format((float) $nombre, 2, '.', ''),
            number_format((float) $nombre, 0, ',', ' '),
            number_format((float) $nombre, 0, ',', "\u{A0}"),
            number_format((float) $nombre, 0, ',', '.'),
        ]);
    }
}
