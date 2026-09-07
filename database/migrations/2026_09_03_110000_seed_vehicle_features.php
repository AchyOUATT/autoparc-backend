<?php

use App\Models\Feature;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Attache aléatoirement des équipements à tous les véhicules.
     * Idempotent : vide d'abord la table pivot, puis re-remplit.
     */
    public function up(): void
    {
        // Requêtes par catégorie (valeurs ASCII — aucun risque d'encodage)
        $securite    = Feature::where('category', 'securite')->pluck('id')->toArray();
        $confort     = Feature::where('category', 'confort')->pluck('id')->toArray();
        $multimedia  = Feature::where('category', 'multimedia')->pluck('id')->toArray();
        $aideConduite= Feature::where('category', 'aide_conduite')->pluck('id')->toArray();
        $dotation    = Feature::where('category', 'dotation')->pluck('id')->toArray();

        if (empty($securite) && empty($dotation)) {
            return; // FeaturesSeeder pas encore passé
        }

        $vehicles = Vehicle::all();

        // ── Attribution par véhicule ───────────────────────────────────────

        foreach ($vehicles as $vehicle) {
            $featureIds = [];

            // Sécurité : 3-8 features sur les 11 disponibles
            $featureIds = array_merge($featureIds, $this->pick($securite, rand(3, 8)));

            // Confort : 2-7 features (50 % des véhicules en ont beaucoup)
            if (rand(1, 10) <= 8) {
                $featureIds = array_merge($featureIds, $this->pick($confort, rand(2, 7)));
            }

            // Multimédia : 2-6 features (80 % des véhicules)
            if (rand(1, 10) <= 8) {
                $featureIds = array_merge($featureIds, $this->pick($multimedia, rand(2, 6)));
            }

            // Aide à la conduite : 2-5 features (55 % des véhicules)
            if (rand(1, 10) <= 6) {
                $featureIds = array_merge($featureIds, $this->pick($aideConduite, rand(2, 5)));
            }

            // Dotation : 4-9 features sur les 9 disponibles (75 % des véhicules)
            if (rand(1, 10) <= 8) {
                $featureIds = array_merge($featureIds, $this->pick($dotation, rand(4, 9)));
            }

            // Attach (sync = remplace tout — idempotent)
            $vehicle->features()->sync(array_unique($featureIds));
        }
    }

    public function down(): void
    {
        \Illuminate\Support\Facades\DB::table('feature_vehicle')->truncate();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /** Tire au hasard $n éléments dans $ids (sans répétition). */
    private function pick(array $ids, int $n): array
    {
        if (empty($ids)) return [];
        shuffle($ids);
        return array_slice($ids, 0, min($n, count($ids)));
    }
};
