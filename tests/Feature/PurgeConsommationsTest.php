<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Nettoyage des consommations inventees, et tarissement de leur source.
 *
 * Le catalogue portait 80 consommations fabriquees par la factory a partir du
 * gabarit, sans une seule cylindree pour les justifier. Les effacer ne suffit
 * pas : tant que la factory en produit, le prochain `db:seed` les recree. Ces
 * deux tests tiennent donc les deux bouts.
 */
class PurgeConsommationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\ReferenceDataSeeder::class,
        ]);
    }

    /**
     * Des vehicules a essence, et rien d'autre.
     *
     * La factory tire le type de moteur au hasard, et un electrique n'a pas de
     * consommation en l/100 km — seulement des kWh. Un tirage au sort dans un
     * test qui compte des lignes, c'est un echec un jour sur trois.
     */
    private function vehiculesEssence(int $nombre, bool $avecConsommation = true)
    {
        $essence = \App\Models\EngineType::where('code', 'petrol')->firstOrFail();

        $factory = Vehicle::factory()->count($nombre)->state(['engine_type_id' => $essence->id]);

        return ($avecConsommation ? $factory->avecConsommationEstimee() : $factory)->create();
    }

    public function test_la_factory_n_invente_plus_de_consommation(): void
    {
        $vehicules = Vehicle::factory()->count(5)->create();

        foreach ($vehicules as $v) {
            $this->assertNull($v->consumption_combined);
            $this->assertNull($v->consumption_urban);
            $this->assertNull($v->co2_g_km);
            $this->assertNull($v->fuel_tank_liters);
        }
    }

    public function test_une_estimation_reste_possible_mais_doit_etre_demandee(): void
    {
        // Le filtre « consommation maximale » de la recherche ne se teste pas
        // sur une colonne vide : l'estimation existe toujours, explicitement.
        $vehicule = $this->vehiculesEssence(1)->first();

        $this->assertNotNull($vehicule->consumption_combined);
        $this->assertGreaterThan(0, (float) $vehicule->consumption_combined);
    }

    public function test_le_mode_analyse_n_efface_rien(): void
    {
        $this->vehiculesEssence(3);

        $this->artisan('catalog:purge-consommations', ['--dry-run' => true])
            ->expectsOutputToContain('Mode analyse')
            ->assertSuccessful();

        $this->assertSame(3, Vehicle::whereNotNull('consumption_combined')->count());
    }

    public function test_la_purge_efface_toutes_les_colonnes_derivees(): void
    {
        $this->vehiculesEssence(3);

        $this->artisan('catalog:purge-consommations', ['--force' => true])
            ->assertSuccessful();

        $this->assertSame(0, Vehicle::whereNotNull('consumption_combined')->count());
        $this->assertSame(0, Vehicle::whereNotNull('consumption_urban')->count());
        $this->assertSame(0, Vehicle::whereNotNull('co2_g_km')->count());
        $this->assertSame(0, Vehicle::whereNotNull('fuel_tank_liters')->count());
    }

    public function test_sur_un_catalogue_deja_propre_elle_ne_fait_rien(): void
    {
        $this->vehiculesEssence(2, avecConsommation: false);

        $this->artisan('catalog:purge-consommations', ['--force' => true])
            ->expectsOutputToContain('rien a faire')
            ->assertSuccessful();
    }

    public function test_sans_confirmation_rien_n_est_efface(): void
    {
        $this->vehiculesEssence(2);

        // Une valeur saisie a la main par un vendeur disparaitrait aussi :
        // la commande ne doit pas effacer sur un simple lancement distrait.
        $this->artisan('catalog:purge-consommations')
            ->expectsConfirmation('Effacer ces valeurs sur 2 fiches ?', 'no')
            ->assertSuccessful();

        $this->assertSame(2, Vehicle::whereNotNull('consumption_combined')->count());
    }
}
