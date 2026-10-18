<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * APP_URL presente et VIDE ne doit plus jamais produire une page a l'air normal.
 *
 * Le defaut n'etait pas l'oubli de la variable, c'etait son silence. render.yaml
 * declare la cle `sync: false` ; laissee blanche dans le tableau de bord, Render
 * la transmet DEFINIE ET VIDE — et le defaut du second argument d'env() ne
 * couvre que l'absence. Mesure, serveur demarre avec APP_URL= :
 *
 *     config('app.url') -> ''      (var_export : '')
 *     HTTP 200
 *     <meta property="og:url" content="/v/1">
 *     <link rel="canonical" href="/v/1">
 *     <meta property="og:image" content="/storage/vehicles/1/photo.jpg">
 *     lien WhatsApp = https://wa.me/226...?text=...%0A%2Fv%2F1
 *
 * Un og:image RELATIF, c'est zero vignette pour toujours, sur une page qui rend
 * 200 et n'ecrit rien dans aucun journal. Deux commentaires — celui de
 * render.yaml et celui d'ArticleController::adresse() — affirmaient que cet
 * oubli serait « bruyant, immediat et sans danger ». Ils affirmaient le
 * contraire de ce que le code faisait.
 *
 * Ce fichier fige les deux moities de la correction :
 *   — la valeur ne PEUT PLUS etre vide (le `?:` de config/app.php et de
 *     config/filesystems.php, qui sont deux endroits et non un) ;
 *   — et l'absence est BRUYANTE en production, le demarrage refusant une
 *     adresse vide, relative ou locale.
 *
 * Les deux comptent ensemble. Avec le seul repli, « http://localhost » en ligne
 * remplace une adresse relative par une adresse injoignable ; avec le seul
 * controle, la lecture de la configuration resterait vide partout ailleurs.
 */
class AppUrlObligatoireTest extends TestCase
{
    use RefreshDatabase;

    /**
     * La valeur d'origine du processus, remise en place apres chaque test.
     *
     * Ces tests posent APP_URL dans l'environnement du processus — c'est la
     * seule facon de mesurer ce que le FICHIER de configuration en fait, et
     * config() ne le dirait pas. Sans restauration, le test suivant heriterait
     * de la valeur vide.
     */
    private string|false $appUrlOriginal = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->appUrlOriginal = getenv('APP_URL');
    }

    protected function tearDown(): void
    {
        if ($this->appUrlOriginal === false) {
            putenv('APP_URL');
            unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
        } else {
            $this->poserAppUrl($this->appUrlOriginal);
        }

        parent::tearDown();
    }

    /** APP_URL telle que Render la transmet : par l'environnement du processus. */
    private function poserAppUrl(string $valeur): void
    {
        putenv('APP_URL='.$valeur);
        $_ENV['APP_URL']    = $valeur;
        $_SERVER['APP_URL'] = $valeur;
    }

    /** Le fichier de configuration relu, et non la valeur deja en memoire. */
    private function relireLaConfiguration(): void
    {
        $app = require config_path('app.php');
        $fs  = require config_path('filesystems.php');

        config([
            'app.url'                      => $app['url'],
            'filesystems.disks.public.url' => $fs['disks']['public']['url'],
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* La valeur ne peut plus etre vide                                    */
    /* ------------------------------------------------------------------ */

    public function test_une_variable_presente_et_vide_ne_laisse_pas_l_adresse_vide(): void
    {
        $this->poserAppUrl('');

        // La mesure du defaut, d'abord : env() rend bien la chaine vide, et son
        // second argument ne s'applique pas. C'est ce point precis qui rendait
        // les deux commentaires faux.
        $this->assertSame('', env('APP_URL'), 'La variable doit etre presente ET vide.');
        $this->assertSame(
            '',
            env('APP_URL', 'http://localhost'),
            'Si ce defaut s\'appliquait, le defaut d\'origine n\'aurait jamais eu de probleme.',
        );

        $this->relireLaConfiguration();

        $this->assertSame('http://localhost', config('app.url'));
    }

    public function test_le_disque_public_ne_sert_pas_une_url_relative(): void
    {
        // Deux endroits, et c'est le point de ce test : og:image ne vient pas de
        // app.url mais de l'URL du disque. Mesure avec APP_URL=, cette cle
        // valait « /storage » et la balise sortait en
        // « /storage/vehicles/1/photo.jpg ». Corriger config/app.php seul
        // laissait la vignette muette.
        $this->poserAppUrl('');
        $this->relireLaConfiguration();

        $this->assertSame('http://localhost/storage', config('filesystems.disks.public.url'));
    }

    public function test_une_page_de_partage_n_annonce_plus_d_adresse_relative(): void
    {
        $this->poserAppUrl('');
        $this->relireLaConfiguration();

        $vehicule = $this->vehiculeAvecPhoto();

        $html = $this->get('/v/'.$vehicule->id)->assertOk()->getContent();

        // Les trois valeurs mesurees relatives, reprises une a une. On exige
        // une adresse ABSOLUE, et non une adresse particuliere : c'est la
        // propriete dont depend la vignette, le robot ne resolvant aucun
        // chemin relatif.
        foreach ([
            'og:url'   => $this->contenu($html, 'og:url'),
            'og:image' => $this->contenu($html, 'og:image'),
        ] as $balise => $valeur) {
            $this->assertMatchesRegularExpression(
                '#^https?://#',
                $valeur,
                "{$balise} vaut « {$valeur} » : une adresse relative ne produit aucune vignette.",
            );
        }

        $this->assertStringNotContainsString(
            '<meta property="og:url" content="/v/',
            $html,
            'La mesure du defaut : og:url sortait en « /v/1 ».',
        );
        $this->assertStringNotContainsString(
            '<link rel="canonical" href="/v/',
            $html,
            'La mesure du defaut : le canonique sortait en « /v/1 ».',
        );
        $this->assertStringNotContainsString(
            'content="/storage/',
            $html,
            'La mesure du defaut : og:image sortait en « /storage/vehicles/1/photo.jpg ».',
        );

        // Le lien WhatsApp porte l'adresse encodee, ou « /v/1 » seul devenait
        // « %2Fv%2F1 ». C'est le texte que le destinataire renvoie : une
        // adresse relative y est inutilisable.
        $this->assertStringNotContainsString('%0A%2Fv%2F', $html);
    }

    /* ------------------------------------------------------------------ */
    /* Et l'absence est bruyante en production                             */
    /* ------------------------------------------------------------------ */

    /**
     * Les trois formes du meme oubli, et la valeur qui passe.
     *
     * « http://localhost » figure parmi les refus a dessein : c'est ce que le
     * repli de config/app.php produit desormais, et c'est exactement la valeur
     * qu'un oubli donne. Un controle qui ne chercherait que la chaine vide ne
     * se declencherait donc PLUS JAMAIS une fois le repli en place.
     *
     * @return array<string, array{string, bool}>
     */
    public static function adresses(): array
    {
        return [
            'vide'               => ['', false],
            'relative'           => ['/v/1', false],
            'sans schema'        => ['autoparc.onrender.com', false],
            'repli de config'    => ['http://localhost', false],
            'hote local en http' => ['http://127.0.0.1:8000', false],
            'URL Render'         => ['https://autoparc-backend.onrender.com', true],
        ];
    }

    #[DataProvider('adresses')]
    public function test_la_production_refuse_de_demarrer_sans_adresse_publique(
        string $url,
        bool $accepte,
    ): void {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.url' => $url]);

        if (! $accepte) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('APP_URL est obligatoire en production');
        }

        (new AppServiceProvider($this->app))->boot();

        if ($accepte) {
            // Sans assertion, PHPUnit compterait ce cas comme « risque » et le
            // signalerait plutot que de le verifier.
            $this->assertSame($url, config('app.url'));
        }
    }

    public function test_hors_production_le_demarrage_ne_juge_pas_l_adresse(): void
    {
        // Le controle ne doit casser ni les 464 tests (phpunit.xml pose
        // APP_ENV=testing) ni le poste de developpement, ou APP_URL peut
        // manquer sans consequence : aucune page de partage n'y est collee
        // dans une conversation.
        $this->assertSame('testing', $this->app->environment());

        config(['app.url' => '']);

        (new AppServiceProvider($this->app))->boot();

        $this->assertSame('', config('app.url'));
    }

    /* ------------------------------------------------------------------ */
    /* Fabrique                                                            */
    /* ------------------------------------------------------------------ */

    private function vehiculeAvecPhoto(): Vehicle
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'toyota'],
            ['name' => 'Toyota', 'is_active' => true],
        );

        $vehicule = Vehicle::create([
            'reference'           => 'VH-APPURL-0001',
            'registration_status' => 'unregistered',
            'brand_id'            => $marque->id,
            'vehicle_model_id'    => VehicleModel::firstOrCreate(
                ['slug' => 'corolla'],
                ['brand_id' => $marque->id, 'name' => 'Corolla', 'is_active' => true],
            )->id,
            'engine_type_id'      => EngineType::firstOrCreate(
                ['code' => 'petrol'],
                ['label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
            )->id,
            'manufacturing_year'  => 2015,
            'condition'           => 'used',
            'status'              => 'in_stock',
            'availability'        => 'sale',
            'sale_price'          => 4_500_000,
            'currency'            => 'XOF',
            'published_at'        => now(),
        ]);

        // Sur le disque `public`, et c'est tout l'interet : c'est son URL qui
        // etait relative, pas celle de la page.
        $vehicule->media()->create([
            'collection' => 'gallery',
            'path'       => 'vehicles/'.$vehicule->id.'/photo.jpg',
            'disk'       => 'public',
            'is_cover'   => true,
        ]);

        return $vehicule;
    }

    /** Le contenu d'une balise meta, isole du reste de la page. */
    private function contenu(string $html, string $propriete): string
    {
        preg_match(
            '/<meta property="'.preg_quote($propriete, '/').'" content="([^"]*)">/',
            $html,
            $trouve,
        );

        return $trouve[1] ?? '';
    }
}
