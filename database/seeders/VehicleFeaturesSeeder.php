<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Vehicle;
use Illuminate\Database\Seeder;

/**
 * Attache aléatoirement des équipements aux véhicules.
 *
 * Cette logique vivait dans la migration 2026_09_03_110000_seed_vehicle_features,
 * où elle ne pouvait pas fonctionner : les migrations s'exécutent avant les
 * seeders, donc les tables `features` et `vehicles` étaient encore vides et la
 * migration sortait sans rien faire — silencieusement, en se marquant appliquée.
 * Sa place est ici, appelé par DatabaseSeeder après FeaturesSeeder et les
 * véhicules.
 *
 * Réexécutable : les véhicules ayant déjà des équipements sont ignorés, pour ne
 * pas retirer au hasard ce qu'un utilisateur aurait saisi à la main.
 */
class VehicleFeaturesSeeder extends Seeder
{
    public function run(): void
    {
        $byCategory = [
            'securite'      => Feature::where('category', 'securite')->pluck('id')->all(),
            'confort'       => Feature::where('category', 'confort')->pluck('id')->all(),
            'multimedia'    => Feature::where('category', 'multimedia')->pluck('id')->all(),
            'aide_conduite' => Feature::where('category', 'aide_conduite')->pluck('id')->all(),
            'dotation'      => Feature::where('category', 'dotation')->pluck('id')->all(),
        ];

        // Contrairement à l'ancienne migration, on signale l'anomalie au lieu de
        // sortir en silence : une table `features` vide ici est une erreur
        // d'ordonnancement, pas un cas normal.
        if (empty(array_filter($byCategory))) {
            $this->command?->warn(
                'VehicleFeaturesSeeder ignoré : aucune caractéristique en base. '
                . 'FeaturesSeeder doit être appelé avant.'
            );

            return;
        }

        $vehicles = Vehicle::doesntHave('features')->get();

        if ($vehicles->isEmpty()) {
            $this->command?->info('Tous les véhicules ont déjà des équipements — rien à faire.');

            return;
        }

        foreach ($vehicles as $vehicle) {
            $ids = $this->pick($byCategory['securite'], rand(3, 8));

            // Toutes les voitures n'ont pas tout : ces tirages donnent au parc
            // une hétérogénéité réaliste.
            if (rand(1, 10) <= 8) {
                $ids = array_merge($ids, $this->pick($byCategory['confort'], rand(2, 7)));
            }
            if (rand(1, 10) <= 8) {
                $ids = array_merge($ids, $this->pick($byCategory['multimedia'], rand(2, 6)));
            }
            if (rand(1, 10) <= 6) {
                $ids = array_merge($ids, $this->pick($byCategory['aide_conduite'], rand(2, 5)));
            }
            if (rand(1, 10) <= 8) {
                $ids = array_merge($ids, $this->pick($byCategory['dotation'], rand(4, 9)));
            }

            $vehicle->features()->sync(array_unique($ids));
        }

        $this->command?->info("Équipements attachés à {$vehicles->count()} véhicule(s).");
    }

    /** Tire au hasard $n éléments dans $ids (sans répétition). */
    private function pick(array $ids, int $n): array
    {
        if (empty($ids)) {
            return [];
        }

        shuffle($ids);

        return array_slice($ids, 0, min($n, count($ids)));
    }
}
