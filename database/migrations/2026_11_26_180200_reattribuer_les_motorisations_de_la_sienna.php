<?php

use App\Support\SiennaMotorisationsReattribution;
use Illuminate\Database\Migrations\Migration;

/**
 * Réattribue aux générations XL20 / XL30 / XL40 les motorisations nrcan de
 * la ligne nue `sienna` (décision du propriétaire du 26/11/2026), règle par
 * règle (cylindrée, cylindres, boîte, carburant, année-modèle confrontée aux
 * dates de fabrication EPC). Les cotes ambiguës restent sur la ligne nue et
 * sont journalisées pour validation manuelle. Aucune cote supprimée ni
 * copiée ; seule `vehicle_model_id` change. Journal :
 * `motorisation_generation_assignments` ; rapport ligne par ligne :
 * `docs/rapports/sienna-motorisations-2026-11-26.csv`.
 *
 * Idempotente. Ne lève jamais d'exception : le déploiement doit continuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $rapport = SiennaMotorisationsReattribution::run();
            echo '  Sienna, motorisations : '.json_encode($rapport, JSON_UNESCAPED_UNICODE).PHP_EOL;
        } catch (Throwable $e) {
            echo '  ATTENTION : motorisations Sienna non réattribuées ('.$e->getMessage().'). Le déploiement continue.'.PHP_EOL;
        }
    }

    public function down(): void
    {
        SiennaMotorisationsReattribution::restaurer();
    }
};
