<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\OemNumberFitment;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\VersionPartage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le marqueur de version dans l'adresse de partage.
 *
 * LE DEFAUT MESURE, rapporte par le proprietaire : « un vehicule deja partage
 * ne met pas a jour ses donnees de partage apres maj ». Le serveur n'y est pour
 * rien, et c'est verifie — /v/86 annonce « Mazda CX-9 2018 — 9 500 000 FCFA »,
 * l'API rend sale_price 9500000.00, les deux concordent. Ce qui ne bouge pas,
 * c'est la vignette DEJA CONSTRUITE chez WhatsApp et chez Facebook : ils la
 * gardent telle qu'ils l'ont fabriquee au premier partage, et aucun en-tete
 * HTTP ne les en fait changer. La seule prise qu'on ait sur eux est l'adresse.
 *
 * D'ou le contrat que ce fichier mesure, et rien d'autre :
 *
 *     forme nue        https://autoparc-backend.onrender.com/v/86
 *     forme marquee    https://autoparc-backend.onrender.com/v/86?m=t3uwow
 *
 * Six proprietes, chacune avec son test, parce que chacune peut se perdre
 * seule et SANS BRUIT — une balise fausse ne leve rien, elle fait seulement
 * circuler un apercu perime, et personne ne le remarque avant qu'un client
 * n'ecrive pour un prix qui n'existe plus :
 *
 *   1. le marqueur change quand `updated_at` change ;
 *   2. il ne change pas sinon — a toute heure, sur toute instance ;
 *   3. og:url le porte, TOUJOURS a sa valeur courante ;
 *   4. <link rel="canonical"> ne le porte PAS ;
 *   5. `?m=` n'influence ni le code HTTP ni le contenu rendu ;
 *   6. l'API rend `share_version` sur les TROIS ressources et sur TOUS leurs
 *      chemins — c'est par lui que l'application compose la meme adresse que
 *      celle que le serveur annonce.
 *
 * Les trois types d'article sont couverts partout ou la propriete les concerne
 * tous les trois : le gabarit est commun, mais c'est justement ce qui fait
 * qu'une regression y toucherait les trois d'un coup sans qu'un test portant
 * sur le seul vehicule ne rougisse.
 */
class VersionDePartageTest extends TestCase
{
    use RefreshDatabase;

    private static int $compteur = 0;

    protected function setUp(): void
    {
        parent::setUp();

        self::$compteur = 0;

        // L'adresse des pages vient de la CONFIGURATION et non de la requete
        // (voir ArticleController::adresse). La poser ici rend og:url, le
        // canonique et le lien WhatsApp verifiables au caractere.
        config(['app.url' => 'https://partage-essai.example']);
    }

    /* ------------------------------------------------------------------ */
    /* Fabrique                                                           */
    /* ------------------------------------------------------------------ */

    private function reference(string $prefixe): string
    {
        return $prefixe.'-VERS-'.str_pad((string) ++self::$compteur, 4, '0', STR_PAD_LEFT);
    }

    private function modele(): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'mazda'],
            ['name' => 'Mazda', 'is_active' => true],
        );

        return VehicleModel::firstOrCreate(
            ['slug' => 'cx-9'],
            ['brand_id' => $marque->id, 'name' => 'CX-9', 'is_active' => true],
        );
    }

    /**
     * La Mazda CX-9 du cas rapporte, aux valeurs du cas.
     *
     * C'est la seule annonce du parc en production, celle dont le proprietaire
     * a signale que le partage restait perime. Reprendre ses valeurs plutot que
     * d'en inventer garde ce fichier lisible a cote du rapport qui l'a motive.
     */
    private function vehicule(array $attributs = []): Vehicle
    {
        $modele = $this->modele();

        return Vehicle::create(array_merge([
            'reference'           => $this->reference('VH'),
            'registration_status' => 'unregistered',
            'brand_id'            => $modele->brand_id,
            'vehicle_model_id'    => $modele->id,
            // Contraint et sans defaut : la colonne est NOT NULL.
            'engine_type_id'      => EngineType::firstOrCreate(
                ['code' => 'petrol'],
                ['label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
            )->id,
            'manufacturing_year'  => 2018,
            'condition'           => 'used',
            'status'              => 'in_stock',
            'availability'        => 'sale',
            'sale_price'          => 9_500_000,
            'currency'            => 'XOF',
        ], $attributs));
    }

    private function piece(array $attributs = []): Part
    {
        return Part::create(array_merge([
            'sku'              => $this->reference('PRT'),
            'name'             => 'Filtre à huile',
            'part_category_id' => PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres'])->id,
            'manufacturer_id'  => Manufacturer::firstOrCreate(['slug' => 'bosch'], ['name' => 'Bosch'])->id,
            'type'             => 'aftermarket',
            'condition'        => 'new',
            'selling_price'    => 3_000,
            'currency'         => 'XOF',
            'vat_rate'         => 18.00,
            'stock_quantity'   => 12,
            'is_active'        => true,
            'is_available'     => true,
        ], $attributs));
    }

    private function accessoire(array $attributs = []): Accessory
    {
        return Accessory::create(array_merge([
            'sku'             => $this->reference('ACC'),
            'name'            => 'Caméra de recul',
            'category'        => 'securite',
            'manufacturer_id' => Manufacturer::firstOrCreate(['slug' => 'bosch'], ['name' => 'Bosch'])->id,
            'selling_price'   => 12_500,
            'currency'        => 'XOF',
            'vat_rate'        => 18.00,
            'stock_quantity'  => 4,
            'is_active'       => true,
            'is_available'    => true,
        ], $attributs));
    }

    /* ------------------------------------------------------------------ */
    /* Lecture des balises                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Le contenu d'og:url, isole du reste de la page.
     *
     * Chercher la chaine dans le document entier rendrait les assertions
     * fausses pour de mauvaises raisons : le canonique porte la meme adresse a
     * un parametre pres, et le lien WhatsApp la porte une troisieme fois, sous
     * forme encodee.
     */
    private function ogUrl(string $html): string
    {
        preg_match('/<meta property="og:url" content="([^"]*)">/', $html, $trouve);

        return $trouve[1] ?? '';
    }

    private function canonique(string $html): string
    {
        preg_match('/<link rel="canonical" href="([^"]*)">/', $html, $trouve);

        return $trouve[1] ?? '';
    }

    /** Le marqueur annonce par la page, ou '' quand og:url est nue. */
    private function marqueurDeLaPage(string $adresse): string
    {
        $url = $this->ogUrl($this->get($adresse)->assertOk()->getContent());

        preg_match('/\?m=(.*)$/', $url, $trouve);

        return $trouve[1] ?? '';
    }

    /* ------------------------------------------------------------------ */
    /* 1. Le marqueur suit `updated_at`                                    */
    /* ------------------------------------------------------------------ */

    public function test_une_modification_de_l_article_change_le_marqueur(): void
    {
        $vehicule = $this->vehicule(['sale_price' => 10_500_000]);

        $avant = $this->marqueurDeLaPage('/v/'.$vehicule->id);

        // L'horloge avance avant l'ecriture, et c'est necessaire : `updated_at`
        // est stocke a la SECONDE sur les trois pilotes du projet (mesure,
        // dateFormat d'Eloquent : sqlite, pgsql et mysql rendent tous les trois
        // « Y-m-d H:i:s »). Deux ecritures dans la meme seconde porteraient le
        // meme instant, donc le meme marqueur — propriete du stockage, pas du
        // marqueur, et dite comme telle dans VersionPartage.
        $this->travel(90)->seconds();

        // La correction de prix du cas rapporte : 10 500 000 ramenes a
        // 9 500 000. C'est exactement le geste apres lequel l'apercu restait
        // perime.
        $vehicule->update(['sale_price' => 9_500_000]);

        $apres = $this->marqueurDeLaPage('/v/'.$vehicule->id);

        $this->assertNotSame(
            $avant,
            $apres,
            'Un prix corrige doit produire une adresse de partage differente, '
            .'sans quoi WhatsApp ressort la vignette du premier partage.',
        );

        // Et il porte l'instant de la modification, pas autre chose : le
        // marqueur est REVERSIBLE, et c'est ce qui permet de dire en production
        // de quelle version un lien encore en circulation a ete fabrique.
        $this->assertSame(
            (string) $vehicule->refresh()->updated_at->getTimestamp(),
            base_convert($apres, 36, 10),
        );
    }

    public function test_le_marqueur_est_stable_a_contenu_inchange(): void
    {
        $piece = $this->piece();

        $premier = $this->marqueurDeLaPage('/p/'.$piece->id);

        // Trois jours plus tard, et sans aucune ecriture. Un marqueur qui
        // bougerait ici ferait re-cler tous les articles de la boutique a
        // chaque consultation, c'est-a-dire autant de vignettes a reconstruire
        // sur l'instance gratuite de Render pour rien. C'est ce qu'un aleatoire
        // ou une horloge courante auraient produit.
        $this->travel(3)->days();

        $this->assertSame($premier, $this->marqueurDeLaPage('/p/'.$piece->id));
        $this->assertNotSame('', $premier);
    }

    public function test_le_marqueur_tient_dans_une_chaine_de_requete_sans_echappement(): void
    {
        foreach ([
            '/v/'.$this->vehicule()->id,
            '/p/'.$this->piece()->id,
            '/a/'.$this->accessoire()->id,
        ] as $adresse) {
            $marqueur = $this->marqueurDeLaPage($adresse);

            // L'alphabet de la base 36 : rien a percent-encoder, pas de « + »
            // ni de « / » a confondre. Un marqueur qui exigerait un
            // echappement se ferait recopier de travers par le premier outil
            // qui recompose l'adresse — et le lien WhatsApp la recompose.
            $this->assertMatchesRegularExpression('/^[0-9a-z]+$/', $marqueur);
            $this->assertSame($marqueur, rawurlencode($marqueur));

            // Six caracteres aujourd'hui, sept a partir du 2038-12-24
            // (36^6 = 2 176 782 336). La borne est verifiee plutot que la
            // longueur exacte : un test qui exigerait « 6 » rougirait en 2039
            // pour une raison qui n'est pas un defaut.
            $this->assertLessThanOrEqual(8, strlen($marqueur));
        }
    }

    /* ------------------------------------------------------------------ */
    /* 2. og:url le porte, le canonique non                                */
    /* ------------------------------------------------------------------ */

    public function test_og_url_porte_le_marqueur_et_le_canonique_reste_nu(): void
    {
        // Les trois types, parce que le gabarit est commun : une regression y
        // toucherait les trois d'un coup, et un test portant sur le seul
        // vehicule ne la verrait pas plus que le code.
        foreach ([
            '/v/' => $this->vehicule(),
            '/p/' => $this->piece(),
            '/a/' => $this->accessoire(),
        ] as $prefixe => $article) {
            $adresse = $prefixe.$article->id;
            $nue     = 'https://partage-essai.example'.$adresse;
            $attendu = $nue.'?m='.VersionPartage::pour($article->updated_at);

            $html = $this->get($adresse)->assertOk()->getContent();

            // Facebook identifie un objet par og:url : c'est cette balise qui
            // decide s'il relit la page ou s'il ressort l'apercu deja construit.
            $this->assertSame($attendu, $this->ogUrl($html), "og:url de {$adresse}");

            // Le canonique, lui, reste NU : un moteur de recherche doit voir
            // UNE page par article, pas une par modification. Dix retouches
            // feraient autrement dix adresses indexables du meme contenu.
            $this->assertSame($nue, $this->canonique($html), "canonical de {$adresse}");
            $this->assertStringNotContainsString(
                '?m=',
                $this->canonique($html),
                'Le canonique porte le marqueur : les moteurs verraient une page par modification.',
            );
        }
    }

    public function test_le_message_whatsapp_de_la_page_porte_l_adresse_marquee(): void
    {
        // Ce message part de la page VERS la boutique, et WhatsApp cle la
        // vignette qu'il affiche sur l'adresse qu'il a APPELEE — la raison meme
        // pour laquelle l'application doit partager une adresse qui change. Un
        // lien nu ferait voir au vendeur la vignette d'une version precedente
        // de son propre article.
        $vehicule = $this->vehicule();

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();

        $marquee = 'https://partage-essai.example/v/'.$vehicule->id
            .'?m='.VersionPartage::pour($vehicule->updated_at);

        // Encodee : wa.me recoit le texte dans une chaine de requete, donc
        // « ? » devient « %3F » et « = » devient « %3D ».
        $this->assertStringContainsString(rawurlencode($marquee), $html);
    }

    /* ------------------------------------------------------------------ */
    /* 3. Le parametre recu est accepte, et inerte                         */
    /* ------------------------------------------------------------------ */

    public function test_og_url_annonce_le_marqueur_courant_et_non_celui_recu(): void
    {
        // LE POINT LE PLUS SUBTIL DU CONTRAT. Un vieux lien continue de
        // circuler apres une modification : visite, il doit annoncer le
        // marqueur NEUF. C'est ce qui fait re-cler l'objet chez Facebook au
        // lieu de figer l'ancien — si og:url renvoyait le marqueur recu, le
        // lien se confirmerait lui-meme et la vignette perimee serait
        // definitive.
        $vehicule = $this->vehicule(['sale_price' => 10_500_000]);

        $ancien = VersionPartage::pour($vehicule->updated_at);

        $this->travel(90)->seconds();
        $vehicule->update(['sale_price' => 9_500_000]);

        $courant = VersionPartage::pour($vehicule->refresh()->updated_at);
        $this->assertNotSame($ancien, $courant);

        // On visite l'ANCIEN lien, celui qui est dans les conversations.
        $html = $this->get('/v/'.$vehicule->id.'?m='.$ancien)->assertOk()->getContent();

        $this->assertSame(
            'https://partage-essai.example/v/'.$vehicule->id.'?m='.$courant,
            $this->ogUrl($html),
        );
        $this->assertStringNotContainsString(
            $ancien,
            $this->ogUrl($html),
            'og:url renvoie le marqueur recu : le lien perime se confirmerait lui-meme.',
        );
    }

    public function test_un_marqueur_quelconque_ne_change_ni_le_code_http_ni_le_contenu(): void
    {
        // `m` est ACCEPTE et n'est relu NULLE PART : ni pour le routage — la
        // liaison de modele se fait sur le segment de chemin — ni pour le
        // contenu. On le verifie par l'egalite STRICTE des deux pages, et non
        // par une absence de message d'erreur : c'est la seule formulation qui
        // interdise aussi qu'un parametre recu se glisse quelque part dans le
        // HTML.
        //
        // Les valeurs sont choisies hostiles : vide, avec des caracteres a
        // echapper, un chemin relatif, une balise, et une longueur qu'aucun
        // marqueur n'atteindra.
        $valeurs = [
            '',
            'nimporte quoi',
            't3uwow',
            '../../../etc/passwd',
            '<script>alert(1)</script>',
            '" onload="x',
            str_repeat('z', 2048),
        ];

        foreach ([
            '/v/'.$this->vehicule()->id,
            '/p/'.$this->piece()->id,
            '/a/'.$this->accessoire()->id,
        ] as $adresse) {
            $reference = $this->get($adresse)->assertOk()->getContent();

            foreach ($valeurs as $valeur) {
                $reponse = $this->get($adresse.'?m='.rawurlencode($valeur));

                $reponse->assertOk();

                $this->assertSame(
                    $reference,
                    $reponse->getContent(),
                    "La page {$adresse} change selon le marqueur recu.",
                );
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /* 4. `share_version` dans l'API, sur tous les chemins                 */
    /* ------------------------------------------------------------------ */

    /**
     * L'INVENTAIRE des chemins publics qui rendent une des trois ressources.
     *
     * Ce depot a livre CINQ fois le defaut `whenLoaded` qui fait DISPARAITRE
     * une cle du JSON selon le chemin emprunte — la liste montrait, la fiche
     * non, ou l'inverse — et toujours sans erreur ni trace. `share_version` ne
     * passe par aucune relation, mais la lecon tient quand meme : une cle se
     * verifie sur TOUS ses chemins, pas sur celui qu'on avait sous les yeux.
     *
     * Les chemins sont construits ici plutot que dans un fournisseur statique
     * parce qu'ils portent les identifiants des articles fabriques. Un chemin
     * public ajoute demain doit etre ajoute a cette liste ; c'est le seul
     * endroit qui dise lesquels sont couverts.
     */
    public function test_les_trois_ressources_rendent_share_version_sur_tous_les_chemins(): void
    {
        $vehicule   = $this->vehicule();
        $piece      = $this->piece();
        $accessoire = $this->accessoire();

        // Une compatibilite declaree pour que la recherche par criteres rende
        // la piece : c'est un chemin a part, servi par CompatibilityService et
        // non par PartController.
        $piece->fitments()->create(['vehicle_model_id' => $this->modele()->id]);

        // Et un numero OEM rattache au meme modele, pour le chemin qui rend des
        // VEHICULES depuis une reference saisie au comptoir.
        $oem = OemNumber::create([
            'number'            => '90915-YZZD4',
            'normalized_number' => OemNumber::normalize('90915-YZZD4'),
        ]);
        OemNumberFitment::create([
            'oem_number_id'    => $oem->id,
            'vehicle_model_id' => $this->modele()->id,
        ]);

        $chemins = [
            // Vehicule : liste, fiche, et la passerelle OEM -> vehicules.
            'liste des vehicules'   => ['/api/catalog/vehicles', 'data.0.share_version', $vehicule],
            'fiche vehicule'        => ['/api/catalog/vehicles/'.$vehicule->id, 'data.share_version', $vehicule],
            'vehicules d\'un OEM'   => ['/api/oem/90915-YZZD4/vehicles', 'data.0.share_version', $vehicule],

            // Piece : liste, fiche, et la recherche de compatibilite.
            'liste des pieces'      => ['/api/catalog/parts', 'data.0.share_version', $piece],
            'fiche piece'           => ['/api/catalog/parts/'.$piece->id, 'data.share_version', $piece],
            'pieces compatibles'    => [
                '/api/catalog/compatible-parts?vehicle_model_id='.$this->modele()->id,
                'data.0.share_version',
                $piece,
            ],

            // Accessoire : catalogue et fiche.
            'catalogue accessoires' => ['/api/catalog/accessories', 'data.0.share_version', $accessoire],
            'fiche accessoire'      => ['/api/catalog/accessories/'.$accessoire->id, 'data.share_version', $accessoire],
        ];

        foreach ($chemins as $nom => [$adresse, $cle, $article]) {
            $reponse = $this->getJson($adresse)->assertOk();

            // assertJsonPath et non une lecture de tableau : il rougit aussi
            // quand la cle est ABSENTE, qui est la forme exacte du defaut
            // `whenLoaded` qu'on surveille ici.
            $reponse->assertJsonPath($cle, VersionPartage::pour($article->updated_at), "chemin « {$nom} »");
        }
    }

    public function test_l_api_et_la_page_annoncent_le_meme_marqueur(): void
    {
        // LA PROPRIETE QUI FAIT TENIR LES DEUX COTES. L'application compose
        // l'adresse de partage depuis `share_version` ; le serveur annonce la
        // sienne dans og:url. Si les deux divergeaient, WhatsApp clerait sa
        // vignette sur une adresse et Facebook sur une autre, et le defaut
        // d'origine reviendrait pour l'un des deux canaux — en silence, chacun
        // des deux cotes etant juste de son point de vue.
        foreach ([
            ['/v/', '/api/catalog/vehicles/',   $this->vehicule()],
            ['/p/', '/api/catalog/parts/',      $this->piece()],
            ['/a/', '/api/catalog/accessories/', $this->accessoire()],
        ] as [$page, $api, $article]) {
            $this->assertSame(
                $this->getJson($api.$article->id)->assertOk()->json('data.share_version'),
                $this->marqueurDeLaPage($page.$article->id),
                "L'API et la page divergent pour {$page}{$article->id}.",
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* 5. Les cas ou il n'y a pas de marqueur                              */
    /* ------------------------------------------------------------------ */

    public function test_sans_updated_at_l_api_rend_nul_et_la_page_retombe_sur_l_adresse_nue(): void
    {
        $vehicule = $this->vehicule();

        // `timestamps()` rend la colonne NULLABLE : une ligne inseree en SQL
        // brut — reprise de donnees, correctif a la main sur Render — peut la
        // laisser vide. Le contrat l'admet, et l'application retombe alors sur
        // l'adresse nue, qui reste parfaitement valable : elle perd seulement
        // le rafraichissement de vignette.
        DB::table('vehicles')->where('id', $vehicule->id)->update(['updated_at' => null]);

        $this->getJson('/api/catalog/vehicles/'.$vehicule->id)
            ->assertOk()
            // La cle doit etre PRESENTE et nulle, jamais absente : c'est la
            // difference que les modeles Dart ne savent pas faire, et c'est par
            // la qu'arrive le defaut `whenLoaded` de ce depot.
            ->assertJsonPath('data.share_version', null);

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();
        $nue  = 'https://partage-essai.example/v/'.$vehicule->id;

        $this->assertSame($nue, $this->ogUrl($html));
        $this->assertSame($nue, $this->canonique($html));
    }

    public function test_un_instant_anterieur_a_l_epoque_ne_produit_pas_de_marqueur(): void
    {
        // base_convert ne prend pas de signe : un `updated_at` negatif aurait
        // rendu une chaine fausse mais d'apparence normale, et la monotonie
        // affirmee par VersionPartage aurait ete fausse sans que rien ne le
        // dise. Ce cas ne peut venir que d'une ecriture manuelle — Eloquent
        // pose now() — mais une affirmation qui depend d'un cas non traite n'en
        // est pas une.
        $piece = $this->piece();

        DB::table('parts')->where('id', $piece->id)->update(['updated_at' => '1969-07-20 20:17:40']);

        $this->getJson('/api/catalog/parts/'.$piece->id)
            ->assertOk()
            ->assertJsonPath('data.share_version', null);

        $this->assertSame('', $this->marqueurDeLaPage('/p/'.$piece->id));
    }
}
