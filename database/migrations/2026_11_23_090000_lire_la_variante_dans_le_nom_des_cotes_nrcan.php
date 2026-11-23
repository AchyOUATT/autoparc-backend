<?php

use App\Support\VarianteNomNrcan;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Motricité, suralimentation et désactivation de cylindres, lues dans le nom.
 *
 * Ressources naturelles Canada n'écrit ces trois informations que dans le nom
 * de modèle (« CX-5 Turbo 4WD », « Ram 1500 (MDS) »). Voir VarianteNomNrcan :
 * seule une mention écrite remplit une colonne, un nom muet la laisse nulle.
 *
 * Trois colonnes nullables, puis un rattrapage des cotes déjà importées :
 *
 * - seules les lignes `nrcan` sont lues — le registre européen écrit des noms
 *   commerciaux qui ne suivent pas ce vocabulaire ;
 * - seule une colonne encore nulle est écrite : rejouer la migration, ou la
 *   lancer après un import qui a déjà rempli les colonnes, n'écrit rien ;
 * - `updated_at`, `cle_source` et `identity_key` ne sont pas touchés : le nom
 *   faisait déjà partie de l'empreinte, la cote publiée ne change pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motorisations', function (Blueprint $table) {
            if (! Schema::hasColumn('motorisations', 'drivetrain_code')) {
                $table->string('drivetrain_code', 8)->nullable();
            }
            if (! Schema::hasColumn('motorisations', 'aspiration')) {
                $table->string('aspiration', 16)->nullable();
            }
            if (! Schema::hasColumn('motorisations', 'cylinder_deactivation')) {
                $table->boolean('cylinder_deactivation')->nullable();
            }
        });

        DB::table('motorisations')
            ->where('source', 'nrcan')
            ->select(['id', 'model_raw', 'fuel_code', 'drivetrain_code', 'aspiration', 'cylinder_deactivation'])
            ->orderBy('id')
            ->chunkById(1000, function ($lignes) {
                // Une requête par combinaison de valeurs, pas une par ligne :
                // 58 000 cotes en production, quelques dizaines de combinaisons.
                $paquets = [];
                foreach ($lignes as $ligne) {
                    $lu = VarianteNomNrcan::lire($ligne->model_raw, $ligne->fuel_code);
                    foreach ($lu as $colonne => $valeur) {
                        if ($valeur === null || $ligne->$colonne !== null) {
                            continue;
                        }
                        $cle = $colonne.'|'.var_export($valeur, true);
                        $paquets[$cle]['colonne'] = $colonne;
                        $paquets[$cle]['valeur'] = $valeur;
                        $paquets[$cle]['ids'][] = $ligne->id;
                    }
                }
                foreach ($paquets as $paquet) {
                    DB::table('motorisations')
                        ->whereIn('id', $paquet['ids'])
                        ->whereNull($paquet['colonne'])
                        ->update([$paquet['colonne'] => $paquet['valeur']]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('motorisations', function (Blueprint $table) {
            $table->dropColumn(['drivetrain_code', 'aspiration', 'cylinder_deactivation']);
        });
    }
};
