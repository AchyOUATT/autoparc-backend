<?php

use App\Console\Commands\ImportConsommations;
use App\Support\ReferenceCatalogWriter;
use Database\Seeders\VehicleModelsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ajoute le CX-5 KE et le CX-50 VA, puis leur rattache les cotes NRCan orphelines.
 *
 * Les definitions sont lues dans `VehicleModelsSeeder::GENERATIONS_MAZDA_CX`,
 * qui explique les bornes et la source du code VA.
 *
 * Le rattachement n'invente pas de regle : chaque cote Mazda encore sans
 * modele passe par `ImportConsommations::modeleLePlusProche`, la fonction du
 * prochain import, avec toutes les generations Mazda du catalogue. Elle n'est
 * retenue que si cette fonction la donne a l'une des deux fiches ajoutees ici.
 * Le prochain import retrouvera donc exactement le meme resultat.
 *
 * Ne touche ni aux cotes deja rattachees, ni aux cotes verifiees ou liees a
 * une finition (le rattachement automatique les ignore aussi), ni a leur
 * `updated_at` : comme `relier()`, on corrige un lien, pas la cote publiee.
 *
 * Idempotente : les fiches sont posees par (marque, slug) et seules les cotes
 * encore nulles sont lues. Aucune exception : le demarrage du conteneur
 * enchaine `migrate --force` avant de servir.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mazda = DB::table('brands')->where('slug', 'mazda')->first();
        if ($mazda === null) {
            return;
        }

        $ajoutees = [];
        foreach (VehicleModelsSeeder::GENERATIONS_MAZDA_CX as [$nom, $generation, $carrosserie, $segment, $debut, $fin]) {
            $slug = Str::slug($nom.'-'.$generation);
            ReferenceCatalogWriter::upsert('vehicle_models', [
                'brand_id' => $mazda->id,
                'slug' => $slug,
            ], [
                'name' => $nom,
                'generation' => $generation,
                'body_type' => $carrosserie,
                'segment' => $segment,
                'production_start' => $debut,
                'production_end' => $fin,
                'is_active' => true,
            ]);
            $ajoutees[] = DB::table('vehicle_models')
                ->where('brand_id', $mazda->id)->where('slug', $slug)->value('id');
        }

        $modeles = DB::table('vehicle_models')
            ->where('brand_id', $mazda->id)
            ->get(['id', 'name', 'production_start', 'production_end']);

        DB::table('motorisations')
            ->whereNull('vehicle_model_id')
            ->whereNull('verified_at')
            ->whereNull('trim_id')
            ->whereNull('trim_application_id')
            ->whereRaw('LOWER(make_raw) = ?', ['mazda'])
            ->select(['id', 'model_raw', 'model_year', 'year_kind'])
            ->orderBy('id')
            ->chunkById(500, function ($cotes) use ($modeles, $ajoutees, $mazda) {
                $parModele = [];
                foreach ($cotes as $cote) {
                    $id = ImportConsommations::modeleLePlusProche(
                        (string) $cote->model_raw,
                        $cote->year_kind === 'registration_year' ? null : (int) $cote->model_year,
                        $modeles,
                        $mazda->name,
                    );
                    if ($id !== null && in_array($id, $ajoutees, false)) {
                        $parModele[$id][] = $cote->id;
                    }
                }
                foreach ($parModele as $modeleId => $ids) {
                    DB::table('motorisations')
                        ->whereIn('id', $ids)
                        ->whereNull('vehicle_model_id')
                        ->update(['vehicle_model_id' => $modeleId, 'brand_id' => $mazda->id]);
                }
            });
    }

    public function down(): void
    {
        // Non destructif : des vehicules ou annonces peuvent deja pointer vers
        // ces fiches. Les retirer demande une revue explicite.
    }
};
