<?php

namespace Database\Factories;

use App\Enums\AccessoryCategory;
use App\Models\Accessory;
use App\Models\Manufacturer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AccessoryFactory extends Factory
{
    protected $model = Accessory::class;

    private array $accessoryNames = [
        // Esthétique
        AccessoryCategory::Esthetique->value => [
            'Jantes aluminium 16"',
            'Jantes aluminium 17"',
            'Jantes aluminium 18"',
            'Spoiler arrière',
            'Baguettes de portières chromées',
            'Protège-seuils de porte inox',
            'Cache moteur plastique',
            'Kit carrosserie sport',
            'Film teinté vitres',
            'Antenne aileron requin',
        ],
        // Confort
        AccessoryCategory::Confort->value => [
            'Tapis de sol caoutchouc',
            'Tapis de sol velours',
            'Housses de siège universelles',
            'Coussin lombaire conducteur',
            'Organisateur de coffre',
            'Parasoleil pare-brise',
            'Porte-gobelet universel',
            'Repose-bras central universel',
            'Rideau pare-soleil arrière',
            'Volant cuir gainé',
        ],
        // Sécurité
        AccessoryCategory::Securite->value => [
            'Caméra de recul universelle',
            'Radar de stationnement',
            'Dashcam Full HD',
            'Dashcam 4K double objectif',
            'Antivol de direction',
            'Antivol pédale',
            'Kit triangle de sécurité + gilet',
            'Extincteur 1kg homologué',
            'Cale de roue',
            'Câbles de démarrage',
        ],
        // Multimédia
        AccessoryCategory::Multimedia->value => [
            'Autoradio 2 DIN Android',
            'Autoradio 1 DIN Bluetooth',
            'Chargeur téléphone à induction',
            'Adaptateur Carplay/Android Auto',
            'Caisson de basse 12"',
            'Amplificateur 4 canaux',
            'Support téléphone grille aération',
            'Support téléphone magnétique',
            'Câble AUX / USB vers allume-cigare',
            'Kit mains-libres Bluetooth',
        ],
        // Utilitaire
        AccessoryCategory::Utilitaire->value => [
            'Barres de toit universelles',
            'Coffre de toit 350L',
            'Porte-vélos attelage (2 vélos)',
            'Remorque porte-voiture légère',
            'Galerie de toit pick-up',
            'Bâche pick-up couvre-benne',
            'Marchepied latéral acier',
            'Crochet de remorquage',
            'Compresseur air portable 12V',
            'Câble de remorquage 5T',
        ],
    ];

    public function definition(): array
    {
        $category = $this->faker->randomElement(AccessoryCategory::values());
        $name     = $this->faker->randomElement(
            $this->accessoryNames[$category] ?? ['Accessoire universel']
        );

        $manufacturer = Manufacturer::inRandomOrder()->first();
        $costPrice    = $this->faker->randomElement([
            5_000, 10_000, 15_000, 20_000, 30_000, 45_000, 60_000, 80_000, 100_000, 150_000, 200_000
        ]);
        $sellingPrice = (int) (round($costPrice * $this->faker->randomFloat(2, 1.3, 2.2) / 500) * 500);
        $stock        = $this->faker->numberBetween(0, 30);

        return [
            'sku'                   => 'ACC-' . strtoupper(Str::random(7)),
            'name'                  => $name,
            'description'           => null,
            'category'              => $category,
            'manufacturer_id'       => $manufacturer?->id,
            'weight_kg'             => $this->faker->optional(0.5)->randomFloat(2, 0.2, 25.0),
            'dimensions'            => null,
            'warranty_months'       => $this->faker->randomElement([null, null, 6, 12, 24]),
            'cost_price'            => $costPrice,
            'selling_price'         => $sellingPrice,
            'currency'              => 'XOF',
            'vat_rate'              => 18.00,
            'stock_quantity'        => $stock,
            'stock_alert_threshold' => $this->faker->numberBetween(1, 5),
            'storage_location'      => $this->faker->optional(0.6)->regexify('[A-D][1-9]-[0-9]{2}'),
            'is_active'             => true,
        ];
    }

    public function inStock(): static
    {
        return $this->state(['stock_quantity' => $this->faker->numberBetween(5, 25)]);
    }
}
