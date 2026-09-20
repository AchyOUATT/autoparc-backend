<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Motorisation;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Import des cotes europeennes, pour ce que le Canada ne vend pas.
 *
 * Hilux, Prado, D-Max, Navara, L200 : l'ossature du parc burkinabe est absente
 * du marche nord-americain. Le registre CO2 europeen, lui, couvre les
 * utilitaires legers de categorie N1.
 *
 * Sa forme impose deux traitements que ces tests tiennent :
 *   - il expose une ligne par immatriculation, pas par motorisation : des
 *     milliers de lignes pour une poignee de moteurs, qu'il faut regrouper ;
 *   - les noms commerciaux different du catalogue — « Land Cruiser Prado » n'y
 *     figure que comme « Land Cruiser » — d'ou la recherche par motifs de plus
 *     en plus larges.
 */
class ImportConsommationsEuropeTest extends TestCase
{
    use RefreshDatabase;

    /** Reponse du service europeen : une ligne par immatriculation. */
    private function reponse(array $lignes): array
    {
        return ['results' => $lignes];
    }

    private function immatriculation(string $marque, string $nom, float $conso, array $extra = []): array
    {
        return array_merge([
            'Mk'  => $marque,
            'Cn'  => $nom,
            'Ct'  => 'N1G',
            'Ft'  => 'diesel',
            'cc'  => 2755,
            'kw'  => 150,
            'Fc'  => $conso,
        ], $extra);
    }

    private function catalogue(string $marque, string $modele): VehicleModel
    {
        $m = Brand::create(['name' => $marque]);

        return VehicleModel::create(['brand_id' => $m->id, 'name' => $modele]);
    }

    public function test_les_immatriculations_sont_regroupees_par_motorisation(): void
    {
        $this->catalogue('Toyota', 'Hilux');

        Http::fake([
            'discodata.eea.europa.eu/*' => Http::response($this->reponse([
                // Quatre immatriculations du meme moteur : une seule
                // motorisation, et la mediane pour cote.
                $this->immatriculation('TOYOTA', 'HILUX', 9.3),
                $this->immatriculation('TOYOTA', 'HILUX', 9.4),
                $this->immatriculation('TOYOTA', 'HILUX', 9.4),
                $this->immatriculation('TOYOTA', 'HILUX', 30.0),   // valeur aberrante
                $this->immatriculation('TOYOTA', 'HILUX', 8.1, ['cc' => 2393, 'kw' => 110]),
            ])),
        ]);

        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])->assertSuccessful();

        $this->assertSame(2, Motorisation::where('source', 'eea')->count());

        $grosMoteur = Motorisation::where('engine_l', 2.8)->first();
        $this->assertEqualsWithDelta(9.4, (float) $grosMoteur->consumption_combined, 0.01,
            'La mediane resiste a une immatriculation aberrante, la moyenne non.');
        $this->assertSame('D', $grosMoteur->fuel_code);
        $this->assertSame('N1G', $grosMoteur->vehicle_class);
        $this->assertSame('wltp', $grosMoteur->cycle);
    }

    public function test_un_nom_absent_est_retente_en_plus_court(): void
    {
        $this->catalogue('Toyota', 'Land Cruiser Prado');

        Http::fake(function ($request) {
            // L'Europe ne connait pas le « Prado » : seule la recherche
            // raccourcie ramene quelque chose.
            $contient = fn (string $mot) => str_contains(urldecode($request->url()), $mot);

            if ($contient('LAND CRUISER PRADO')) {
                return Http::response($this->reponse([]));
            }

            return Http::response($this->reponse([
                $this->immatriculation('TOYOTA', 'LAND CRUISER', 8.9),
            ]));
        });

        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])->assertSuccessful();

        $cote = Motorisation::where('source', 'eea')->first();
        $this->assertNotNull($cote, 'Le repli sur un nom plus court doit trouver le modele.');
        $this->assertSame('LAND CRUISER', $cote->model_raw);
    }

    public function test_une_cylindree_inconnue_ne_cree_pas_de_doublon(): void
    {
        $this->catalogue('Nissan', 'Navara');

        Http::fake([
            'discodata.eea.europa.eu/*' => Http::response($this->reponse([
                $this->immatriculation('NISSAN', 'NAVARA', 7.8, ['cc' => null, 'kw' => null]),
            ])),
        ]);

        // Deux passages : sans empreinte, les colonnes nulles auraient fait
        // passer la meme ligne deux fois — MySQL et PostgreSQL considerent
        // deux NULL comme distincts.
        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])->assertSuccessful();
        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])->assertSuccessful();

        $this->assertSame(1, Motorisation::where('source', 'eea')->count());
        $this->assertNull(Motorisation::first()->engine_l);
    }

    public function test_seuls_les_modeles_sans_cote_sont_cherches(): void
    {
        $hilux = $this->catalogue('Toyota', 'Hilux');
        $corolla = VehicleModel::create(['brand_id' => $hilux->brand_id, 'name' => 'Corolla']);

        // La Corolla a deja une cote canadienne : inutile d'aller la chercher
        // en Europe, chaque modele coutant une requete au service.
        Motorisation::create([
            'source' => 'nrcan', 'cycle' => '5-cycle', 'cle_source' => 'deja-la',
            'model_year' => 2013, 'make_raw' => 'Toyota', 'model_raw' => 'Corolla',
            'consumption_combined' => 7.5, 'vehicle_model_id' => $corolla->id,
        ]);

        Http::fake([
            'discodata.eea.europa.eu/*' => Http::response($this->reponse([
                $this->immatriculation('TOYOTA', 'HILUX', 9.4),
            ])),
        ]);

        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])
            ->expectsOutputToContain('1 modeles a chercher en Europe.')
            ->assertSuccessful();
    }

    public function test_un_service_en_panne_ne_fait_pas_echouer_la_commande(): void
    {
        $this->catalogue('Isuzu', 'D-Max');

        Http::fake(['discodata.eea.europa.eu/*' => Http::response('', 503)]);

        $this->artisan('catalog:import-consommations', ['--source' => 'eea'])
            ->assertSuccessful();

        $this->assertSame(0, Motorisation::count());
    }

    public function test_le_mode_analyse_n_ecrit_rien(): void
    {
        $this->catalogue('Toyota', 'Hilux');

        Http::fake([
            'discodata.eea.europa.eu/*' => Http::response($this->reponse([
                $this->immatriculation('TOYOTA', 'HILUX', 9.4),
            ])),
        ]);

        $this->artisan('catalog:import-consommations', ['--source' => 'eea', '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Motorisation::count());
    }
}
