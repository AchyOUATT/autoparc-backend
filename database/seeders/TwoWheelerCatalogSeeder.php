<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Premiere vague de motos routieres, issues de pages constructeur officielles.
 *
 * Priorite (demande du proprietaire) : motos routieres / de route courantes
 * en Afrique de l'Ouest. Les scooters, cyclomoteurs, trails, modeles agricoles
 * et tricycles ne sont pas semes ici, meme lorsqu'ils apparaissent au catalogue
 * officiel. Les types de caisse scooter/cyclomoteur restent disponibles a la
 * saisie, sans ligne de referentiel dans ce lot.
 *
 * Sources (consultees le 13 novembre 2026) :
 * - Yamaha Motor : https://global.yamaha-motor.com/business/omdo/products/
 *   et fiche YBR125E https://global.yamaha-motor.com/business/omdo/products/ybr125e/
 *   (CFAO Motors Burkina Faso figure dans le reseau Yamaha Afrique :
 *   https://global.yamaha-motor.com/business/mc/sales-network/africa/)
 * - Honda Global Newsroom Afrique / Nigeria :
 *   CG110 https://global.honda/en/newsroom/news/2013/2131210eng.html
 *   Ace CB125 et Ace CB125-D https://global.honda/en/newsroom/news/2011/c110929eng.html
 *   Ace110 https://global.honda/en/newsroom/news/2017/2171120eng.html
 * - APSONIC / Guangzhou Sincerity Motorcycle Industry :
 *   https://en.sincerity.com/html/Motorcycles/
 *   (filiale burkinabe listee sur https://www.apsonic-moto.com/)
 * - TVS Motor, catalogue Nigeria (Afrique de l'Ouest, pas de page BF) :
 *   https://www.tvsmotor.com/en/ng
 *
 * Idempotent : les lignes existantes (slug ou nom) sont laissees intactes.
 */
class TwoWheelerCatalogSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $now = now();
            $countryIds = DB::table('countries')->whereIn('iso2', ['JP', 'CN', 'IN'])->pluck('id', 'iso2');

            $yamahaId = $this->brand('Yamaha', 'yamaha', 'JP', $countryIds['JP'] ?? null, $now);
            $hondaId = $this->existingBrand('Honda', 'honda');
            $apsonicId = $this->brand('Apsonic', 'apsonic', 'CN', $countryIds['CN'] ?? null, $now);
            $tvsId = $this->brand('TVS', 'tvs', 'IN', $countryIds['IN'] ?? null, $now);

            foreach ($this->models() as $row) {
                $brandId = match ($row['brand']) {
                    'yamaha' => $yamahaId,
                    'honda' => $hondaId,
                    'apsonic' => $apsonicId,
                    'tvs' => $tvsId,
                    default => throw new RuntimeException('Marque inconnue : '.$row['brand']),
                };
                $slug = $row['slug'];
                $name = $row['name'];
                if (DB::table('vehicle_models')->where('brand_id', $brandId)
                    ->where(fn ($query) => $query->where('slug', $slug)->orWhere('name', $name))
                    ->exists()) {
                    continue;
                }

                DB::table('vehicle_models')->insert([
                    'brand_id' => $brandId,
                    'name' => $name,
                    'slug' => $slug,
                    'generation' => null,
                    'body_type' => null,
                    'segment' => null,
                    'production_start' => null,
                    'production_end' => null,
                    'default_vehicle_type' => 'two_wheeler',
                    'default_power_hp' => $row['default_power_hp'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    /**
     * @return list<array{brand: string, name: string, slug: string, default_power_hp: int|null}>
     */
    private function models(): array
    {
        return [
            // Yamaha YBR125E : moto routiere 124 cm3, 10,1 PS (fiche officielle).
            ['brand' => 'yamaha', 'name' => 'YBR125E', 'slug' => 'ybr125e', 'default_power_hp' => 10],
            // Honda CG110 / Ace : classes 110 et 125 cm3 annoncees pour le Nigeria.
            // La cylindree exacte n'est pas un entier unique dans le communique ; on ne l'invente pas.
            ['brand' => 'honda', 'name' => 'CG110', 'slug' => 'cg110', 'default_power_hp' => null],
            ['brand' => 'honda', 'name' => 'Ace CB125', 'slug' => 'ace-cb125', 'default_power_hp' => null],
            ['brand' => 'honda', 'name' => 'Ace CB125-D', 'slug' => 'ace-cb125-d', 'default_power_hp' => null],
            ['brand' => 'honda', 'name' => 'Ace110', 'slug' => 'ace110', 'default_power_hp' => null],
            // APSONIC ALOBA et Z-ONE : motos de route nommees sur le catalogue officiel.
            // Pas de cylindree officielle sur la page de synthese : on ne l'invente pas.
            ['brand' => 'apsonic', 'name' => 'ALOBA', 'slug' => 'aloba', 'default_power_hp' => null],
            ['brand' => 'apsonic', 'name' => 'Z-ONE', 'slug' => 'z-one', 'default_power_hp' => null],
            // TVS HLX / Rockz : motos routieres du catalogue officiel Nigeria.
            ['brand' => 'tvs', 'name' => 'HLX 125', 'slug' => 'hlx-125', 'default_power_hp' => null],
            ['brand' => 'tvs', 'name' => 'HLX Plus', 'slug' => 'hlx-plus', 'default_power_hp' => null],
            ['brand' => 'tvs', 'name' => 'Rockz', 'slug' => 'rockz', 'default_power_hp' => null],
        ];
    }

    private function brand(string $name, string $slug, string $iso2, ?int $countryId, $now): int
    {
        $rows = DB::table('brands')->where('slug', $slug)->orWhere('name', $name)->get();
        if ($rows->count() > 1) {
            throw new RuntimeException("Marque ambigue $name ($slug) : reconcilier avant d'importer.");
        }
        if ($rows->first()) {
            return (int) $rows->first()->id;
        }

        return (int) DB::table('brands')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'country_id' => $countryId,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function existingBrand(string $name, string $slug): int
    {
        $rows = DB::table('brands')->where('slug', $slug)->orWhere('name', $name)->get();
        if ($rows->count() !== 1) {
            throw new RuntimeException(
                "Marque Honda introuvable ou ambigue ($slug). Elle doit exister avant ce seeder."
            );
        }

        return (int) $rows->first()->id;
    }
}
