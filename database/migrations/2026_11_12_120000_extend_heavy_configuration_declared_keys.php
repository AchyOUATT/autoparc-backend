<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Les nouvelles cles (type de carrosserie, volume utile, capacite de cuve,
 * charge utile) vivent dans la colonne JSON existante `heavy_configuration`,
 * deja nullable. Aucun alter de schema : un vehicule sans ces precisions
 * reste valide, et l'application publiee ignore les cles qu'elle ne lit pas.
 *
 * On n'etend PAS `vehicles.body_style` : une valeur inconnue y serait
 * affichee brute (voire mal classee) par les binaires deja installes.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const KEYS = [
        'body_type', 'useful_volume_m3', 'tank_capacity_liters', 'payload_kg',
    ];

    public function up(): void
    {
        // Rien a creer : la colonne existe depuis
        // 2026_11_07_000001_add_heavy_configuration_to_vehicles.
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            $removals = implode(' - ', array_map(
                fn (string $key) => "'{$key}'",
                self::KEYS,
            ));
            DB::statement("
                UPDATE vehicles
                SET heavy_configuration = ((heavy_configuration::jsonb) - {$removals})::json
                WHERE heavy_configuration IS NOT NULL
            ");
            DB::statement("
                UPDATE vehicles
                SET heavy_configuration = NULL
                WHERE heavy_configuration IS NOT NULL
                  AND heavy_configuration::jsonb = '{}'::jsonb
            ");

            return;
        }

        foreach (self::KEYS as $key) {
            DB::statement("
                UPDATE vehicles
                SET heavy_configuration = json_remove(heavy_configuration, '\$.{$key}')
                WHERE heavy_configuration IS NOT NULL
            ");
        }
        DB::statement("
            UPDATE vehicles
            SET heavy_configuration = NULL
            WHERE heavy_configuration IS NOT NULL
              AND heavy_configuration IN ('{}', '[]', 'null')
        ");
    }
};
