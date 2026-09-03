<?php

namespace App\Console\Commands;

use App\Models\Brand;
use App\Models\VehicleModel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Peuple brands + vehicle_models via l'API NHTSA vPIC (gratuite, sans cle).
 *
 * Limite connue et assumee : NHTSA ne fournit ni finitions, ni motorisation,
 * ni consommation. Ca reste a la charge du staff (saisie plus legere : une
 * finition par modele existant, pas tout le catalogue).
 *
 * Usage :
 *   php artisan catalog:import-nhtsa                  -> marques par defaut (marche Afrique de l'Ouest)
 *   php artisan catalog:import-nhtsa --make=Peugeot    -> une seule marque
 */
class ImportNhtsaCatalog extends Command
{
    protected $signature = 'catalog:import-nhtsa {--make= : N\'importer qu\'une seule marque}';

    protected $description = 'Importe marques et modeles depuis l\'API NHTSA vPIC (gratuite)';

    /** Marques courantes sur le marche de l'occasion importee en Afrique de l'Ouest. */
    protected array $defaultMakes = [
        'Toyota', 'Hyundai', 'Kia', 'Nissan', 'Mazda', 'Honda',
        'Mitsubishi', 'Suzuki', 'Peugeot', 'Mercedes-Benz', 'Ford', 'Volkswagen',
    ];

    public function handle(): int
    {
        $makes = $this->option('make') ? [$this->option('make')] : $this->defaultMakes;

        foreach ($makes as $makeName) {
            $this->importMake($makeName);
        }

        return self::SUCCESS;
    }

    protected function importMake(string $makeName): void
    {
        $this->info("Import : {$makeName}...");

        $response = Http::timeout(15)->get(
            "https://vpic.nhtsa.dot.gov/api/vehicles/getmodelsformake/{$makeName}",
            ['format' => 'json']
        );

        if (! $response->ok()) {
            $this->warn("  Echec de l'appel NHTSA pour {$makeName} (HTTP {$response->status()}) - ignore.");

            return;
        }

        $results = $response->json('Results', []);

        if (empty($results)) {
            $this->warn("  Aucun modele retourne par NHTSA pour {$makeName}.");

            return;
        }

        $brand = Brand::updateOrCreate(
            ['name' => $makeName],
            ['slug' => Str::slug($makeName), 'is_active' => true]
        );

        // NHTSA peut renvoyer plusieurs entrees pour un meme nom de modele
        // (variantes internes) : on ne garde qu'une occurrence par nom.
        $modelNames = collect($results)->pluck('Model_Name')->unique()->filter();

        $created = 0;

        foreach ($modelNames as $modelName) {
            VehicleModel::updateOrCreate(
                ['brand_id' => $brand->id, 'slug' => Str::slug($modelName)],
                ['name' => $modelName, 'is_active' => true]
            );
            $created++;
        }

        $this->info("  {$created} modele(s) synchronise(s) pour {$makeName}.");

        // Courtoisie envers l'API publique : evite d'enchainer les appels trop vite.
        usleep(300_000);
    }
}
