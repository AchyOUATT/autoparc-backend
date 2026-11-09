<?php

namespace App\Support;

use App\Enums\FitmentSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/** Additive, source-backed additions for the platform's 90915-10001 only. */
final class Toyota10001VerifiedImport
{
    private const SKU = '90915-10001';

    /** Reference seeding never imports candidate or article fitments. */
    public static function ensureModels(): array
    {
        $catalogue = self::catalogue();

        return DB::transaction(function () use ($catalogue): array {
            $brand = DB::table('brands')->where('slug', 'toyota')->lockForUpdate()->first();

            return $brand === null
                ? ['inserted' => 0, 'ids' => []]
                : self::insertModels((int) $brand->id, $catalogue['models']);
        });
    }

    /** Import only the reviewed fitments; pending_candidates is never consumed. */
    public static function run(): array
    {
        $catalogue = self::catalogue();

        return DB::transaction(function () use ($catalogue): array {
            $brand = DB::table('brands')->where('slug', 'toyota')->lockForUpdate()->first();
            if ($brand === null) {
                return ['models_inserted' => 0, 'fitments_inserted' => 0, 'part_id' => null];
            }

            $models = self::insertModels((int) $brand->id, $catalogue['models']);
            $parts = DB::table('parts')->where('sku', self::SKU)
                ->whereNull('owner_partner_account_id')->whereNull('deleted_at')
                ->lockForUpdate()->limit(2)->get();
            if ($parts->count() > 1) {
                throw new RuntimeException('Import 90915-10001 refusé : plusieurs articles plateforme portent ce SKU.');
            }

            $part = $parts->first();
            $inserted = 0;
            if ($part !== null) {
                foreach ($catalogue['fitments'] as $fitment) {
                    $identity = [
                        'part_id' => $part->id,
                        'vehicle_model_id' => $models['ids'][$fitment['model_slug']],
                        'trim_id' => null,
                        'engine_type_id' => null,
                        'drivetrain_id' => null,
                        'engine_code' => $fitment['engine_code'],
                        'year_from' => $fitment['year_from'],
                        'year_to' => $fitment['year_to'],
                        'position' => null,
                        'source' => FitmentSource::Declared->value,
                    ];
                    // An identical shop declaration keeps its ID, note and dates.
                    if (DB::table('part_fitments')->where($identity)->exists()) {
                        continue;
                    }
                    DB::table('part_fitments')->insert([
                        ...$identity,
                        'notes' => self::notes($fitment),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $inserted++;
                }
                if ($inserted > 0) {
                    // Invalidate the public share version without touching commerce.
                    DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
                }
            }

            return ['models_inserted' => $models['inserted'], 'fitments_inserted' => $inserted, 'part_id' => $part?->id];
        });
    }

    private static function insertModels(int $brandId, array $definitions): array
    {
        $ids = [];
        $inserted = 0;
        foreach ($definitions as $definition) {
            $identity = ['brand_id' => $brandId, 'slug' => $definition['slug']];
            $existing = DB::table('vehicle_models')->where($identity)->lockForUpdate()->first();
            if ($existing !== null) {
                if (strtoupper(trim((string) $existing->generation)) !== strtoupper(trim($definition['generation']))) {
                    throw new RuntimeException('Import 90915-10001 refusé : génération incohérente pour '.$definition['slug'].'.');
                }
                $ids[$definition['slug']] = (int) $existing->id;

                continue;
            }
            $ids[$definition['slug']] = (int) DB::table('vehicle_models')->insertGetId([
                ...$identity,
                'name' => $definition['name'],
                'generation' => $definition['generation'],
                'body_type' => $definition['body_type'],
                'segment' => $definition['segment'],
                'production_start' => $definition['production_start'],
                'production_end' => $definition['production_end'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inserted++;
        }

        return ['inserted' => $inserted, 'ids' => $ids];
    }

    private static function notes(array $fitment): string
    {
        return 'Application documentée, marché Japon ('.$fitment['market'].') : '.$fitment['source_model_name']
            .', châssis '.implode('/', $fitment['chassis_codes']).', moteur '.$fitment['engine_code']
            .', période source '.$fitment['source_period'].'. Source : '.$fitment['source_url']
            .', p. '.$fitment['source_page'].' ; consultée le '.$fitment['reviewed_on']
            .'. Bornes annuelles : contrôler le VIN, le châssis et la date de fabrication aux bornes.';
    }

    private static function catalogue(): array
    {
        $data = json_decode(file_get_contents(database_path('catalogue/toyota-10001-verified-applications.json')), true, flags: JSON_THROW_ON_ERROR);
        Validator::make($data, [
            'schema_version' => ['required', 'in:1'],
            'sku' => ['required', 'in:'.self::SKU],
            'models' => ['required', 'array', 'min:1'],
            'models.*.slug' => ['required', 'string', 'distinct'],
            'models.*.name' => ['required', 'string'],
            'models.*.generation' => ['required', 'string'],
            'models.*.body_type' => ['required', 'string'],
            'models.*.segment' => ['required', 'string'],
            'models.*.production_start' => ['present', 'nullable', 'integer'],
            'models.*.production_end' => ['present', 'nullable', 'integer'],
            'fitments' => ['required', 'array', 'min:1'],
            'fitments.*.model_slug' => ['required', 'in:'.implode(',', array_column($data['models'] ?? [], 'slug'))],
            'fitments.*.engine_code' => ['required', 'string'],
            'fitments.*.year_from' => ['required', 'integer', 'between:1900,2100'],
            'fitments.*.year_to' => ['required', 'integer', 'between:1900,2100'],
            'fitments.*.market' => ['required', 'in:JP'],
            'fitments.*.source_url' => ['required', 'url'],
            'fitments.*.source_page' => ['required', 'integer', 'min:1'],
            'fitments.*.source_period' => ['required', 'string'],
            'fitments.*.chassis_codes' => ['required', 'array', 'min:1'],
            'fitments.*.chassis_codes.*' => ['required', 'string'],
            'fitments.*.source_model_name' => ['required', 'string'],
            'fitments.*.reviewed_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        foreach ($data['fitments'] as $fitment) {
            if ($fitment['year_from'] > $fitment['year_to']) {
                throw new RuntimeException('Import 90915-10001 refusé : période source inversée.');
            }
        }

        return $data;
    }
}
