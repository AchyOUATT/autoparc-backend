<?php

namespace Database\Seeders;

use App\Enums\PartCondition;
use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Support\PartTaxonomy;
use Illuminate\Database\Seeder;

class PartSeeder extends Seeder
{
    public function run(): void
    {
        // Le nom decide de la categorie : un tirage independant rangeait
        // « Alternateur » dans « Flexibles de frein ». Voir PartTaxonomy.
        $slugToId = PartTaxonomy::slugToId();
        $fallback = PartCategory::whereNotNull('parent_id')->get();
        $manufacturers = Manufacturer::all();
        $models = VehicleModel::all();

        $partNames = [
            'Plaquettes de frein avant', 'Disque de frein avant', 'Filtre à huile',
            'Filtre à air', 'Courroie de distribution', 'Amortisseur avant',
            'Bougie d\'allumage', 'Alternateur', 'Batterie 60Ah', 'Radiateur',
            'Pompe à eau', 'Rotule de direction',
        ];

        foreach ($partNames as $i => $name) {
            $part = Part::create([
                'sku' => 'PRT-'.str_pad((string) ($i + 1), 5, '0', STR_PAD_LEFT),
                'name' => $name,
                'description' => "{$name} - pièce de rechange standard.",
                'part_category_id' => $slugToId[PartTaxonomy::slugFor($name)] ?? $fallback->random()->id,
                'manufacturer_id' => $manufacturers->random()->id,
                'manufacturer_reference' => strtoupper(substr(md5($name), 0, 10)),
                'type' => collect(PartType::cases())->random()->value,
                'condition' => collect(PartCondition::cases())->random()->value,
                'weight_kg' => round(random_int(1, 150) / 10, 2),
                'warranty_months' => collect([6, 12, 24])->random(),
                'cost_price' => random_int(3000, 60000),
                'selling_price' => random_int(5000, 90000),
                'currency' => 'XOF',
                'vat_rate' => 18.00,
                'stock_quantity' => random_int(0, 50),
                'stock_alert_threshold' => 5,
                'storage_location' => 'A'.random_int(1, 9).'-B'.random_int(1, 20),
                'is_active' => true,
            ]);

            // Numéro OEM associé
            $oem = OemNumber::create([
                'number' => strtoupper(substr(md5($name.'oem'), 0, 5)).'-'.strtoupper(substr(md5($name), 5, 4)),
                'normalized_number' => strtoupper(substr(md5($name.'oemnorm'), 0, 9)),
                'label' => $name,
                'is_superseded' => false,
            ]);
            $part->oemNumbers()->attach($oem->id, ['is_primary' => true]);

            // Compatibilité directe piece -> modèle(s), sur 1 à 3 modèles au hasard
            foreach ($models->random(min(3, $models->count())) as $model) {
                PartFitment::create([
                    'part_id' => $part->id,
                    'vehicle_model_id' => $model->id,
                    'year_from' => 2015,
                    'year_to' => 2024,
                    'position' => collect(['avant gauche', 'avant droit', 'arrière', null])->random(),
                ]);
            }
        }
    }
}
