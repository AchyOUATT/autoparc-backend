<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use App\Services\VinDecodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le decodage d'un VIN doit choisir la bonne generation.
 *
 * Un « modele » est une generation, pas un nom commercial. Trois Mazda3
 * coexistent au catalogue — BL 2008-2013, BM 2013-2018, BP depuis 2018 — et
 * elles repondent toutes au nom « Mazda3 » que NHTSA renvoie.
 *
 * Le defaut couvert ici est reel et a ete signale par un proprietaire : son VIN
 * JM1BM1L78E1161371, un Mazda3 de 2014, ressortait sur la BL, dont la
 * production s'etait arretee l'annee precedente. L'annee etait pourtant connue
 * — NHTSA la donne, et le dixieme caractere du VIN la confirme — mais elle
 * n'entrait pas dans le choix : la requete rendait la premiere ligne que la
 * base voulait bien donner. Et le decodage annoncait une confiance « haute »
 * sur ce resultat, ce qui empechait de voir qu'il y avait quelque chose a
 * corriger. Le vehicule etait ensuite apparie a des pieces qui ne vont pas
 * dessus : celles d'une BL ne montent pas sur une BM.
 */
class VinDecodeGenerationTest extends TestCase
{
    use RefreshDatabase;

    /** Le VIN reel du signalement : Mazda3, dixieme caractere « E » = 2014. */
    private const VIN = 'JM1BM1L78E1161371';

    protected function setUp(): void
    {
        parent::setUp();

        $mazda = Brand::firstOrCreate(['slug' => 'mazda'], ['name' => 'Mazda']);

        foreach ([
            ['BL', 'mazda3-bl', 2008, 2013],
            ['BM', 'mazda3-bm', 2013, 2018],
            ['BP', 'mazda3-bp', 2018, null],
        ] as [$generation, $slug, $debut, $fin]) {
            VehicleModel::create([
                'brand_id'         => $mazda->id,
                'name'             => 'Mazda3',
                'slug'             => $slug,
                'generation'       => $generation,
                'production_start' => $debut,
                'production_end'   => $fin,
                'is_active'        => true,
            ]);
        }
    }

    private function nhtsaRend(string $modele, ?string $annee): void
    {
        Http::fake([
            'vpic.nhtsa.dot.gov/*' => Http::response([
                'Results' => [[
                    'Make'            => 'MAZDA',
                    'Model'           => $modele,
                    'ModelYear'       => $annee ?? '',
                    'ErrorCode'       => '0',
                    'FuelTypePrimary' => 'Gasoline',
                ]],
            ]),
        ]);
    }

    private function decode(string $vin = self::VIN): array
    {
        return app(VinDecodeService::class)->decode($vin);
    }

    public function test_un_mazda3_de_2014_ressort_sur_la_generation_de_2014(): void
    {
        $this->nhtsaRend('Mazda3', '2014');

        $resultat = $this->decode();

        $this->assertSame('BM', $resultat['vehicle_model']['generation'],
            'La BL s\'arrete en 2013 et la BP commence en 2018 : seule la BM couvre 2014.');
        $this->assertTrue($resultat['vehicle_model']['covers_year']);
        $this->assertSame([], $resultat['warnings']);
    }

    public function test_chaque_millesime_tombe_sur_sa_generation(): void
    {
        // Http::fake() CUMULE ses stubs et retient le premier qui correspond :
        // rappele dans la boucle, il n'aurait aucun effet et les quatre
        // millesimes auraient ete decodes avec la reponse du premier. D'ou la
        // variable relue a chaque requete.
        $annee = '';

        Http::fake(function () use (&$annee) {
            return Http::response(['Results' => [[
                'Make'            => 'MAZDA',
                'Model'           => 'Mazda3',
                'ModelYear'       => $annee,
                'ErrorCode'       => '0',
                'FuelTypePrimary' => 'Gasoline',
            ]]]);
        });

        foreach ([2010 => 'BL', 2014 => 'BM', 2016 => 'BM', 2021 => 'BP'] as $millesime => $attendue) {
            $annee = (string) $millesime;

            $this->assertSame(
                $attendue,
                $this->decode()['vehicle_model']['generation'],
                "Un Mazda3 de {$millesime} appartient a la {$attendue}.",
            );
        }
    }

    public function test_une_annee_de_chevauchement_retient_la_generation_qui_demarre(): void
    {
        // 2013 tombe dans la BL (…-2013) comme dans la BM (2013-…) : les
        // generations se chevauchent toujours l'annee du changement. Les
        // constructeurs lancent en cours d'annee civile, donc le millesime
        // appartient le plus souvent a celle qui demarre.
        $this->nhtsaRend('Mazda3', '2013');

        $this->assertSame('BM', $this->decode()['vehicle_model']['generation']);
    }

    public function test_une_annee_que_le_catalogue_ne_couvre_pas_est_annoncee_comme_telle(): void
    {
        // C'est le cas qu'il ne faut surtout pas presenter comme certain : on
        // propose la generation la moins eloignee pour que le proprietaire ait
        // quelque chose a corriger, et on dit que c'est a verifier.
        $this->nhtsaRend('Mazda3', '2004');

        $resultat = $this->decode();

        $this->assertSame('BL', $resultat['vehicle_model']['generation'], 'La moins eloignee de 2004.');
        $this->assertFalse($resultat['vehicle_model']['covers_year']);
        $this->assertNotSame('high', $resultat['confidence'],
            'Une generation hors production n\'est pas une certitude.');

        $this->assertNotEmpty(array_filter(
            $resultat['warnings'],
            fn ($w) => str_contains($w, '2004'),
        ), 'Le proprietaire doit savoir qu\'il y a quelque chose a corriger.');
    }

    public function test_un_modele_a_generation_unique_passe_sans_ceremonie(): void
    {
        // La plupart des modeles n'ont qu'une ligne au catalogue : l'annee ne
        // doit alors rien changer, ni ecarter le seul candidat disponible.
        $mazda = Brand::where('slug', 'mazda')->first();
        VehicleModel::create([
            'brand_id' => $mazda->id, 'name' => 'BT-50', 'slug' => 'bt-50',
            'production_start' => 2011, 'is_active' => true,
        ]);

        $this->nhtsaRend('BT-50', '2015');

        $this->assertSame('BT-50', $this->decode()['vehicle_model']['name']);
    }

    public function test_sans_annee_le_decodage_rend_quand_meme_un_modele(): void
    {
        // NHTSA injoignable ou millesime absent : mieux vaut proposer une
        // generation que rien du tout, le proprietaire corrigera.
        $this->nhtsaRend('Mazda3', null);

        $resultat = $this->decode();

        $this->assertNotNull($resultat['vehicle_model']);
        $this->assertSame('Mazda3', $resultat['vehicle_model']['name']);
    }
}
