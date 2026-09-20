<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une marque proposee au choix doit mener quelque part.
 *
 * Cinq marques figuraient dans la liste sans le moindre modele : Lexus,
 * SsangYong, Citroen, Opel et Fiat. Rien ne le signalait — ni erreur, ni
 * journal. Un proprietaire de Lexus choisissait sa marque, arrivait sur une
 * liste vide, et ne pouvait pas ajouter son vehicule. Pendant ce temps, 584
 * cotes de consommation Lexus attendaient en base, rattachees a rien.
 *
 * C'est la meme famille de panne que les compatibilites vides ou la cloche de
 * notifications muette : une reponse valide, et personne pour dire qu'elle est
 * vide.
 */
class CatalogueModelesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\CountriesSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
        ]);
    }

    public function test_aucune_marque_ne_reste_sans_modele(): void
    {
        $orphelines = Brand::query()
            ->whereDoesntHave('vehicleModels')
            ->pluck('name');

        $this->assertTrue(
            $orphelines->isEmpty(),
            'Ces marques mènent à une liste vide : '.$orphelines->implode(', '),
        );
    }

    public function test_les_generations_d_un_meme_modele_ne_se_chevauchent_pas(): void
    {
        // Le rattachement des cotes choisit la generation produite l'annee du
        // vehicule. Deux generations qui se recouvrent rendraient ce choix
        // arbitraire — et la consommation affichee avec.
        $parModele = VehicleModel::query()
            ->whereNotNull('production_start')
            ->get(['brand_id', 'name', 'generation', 'production_start', 'production_end'])
            ->groupBy(fn (VehicleModel $m) => $m->brand_id.'|'.$m->name);

        foreach ($parModele as $cle => $generations) {
            $triees = $generations->sortBy('production_start')->values();

            for ($i = 1; $i < $triees->count(); $i++) {
                $precedente = $triees[$i - 1];
                $courante   = $triees[$i];

                $this->assertNotNull(
                    $precedente->production_end,
                    "$cle : la génération {$precedente->generation} n'a pas de fin alors "
                    ."qu'une suivante commence en {$courante->production_start}.",
                );
                $this->assertLessThanOrEqual(
                    $courante->production_start,
                    $precedente->production_end,
                    "$cle : {$precedente->generation} et {$courante->generation} se chevauchent.",
                );
            }
        }
    }

    public function test_les_marques_ajoutees_portent_bien_leurs_modeles(): void
    {
        foreach (['Lexus', 'Citroën', 'Opel', 'Fiat', 'SsangYong'] as $nom) {
            $marque = Brand::where('name', $nom)->first();

            $this->assertNotNull($marque, "La marque $nom a disparu du catalogue.");
            $this->assertGreaterThan(
                0,
                VehicleModel::where('brand_id', $marque->id)->count(),
                "$nom ne propose aucun modèle.",
            );
        }
    }
}
