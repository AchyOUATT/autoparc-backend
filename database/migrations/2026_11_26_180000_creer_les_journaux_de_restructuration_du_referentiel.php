<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deux journaux pour les restructurations du référentiel véhicules (premier
 * usage : la ligne nue `sienna`, 26/11/2026).
 *
 *  - `part_fitment_archives` : une compatibilité retirée y laisse sa ligne
 *    complète (JSON), sa clé lisible (SKU, slug, moteur, bornes) et la liste
 *    des compatibilités documentées qui la remplacent. Le `down()` de la
 *    migration qui l'a retirée la recrée depuis ce journal.
 *  - `motorisation_generation_assignments` : chaque motorisation examinée y
 *    a une ligne, réattribuée ou non, avec le modèle d'origine, le modèle
 *    retenu, la règle appliquée, le moteur et les châssis déduits de l'EPC
 *    (NON recopiés dans `motorisations`), et la raison d'une mise de côté.
 *    Le rattachement automatique des cotes (`consommations:importer`)
 *    ignore les motorisations présentes ici, pour ne pas défaire une
 *    décision documentée par sa seule règle d'années.
 *
 * Aucune clé étrangère vers `part_fitments` (la ligne archivée n'existe
 * plus) ; celle vers `motorisations` part en cascade avec la cote.
 *
 * Additive et idempotente : tables créées seulement si absentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_fitment_archives')) {
            Schema::create('part_fitment_archives', function (Blueprint $table) {
                $table->id();
                $table->string('migration', 150);
                $table->unsignedBigInteger('original_id')->nullable();
                $table->string('fitment_key', 191);
                $table->string('part_sku', 100);
                $table->string('vehicle_model_slug', 150);
                $table->json('attributes');
                $table->json('replaced_by');
                $table->text('reason');
                $table->timestamp('archived_at');
                $table->timestamps();

                $table->unique(['migration', 'fitment_key'], 'part_fitment_archives_migration_key_unique');
            });
        }

        if (! Schema::hasTable('motorisation_generation_assignments')) {
            Schema::create('motorisation_generation_assignments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('motorisation_id')->constrained()->cascadeOnDelete();
                $table->string('migration', 150);
                // reassigned | ambiguous | not_reassigned
                $table->string('decision', 20);
                $table->string('from_vehicle_model_slug', 150)->nullable();
                $table->string('to_vehicle_model_slug', 150)->nullable();
                $table->string('rule', 60)->nullable();
                $table->string('engine_code_deduced', 30)->nullable();
                $table->string('chassis_codes_deduced', 120)->nullable();
                $table->text('reason');
                $table->json('evidence')->nullable();
                $table->timestamps();

                $table->unique('motorisation_id', 'motorisation_generation_assignments_motorisation_unique');
            });
        }
    }

    /** Les journaux ne sont retirés que vides : ils sont la trace des décisions. */
    public function down(): void
    {
        foreach (['motorisation_generation_assignments', 'part_fitment_archives'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->count() === 0) {
                Schema::drop($table);
            }
        }
    }
};
