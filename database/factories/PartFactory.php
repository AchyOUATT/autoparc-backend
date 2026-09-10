<?php

namespace Database\Factories;

use App\Enums\PartCondition;
use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Support\PartTaxonomy;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PartFactory extends Factory
{
    protected $model = Part::class;

    // Noms de pièces par famille — pour générer des noms réalistes
    private array $partNames = [
        'Filtre à huile',
        'Filtre à air',
        'Filtre habitacle',
        'Filtre carburant',
        'Bougie d\'allumage',
        'Kit de distribution',
        'Courroie de distribution',
        'Plaquettes de frein avant',
        'Plaquettes de frein arrière',
        'Disque de frein avant',
        'Disque de frein arrière',
        'Amortisseur avant gauche',
        'Amortisseur avant droit',
        'Amortisseur arrière gauche',
        'Amortisseur arrière droit',
        'Rotule de suspension',
        'Roulement de roue avant',
        'Roulement de roue arrière',
        'Biellette de barre stab.',
        'Soufflet de cardan',
        'Pompe à eau',
        'Thermostat moteur',
        'Radiateur de refroidissement',
        'Sonde lambda',
        'Capteur ABS',
        'Alternateur',
        'Démarreur',
        'Batterie 12V 60Ah',
        'Batterie 12V 70Ah',
        'Batterie 12V 75Ah',
        'Bobine d\'allumage',
        'Injecteur',
        'Kit embrayage',
        'Volant moteur bi-masse',
        'Joint de culasse',
        'Courroie accessoires',
        'Galet tendeur',
        'Pompe de direction assistée',
        'Silent-bloc de berceau',
        'Bras de suspension',
        'Crémaillère de direction',
        'Kit roulement moyeu',
        'Pare-brise',
        'Vitre latérale avant gauche',
        'Optique avant gauche',
        'Optique avant droit',
        'Feu arrière gauche',
        'Feu arrière droit',
        'Catalyseur',
        'Silencieux arrière',
    ];

    public function definition(): array
    {
        // Le nom decide de la categorie. Les deux etaient tires
        // independamment : « Alternateur » se retrouvait range dans
        // « Flexibles de frein », et filtrer par famille ne renvoyait rien de
        // coherent — le defaut sautait aux yeux des la premiere capture.
        $name         = $this->faker->randomElement($this->partNames);
        $category     = $this->categoryFor($name);
        $manufacturer = Manufacturer::inRandomOrder()->first();
        $type         = $this->faker->randomElement(['aftermarket', 'aftermarket', 'oes', 'oem', 'salvage']);
        $condition    = $type === 'salvage'
            ? PartCondition::Used->value
            : $this->faker->randomElement(['new', 'new', 'new', 'refurbished']);

        $stock        = $this->faker->numberBetween(0, 50);
        $costPrice    = $this->faker->randomElement([2_000, 3_500, 5_000, 7_500, 10_000, 15_000, 25_000, 40_000, 60_000, 100_000]);
        $sellingPrice = (int) ($costPrice * $this->faker->randomFloat(2, 1.25, 2.0));
        // Arrondi au 500 le plus proche
        $sellingPrice = (int) (round($sellingPrice / 500) * 500);

        return [
            'sku'                   => 'PRT-' . strtoupper(Str::random(8)),
            'name'                  => $name,
            'description'           => null,
            'part_category_id'      => $category->id,
            'manufacturer_id'       => $manufacturer?->id,
            'manufacturer_reference'=> $this->faker->optional(0.6)->regexify('[A-Z]{2}[0-9]{5,8}'),
            'type'                  => $type,
            'condition'             => $condition,
            'donor_vehicle_id'      => null,
            'weight_kg'             => $this->faker->optional(0.5)->randomFloat(2, 0.1, 15.0),
            'dimensions'            => null,
            'warranty_months'       => $condition === 'new'
                ? $this->faker->randomElement([3, 6, 12, 24])
                : null,
            'cost_price'            => $costPrice,
            'selling_price'         => $sellingPrice,
            'currency'              => 'XOF',
            'vat_rate'              => 18.00,
            'stock_quantity'        => $stock,
            'stock_alert_threshold' => $this->faker->numberBetween(1, 5),
            'storage_location'      => $this->faker->optional(0.7)->regexify('[A-E][1-9]-[0-9]{2}'),
            'is_active'             => true,
        ];
    }

    /**
     * Categorie attendue pour ce nom, avec repli aleatoire.
     *
     * Le repli ne devrait jamais servir : il couvre le cas ou un nom serait
     * ajoute a $partNames sans etre declare dans PartTaxonomy.
     */
    private function categoryFor(string $name): PartCategory
    {
        $slug = PartTaxonomy::slugFor($name);

        $category = $slug !== null
            ? PartCategory::where('slug', $slug)->first()
            : null;

        return $category
            ?? PartCategory::whereNotNull('parent_id')->inRandomOrder()->first()
            ?? PartCategory::inRandomOrder()->firstOrFail();
    }

    public function inStock(): static
    {
        return $this->state(['stock_quantity' => $this->faker->numberBetween(5, 30)]);
    }

    public function outOfStock(): static
    {
        return $this->state(['stock_quantity' => 0]);
    }

    public function oem(): static
    {
        return $this->state(['type' => PartType::Oem->value, 'condition' => PartCondition::New->value]);
    }
}
