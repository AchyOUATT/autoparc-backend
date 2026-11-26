<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Niveau de preuve d'un remplacement de numéro OEM (`superseded_by_id`).
 *
 * `is_superseded` / `superseded_by_id` disent QU'un numéro est remplacé,
 * pas QUI l'atteste. Ces deux colonnes, facultatives, le précisent :
 *  - `supersession_evidence` : `documentation_constructeur` (fiche officielle
 *    du constructeur, par exemple « Previous Version(s) » sur
 *    autoparts.toyota.com) ou `catalogues_distribution` (catalogues de
 *    concessionnaires ou distributeurs de la marque, sans fiche constructeur) ;
 *  - `supersession_sources` : liste JSON des URL consultées.
 *
 * Un remplacement ne transfère AUCUNE compatibilité : chaque application de
 * la référence actuelle reste justifiée individuellement.
 *
 * Additive et idempotente : colonnes nullables, ajoutées seulement si absentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oem_numbers', function (Blueprint $table) {
            if (! Schema::hasColumn('oem_numbers', 'supersession_evidence')) {
                $table->string('supersession_evidence', 40)->nullable()->after('superseded_by_id');
            }
            if (! Schema::hasColumn('oem_numbers', 'supersession_sources')) {
                $table->json('supersession_sources')->nullable()->after('supersession_evidence');
            }
        });
    }

    public function down(): void
    {
        Schema::table('oem_numbers', function (Blueprint $table) {
            foreach (['supersession_sources', 'supersession_evidence'] as $colonne) {
                if (Schema::hasColumn('oem_numbers', $colonne)) {
                    $table->dropColumn($colonne);
                }
            }
        });
    }
};
