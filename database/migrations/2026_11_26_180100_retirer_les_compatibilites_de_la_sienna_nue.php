<?php

use App\Support\SiennaLigneNueCompatibilitesRetrait;
use Illuminate\Database\Migrations\Migration;

/**
 * Retire les 4 compatibilités de la ligne nue `sienna` (décision du
 * propriétaire du 26/11/2026), chacune seulement si ses remplaçantes au
 * niveau génération existent et couvrent ses années. Ciblage par SKU, slug,
 * moteur et bornes ; trace complète dans `part_fitment_archives` ; détail
 * dans `docs/proposition-referentiel-sienna.md`.
 *
 * Idempotente. Ne lève jamais d'exception : le déploiement doit continuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $rapport = SiennaLigneNueCompatibilitesRetrait::run();
            echo '  Sienna, compatibilités de la ligne nue : '.json_encode($rapport, JSON_UNESCAPED_UNICODE).PHP_EOL;
        } catch (Throwable $e) {
            echo '  ATTENTION : compatibilités de la ligne nue Sienna non retirées ('.$e->getMessage().'). Le déploiement continue.'.PHP_EOL;
        }
    }

    /** Recrée les lignes depuis leur archive. */
    public function down(): void
    {
        SiennaLigneNueCompatibilitesRetrait::restaurer();
    }
};
