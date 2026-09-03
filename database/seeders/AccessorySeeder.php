<?php

namespace Database\Seeders;

use App\Enums\AccessoryCategory;
use App\Models\Accessory;
use App\Models\AccessoryFitment;
use App\Models\Manufacturer;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;

class AccessorySeeder extends Seeder
{
    public function run(): void
    {
        $manufacturers = Manufacturer::all();
        $models = VehicleModel::all();
        $categories = AccessoryCategory::cases();

        $accessoryNames = [
            'Tapis de sol sur mesure', 'Barres de toit', 'Housse de siège',
            'Caméra de recul additionnelle', 'Support téléphone', 'Antivol volant',
            'Coffre de toit', 'Attelage remorque', 'Enjoliveurs',
        ];

        foreach ($accessoryNames as $i => $name) {
            $accessory = Accessory::create([
                'sku' => 'ACC-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => "{$name} - accessoire compatible large gamme.",
                'category' => collect($categories)->random()->value,
                'manufacturer_id' => $manufacturers->random()->id,
                'warranty_months' => 12,
                'cost_price' => random_int(5000, 80000),
                'selling_price' => random_int(8000, 120000),
                'currency' => 'XOF',
                'vat_rate' => 18.00,
                'stock_quantity' => random_int(0, 30),
                'stock_alert_threshold' => 3,
                'is_active' => true,
            ]);

            foreach ($models->random(min(2, $models->count())) as $model) {
                AccessoryFitment::create([
                    'accessory_id' => $accessory->id,
                    'vehicle_model_id' => $model->id,
                    'year_from' => 2015,
                    'year_to' => 2024,
                ]);
            }
        }
    }
}
