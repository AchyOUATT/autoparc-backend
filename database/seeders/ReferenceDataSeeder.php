<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Color;
use App\Models\Country;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Feature;
use App\Models\Manufacturer;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // ---- Countries ----
        $countries = [
            ['iso2' => 'BF', 'iso3' => 'BFA', 'name' => 'Burkina Faso', 'nationality' => 'Burkinabè', 'currency_code' => 'XOF', 'is_common_origin' => false],
            ['iso2' => 'JP', 'iso3' => 'JPN', 'name' => 'Japon', 'nationality' => 'Japonaise', 'currency_code' => 'JPY', 'is_common_origin' => true],
            ['iso2' => 'FR', 'iso3' => 'FRA', 'name' => 'France', 'nationality' => 'Française', 'currency_code' => 'EUR', 'is_common_origin' => true],
            ['iso2' => 'DE', 'iso3' => 'DEU', 'name' => 'Allemagne', 'nationality' => 'Allemande', 'currency_code' => 'EUR', 'is_common_origin' => true],
            ['iso2' => 'AE', 'iso3' => 'ARE', 'name' => 'Émirats Arabes Unis', 'nationality' => 'Émirienne', 'currency_code' => 'AED', 'is_common_origin' => true],
            ['iso2' => 'KR', 'iso3' => 'KOR', 'name' => 'Corée du Sud', 'nationality' => 'Coréenne', 'currency_code' => 'KRW', 'is_common_origin' => true],
        ];
        foreach ($countries as $c) {
            Country::create($c);
        }

        $japan = Country::where('iso2', 'JP')->first();
        $france = Country::where('iso2', 'FR')->first();
        $korea = Country::where('iso2', 'KR')->first();
        $germany = Country::where('iso2', 'DE')->first();

        // ---- Brands + Models + Trims ----
        $brandDefs = [
            ['name' => 'Toyota', 'country_id' => $japan->id, 'models' => ['Corolla', 'Hilux', 'RAV4']],
            ['name' => 'Hyundai', 'country_id' => $korea->id, 'models' => ['Tucson', 'i10', 'Elantra']],
            ['name' => 'Kia', 'country_id' => $korea->id, 'models' => ['Sportage', 'Picanto']],
            ['name' => 'Nissan', 'country_id' => $japan->id, 'models' => ['Navara', 'Qashqai']],
            ['name' => 'Peugeot', 'country_id' => $france->id, 'models' => ['208', '3008']],
            ['name' => 'Volkswagen', 'country_id' => $germany->id, 'models' => ['Golf', 'Tiguan']],
        ];

        $trimNames = ['Base', 'LE', 'SE', 'Limited', 'GLS'];

        foreach ($brandDefs as $bd) {
            $brand = Brand::create([
                'name' => $bd['name'],
                'slug' => Str::slug($bd['name']),
                'country_id' => $bd['country_id'],
                'oem_prefix' => strtoupper(substr($bd['name'], 0, 3)),
                'is_active' => true,
            ]);

            foreach ($bd['models'] as $modelName) {
                $model = VehicleModel::create([
                    'brand_id' => $brand->id,
                    'name' => $modelName,
                    'slug' => Str::slug($bd['name'].'-'.$modelName),
                    'body_type' => collect(['berline', 'SUV', 'pick-up', 'break'])->random(),
                    'segment' => collect(['B', 'C', 'D'])->random(),
                    'production_start' => 2015,
                    'production_end' => null,
                    'is_active' => true,
                ]);

                // 2 finitions par modèle
                foreach (array_slice($trimNames, 0, 2) as $i => $trimName) {
                    Trim::create([
                        'vehicle_model_id' => $model->id,
                        'name' => $trimName,
                        'code' => strtoupper(Str::random(3)),
                        'rank' => $i,
                    ]);
                }
            }
        }

        // ---- Drivetrains ----
        foreach ([
            ['code' => 'FWD', 'label' => 'Traction avant'],
            ['code' => 'RWD', 'label' => 'Propulsion'],
            ['code' => 'AWD', 'label' => 'Intégrale permanente'],
            ['code' => '4WD', 'label' => '4x4 enclenchable'],
        ] as $d) {
            Drivetrain::create($d);
        }

        // ---- Engine types ----
        foreach ([
            ['code' => 'petrol', 'label' => 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
            ['code' => 'diesel', 'label' => 'Diesel', 'uses_fuel' => true, 'uses_battery' => false],
            ['code' => 'hybrid', 'label' => 'Hybride', 'uses_fuel' => true, 'uses_battery' => true],
            ['code' => 'electric', 'label' => 'Électrique', 'uses_fuel' => false, 'uses_battery' => true],
        ] as $e) {
            EngineType::create($e);
        }

        // ---- Colors ----
        foreach ([
            ['name' => 'Blanc', 'hex_code' => '#FFFFFF', 'finish' => 'opaque'],
            ['name' => 'Noir', 'hex_code' => '#000000', 'finish' => 'metallise'],
            ['name' => 'Gris', 'hex_code' => '#808080', 'finish' => 'metallise'],
            ['name' => 'Rouge', 'hex_code' => '#C0392B', 'finish' => 'opaque'],
            ['name' => 'Bleu', 'hex_code' => '#2980B9', 'finish' => 'metallise'],
            ['name' => 'Argent', 'hex_code' => '#C0C0C0', 'finish' => 'metallise'],
        ] as $c) {
            Color::create($c);
        }

        // ---- Features ----
        foreach ([
            ['name' => 'Climatisation', 'category' => 'confort'],
            ['name' => 'Caméra de recul', 'category' => 'securite'],
            ['name' => 'Bluetooth', 'category' => 'multimedia'],
            ['name' => 'Sièges cuir', 'category' => 'confort'],
            ['name' => 'Toit ouvrant', 'category' => 'confort'],
            ['name' => 'Régulateur de vitesse', 'category' => 'securite'],
            ['name' => 'Navigation GPS', 'category' => 'multimedia'],
        ] as $f) {
            Feature::create($f);
        }

        // ---- Part categories ----
        $freinage = PartCategory::create(['name' => 'Freinage', 'slug' => 'freinage']);
        PartCategory::create(['parent_id' => $freinage->id, 'name' => 'Plaquettes', 'slug' => 'plaquettes']);
        PartCategory::create(['parent_id' => $freinage->id, 'name' => 'Disques', 'slug' => 'disques']);

        $moteur = PartCategory::create(['name' => 'Moteur', 'slug' => 'moteur']);
        PartCategory::create(['parent_id' => $moteur->id, 'name' => 'Filtres', 'slug' => 'filtres']);
        PartCategory::create(['parent_id' => $moteur->id, 'name' => 'Courroies', 'slug' => 'courroies']);

        PartCategory::create(['name' => 'Suspension', 'slug' => 'suspension']);
        PartCategory::create(['name' => 'Électrique', 'slug' => 'electrique']);
        PartCategory::create(['name' => 'Carrosserie', 'slug' => 'carrosserie']);

        // ---- Manufacturers (équipementiers) ----
        foreach ([
            ['name' => 'Bosch', 'country_id' => $germany->id, 'is_oem_supplier' => true],
            ['name' => 'Denso', 'country_id' => $japan->id, 'is_oem_supplier' => true],
            ['name' => 'Valeo', 'country_id' => $france->id, 'is_oem_supplier' => true],
            ['name' => 'NGK', 'country_id' => $japan->id, 'is_oem_supplier' => false],
            ['name' => 'Brembo', 'country_id' => null, 'is_oem_supplier' => false],
        ] as $m) {
            Manufacturer::create([
                'name' => $m['name'],
                'slug' => Str::slug($m['name']),
                'country_id' => $m['country_id'],
                'is_oem_supplier' => $m['is_oem_supplier'],
            ]);
        }
    }
}
