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

        // ── CET IMPORT NE RESSUSCITE RIEN ────────────────────────────────
        //
        // `updateOrCreate(..., ['is_active' => true])` reposait le drapeau a
        // VRAI sur CHAQUE ligne rencontree, existante comprise. Un seul
        // passage aurait donc reactive, d'un coup et sans un mot, tout ce que
        // le referentiel avait desactive a la main — notamment les lignes nues
        // `tacoma` et `venza`, remplacees par leurs generations le 23/10/2026.
        // Le selecteur se serait remis a proposer les doublons que le filtre
        // de `BrandController::models()` venait d'eteindre, et rien n'aurait
        // relie la cause a l'effet : l'import ne dit pas ce qu'il reactive.
        //
        // NHTSA dit ce qui EXISTE chez le constructeur. Il ne decide pas de ce
        // que notre referentiel PROPOSE. Le drapeau n'est donc pose qu'a la
        // creation ; sur une ligne existante, il n'est jamais touche.
        $brand = Brand::firstOrNew(['name' => $makeName]);

        if (! $brand->exists) {
            $brand->is_active = true;
        }

        $brand->slug = Str::slug($makeName);
        $brand->save();

        // NHTSA peut renvoyer plusieurs entrees pour un meme nom de modele
        // (variantes internes) : on ne garde qu'une occurrence par nom.
        $modelNames = collect($results)->pluck('Model_Name')->unique()->filter();

        $created = 0;

        foreach ($modelNames as $modelName) {
            // Meme regle que pour la marque ci-dessus : le drapeau n'est pose
            // qu'a la creation. C'est ICI que le defaut mordait le plus fort,
            // puisque c'est au niveau du modele que la desactivation sert.
            $modele = VehicleModel::firstOrNew([
                'brand_id' => $brand->id,
                'slug'     => Str::slug($modelName),
            ]);

            if (! $modele->exists) {
                $modele->is_active = true;
            }

            $modele->name = $modelName;
            $modele->save();
            $created++;
        }

        $this->info("  {$created} modele(s) synchronise(s) pour {$makeName}.");

        // Courtoisie envers l'API publique : evite d'enchainer les appels trop vite.
        usleep(300_000);
    }
}
