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
        // Catalogue de features indexé par slug-like name (ceux du FeaturesSeeder)
        $all = Feature::all()->keyBy('name');

        if ($all->isEmpty()) {
            return; // FeaturesSeeder pas encore passé
        }

        $vehicles = Vehicle::all();

        // ── Groupes pour tirage ────────────────────────────────────────────

        // Sécurité de base : presque tous les véhicules
        $securiteBase = $this->ids($all, [
            'ABS', 'Airbags frontaux', 'Freins à disques avant',
        ]);

        // Sécurité évoluée : 60 % des véhicules
        $securitePlus = $this->ids($all, [
            'ESP / Contrôle de stabilité',
            'Aide au freinage d\'urgence (BA)',
            'Airbags latéraux',
            'Airbags rideaux',
            'Contrôle de traction (ASR)',
            'Aide au démarrage en côte',
            'Freins à disques 4 roues',
            'Ancrage siège enfant',
        ]);

        // Confort de base : presque tous
        $confortBase = $this->ids($all, [
            'Climatisation manuelle',
            'Vitres électriques avant',
            'Rétroviseurs électriques',
        ]);

        // Confort premium : 50 %
        $confortPremium = $this->ids($all, [
            'Climatisation automatique',
            'Toit ouvrant',
            'Toit panoramique',
            'Sièges ventilés',
            'Vitres électriques arrière',
            'Rétroviseurs rabattables électr.',
            'Volant réglable en hauteur',
            'Siège conducteur électrique',
            'Accès et démarrage sans clé',
            'Direction assistée électrique',
        ]);

        // Multimédia de base : 80 %
        $multimediaBase = $this->ids($all, [
            'Autoradio', 'Bluetooth', 'Prise USB',
        ]);

        // Multimédia premium : 50 %
        $multimediaPremium = $this->ids($all, [
            'Écran tactile', 'Android Auto', 'Apple CarPlay',
            'GPS intégré', 'Haut-parleurs premium', 'Chargeur à induction',
        ]);

        // Aide à la conduite : 55 %
        $aideConduite = $this->ids($all, [
            'Caméra de recul',
            'Radar de recul',
            'Régulateur de vitesse',
            'Caméra 360°',
            'Radar avant',
            'Régulateur adaptatif (ACC)',
            'Alerte sortie de voie',
            'Détecteur d\'angle mort',
            'Freinage automatique d\'urgence',
            'Affichage tête haute (HUD)',
            'Reconnaissance des panneaux',
        ]);

        // Dotation : la plupart des véhicules
        $dotation = $this->ids($all, [
            'Triangle de signalisation', 'Roue de secours', 'Cric',
            'Clé de roue', 'Boîte à pharmacie', 'Gilet réfléchissant',
            'Câbles de démarrage', 'Manuel du propriétaire', 'Carnet d\'entretien',
        ]);

        // ── Attribution par véhicule ───────────────────────────────────────

        foreach ($vehicles as $vehicle) {
            $featureIds = [];

            // Sécurité de base (90 % de chance par feature)
            foreach ($securiteBase as $id) {
                if (rand(1, 10) <= 9) $featureIds[] = $id;
            }
            // Sécurité évoluée : tire 3-6 au hasard, 60 % de chance d'en avoir
            if (rand(1, 10) <= 6) {
                $featureIds = array_merge(
                    $featureIds,
                    $this->pick($securitePlus, rand(2, 5))
                );
            }

            // Confort de base (85 % de chance par feature)
            foreach ($confortBase as $id) {
                if (rand(1, 100) <= 85) $featureIds[] = $id;
            }
            // Confort premium : 50 % des véhicules, 2-5 features
            if (rand(1, 10) <= 5) {
                $featureIds = array_merge(
                    $featureIds,
                    $this->pick($confortPremium, rand(2, 5))
                );
            }

            // Multimédia de base (80 %)
            foreach ($multimediaBase as $id) {
                if (rand(1, 10) <= 8) $featureIds[] = $id;
            }
            // Multimédia premium (50 %, 2-4 features)
            if (rand(1, 10) <= 5) {
                $featureIds = array_merge(
                    $featureIds,
                    $this->pick($multimediaPremium, rand(2, 4))
                );
            }

            // Aide à la conduite (55 %, 2-5 features)
            if (rand(1, 10) <= 6) {
                $featureIds = array_merge(
                    $featureIds,
                    $this->pick($aideConduite, rand(2, 5))
                );
            }

            // Dotation (75 % de chance par feature)
            foreach ($dotation as $id) {
                if (rand(1, 100) <= 75) $featureIds[] = $id;
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

    /** Retourne les IDs des features dont le nom est dans $names. */
    private function ids(\Illuminate\Support\Collection $all, array $names): array
    {
        return $all->only($names)
            ->pluck('id')
            ->values()
            ->toArray();
    }

    /** Tire au hasard $n éléments dans $ids (sans répétition). */
    private function pick(array $ids, int $n): array
    {
        if (empty($ids)) return [];
        shuffle($ids);
        return array_slice($ids, 0, min($n, count($ids)));
    }
};
