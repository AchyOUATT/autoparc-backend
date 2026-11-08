<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/** Additive, targeted catalogue import. No engines or filter fitments are inferred. */
class SinotrukCatalogSeeder extends Seeder
{
    public const MODEL_NAMES = [
        'HOWO A7', 'HOWO T7H', 'HOWO T5G', 'HOWO TX', 'HOWO NX', 'HOWO 7',
        'SITRAK C7H', 'SITRAK C9H', 'SITRAK G7', 'SITRAK G7S',
        'HOHAN E7', 'HOHAN N7',
    ];

    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            // Resolve the country by code, never by an environment-specific ID.
            // Fresh migrations run before CountriesSeeder.
            $country = DB::table('countries')->where('iso2', 'CN')->first();
            $countryId = $country?->id ?? DB::table('countries')->insertGetId([
                'iso2' => 'CN', 'iso3' => 'CHN', 'name' => 'Chine',
                'created_at' => $now, 'updated_at' => $now,
            ]);

            $brands = DB::table('brands')->where('slug', 'sinotruk')
                ->orWhere('name', 'Sinotruk')->get();
            if ($brands->count() > 1) {
                throw new RuntimeException('Ambiguous Sinotruk brand: reconcile name and slug before importing.');
            }
            $brandId = $brands->first()?->id ?? DB::table('brands')->insertGetId([
                'name' => 'Sinotruk', 'slug' => 'sinotruk',
                'country_id' => $countryId, 'oem_prefix' => 'ZZ', 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);

            foreach (self::MODEL_NAMES as $name) {
                $slug = Str::slug($name);
                // Preserve even inactive/customised rows, including their timestamps.
                if (DB::table('vehicle_models')->where('brand_id', $brandId)
                    ->where(fn ($query) => $query->where('slug', $slug)->orWhere('name', $name))
                    ->exists()) {
                    continue;
                }

                DB::table('vehicle_models')->insert([
                    'brand_id' => $brandId, 'name' => $name, 'slug' => $slug,
                    'generation' => null, 'body_type' => 'camion', 'segment' => null,
                    'production_start' => null, 'production_end' => null,
                    'default_vehicle_type' => 'heavy', 'is_active' => true,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        });
    }
}
