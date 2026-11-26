<?php

use App\Support\ToyotaSiennaReferentielImport;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Donne ses générations XL20, XL30 et XL40 à la Toyota Sienna, leurs
 * compatibilités de filtre à huile réévaluées une à une, et rend la ligne nue
 * `sienna` inactive (décision du propriétaire du 26/11/2026).
 *
 * La XL10 est posée par la PR #41 (`GENERATIONS_FILTRE_YZZD1`) ; cette
 * migration ne la touche pas et n'en dépend pas pour s'exécuter.
 *
 * Additive et idempotente : générations créées si leur slug manque,
 * compatibilités insérées si absentes, aucune ligne existante modifiée sauf
 * la ligne nue (is_active = false, bornes vidées comme le Tacoma et le
 * Venza), et seulement si aucun véhicule ne la référence. Les 4
 * compatibilités, les 86 motorisations nrcan et tout autre lien de la ligne
 * nue restent en place.
 *
 * Ne lève jamais d'exception : le déploiement doit continuer.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            $rapport = ToyotaSiennaReferentielImport::run();
            echo '  Sienna : '.json_encode($rapport, JSON_UNESCAPED_UNICODE).PHP_EOL;
        } catch (Throwable $e) {
            echo '  ATTENTION : référentiel Sienna non posé ('.$e->getMessage().'). Le déploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Réactive la ligne nue avec ses bornes d'avant (2007-ouvert) et retire
     * les générations XL20/XL30/XL40 qu'aucun véhicule ne référence (leurs
     * compatibilités partent en cascade).
     */
    public function down(): void
    {
        $toyota = DB::table('brands')->where('slug', 'toyota')->value('id');
        if ($toyota === null) {
            return;
        }

        DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', ToyotaSiennaReferentielImport::SLUG_NU)
            ->update(['is_active' => true, 'production_start' => 2007, 'production_end' => null, 'updated_at' => now()]);

        foreach (VehicleModelsSeeder::GENERATIONS_SIENNA as [$nom, $gen]) {
            $id = DB::table('vehicle_models')->where('brand_id', $toyota)->where('slug', Str::slug($nom.'-'.$gen))->value('id');
            if ($id === null) {
                continue;
            }
            $attaches = DB::table('vehicles')->where('vehicle_model_id', $id)->count()
                + DB::table('owned_vehicles')->where('vehicle_model_id', $id)->count();
            if ($attaches === 0) {
                DB::table('vehicle_models')->where('id', $id)->delete();
            }
        }
    }
};
