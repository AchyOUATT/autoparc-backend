<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Ajoute la finition GX-R (code GXR) au Toyota Land Cruiser J200 et replace
 * la gamme du Golfe dans l'ordre GX < GX-R < VX < VX-R.
 *
 * Le rang est uniquement une hiérarchie de gamme (colonne `trims.rank`, sans
 * contrainte d'unicité) : les véhicules pointent vers `trim_id`, pas vers le
 * rang. Décaler VX de 2 à 3 et VX-R de 3 à 4 est donc sûr, et nécessaire
 * pour éviter un doublon de rang et pour que la synchronisation du catalogue
 * présente la bonne échelle. `updated_at` est touché sur les lignes décalées
 * afin que le delta de `CatalogSyncController` les renvoie aux clients.
 *
 * Motricité par défaut : 4WD, comme les autres finitions du J200 (le
 * Land Cruiser 200 n'existe qu'en quatre roues motrices).
 *
 * Type de moteur par défaut : laissé nul. Au Golfe, le GX-R a été vendu en
 * essence (4,0 V6 1GR-FE ; 4,7 V8 2UZ-FE jusqu'en 2011 ; 4,6 V8 1UR-FE à
 * partir de 2012 — DriveArabia, 24/02/2012, lancement EAU 2012) et, sur les
 * millésimes plus tardifs et à l'export, en diesel 4,5 V8 1VD-FTV. Le modèle
 * `Trim` prévoit précisément de laisser le client préciser le moteur quand
 * une finition en admet plusieurs. On ne choisit donc pas essence ou diesel.
 *
 * Idempotent : retrouve le modèle par slug, les types de moteur et
 * motricités par code, et la finition par (modèle, nom). Ne recrée pas la
 * ligne, ne réécrit pas un type de moteur ou une motricité déjà posés, et
 * ne décale les rangs que s'ils ne sont pas déjà ceux de la gamme.
 */
final class LandCruiserJ200GxrTrimImport
{
    public const MODEL_SLUG = 'land-cruiser-j200';

    public const NAME = 'GX-R';

    public const CODE = 'GXR';

    /**
     * Hiérarchie de gamme du Golfe, du plus bas au plus haut.
     *
     * @var array<string, int>
     */
    public const RANGS = [
        'GX' => 1,
        'GX-R' => 2,
        'VX' => 3,
        'VX-R' => 4,
    ];

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'model_id' => null,
                'trim_id' => null,
                'created' => false,
                'ranks_shifted' => false,
            ];

            $modele = DB::table('vehicle_models')
                ->where('slug', self::MODEL_SLUG)
                ->first();
            if ($modele === null) {
                return $rapport;
            }

            $rapport['model_id'] = (int) $modele->id;

            $quatreRoues = DB::table('drivetrains')->where('code', '4WD')->value('id');

            $rapport['ranks_shifted'] = self::assurerLesRangs((int) $modele->id);

            $existante = DB::table('trims')
                ->where('vehicle_model_id', $modele->id)
                ->where('name', self::NAME)
                ->first();

            if ($existante !== null) {
                $rapport['trim_id'] = (int) $existante->id;

                // Le rang a déjà été aligné ci-dessus. On ne touche ni au
                // moteur par défaut, ni à la motricité, ni à la description
                // d'une ligne déjà en base.
                return $rapport;
            }

            $id = DB::table('trims')->insertGetId([
                'vehicle_model_id' => $modele->id,
                'name' => self::NAME,
                'code' => self::CODE,
                'description' => null,
                'rank' => self::RANGS[self::NAME],
                'default_engine_type_id' => null,
                'default_drivetrain_id' => $quatreRoues,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $rapport['trim_id'] = $id;
            $rapport['created'] = true;

            return $rapport;
        });
    }

    /**
     * Aligne GX, GX-R (si déjà présente), VX et VX-R sur la hiérarchie du Golfe.
     *
     * Les autres finitions éventuellement ajoutées à la main ne sont pas
     * touchées. Les identifiants restent stables.
     */
    private static function assurerLesRangs(int $modeleId): bool
    {
        $decale = false;

        foreach (self::RANGS as $nom => $rang) {
            $finition = DB::table('trims')
                ->where('vehicle_model_id', $modeleId)
                ->where('name', $nom)
                ->first();

            if ($finition === null || (int) $finition->rank === $rang) {
                continue;
            }

            DB::table('trims')
                ->where('id', $finition->id)
                ->update([
                    'rank' => $rang,
                    'updated_at' => now(),
                ]);
            $decale = true;
        }

        return $decale;
    }
}
