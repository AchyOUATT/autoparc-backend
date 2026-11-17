<?php

use App\Support\ReferenceCatalogWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les Mazda 2, 3 et 6 figuraient au catalogue sous le nom colle (Mazda2,
 * Mazda3, Mazda6). Le selecteur de l'application affiche ce nom tel quel :
 * la forme usuelle avec espace n'y figurait pas. Cette migration renomme les
 * fiches existantes, pose les alias (ecriture collee et noms japonais Demio,
 * Axela, Atenza), ajoute la generation BK de la Mazda 3, et laisse les slugs
 * et les identifiants inchanges pour ne pas casser les vehicules rattaches.
 */
return new class extends Migration
{
    /**
     * @var array<string, array{name: string, aliases: list<string>, create?: array<string, mixed>}>
     */
    private const FICHES = [
        'mazda2-dj' => [
            'name' => 'Mazda 2',
            'aliases' => ['Mazda2', 'Demio'],
        ],
        'mazda3-bk' => [
            'name' => 'Mazda 3',
            'aliases' => ['Mazda3', '3', 'Axela'],
            // Meme carrosserie et segment que BL, BM et BP. La fin 2008 est
            // l'annee ou la BL commence deja dans ce catalogue.
            'create' => [
                'generation' => 'BK',
                'body_type' => 'compacte',
                'segment' => 'C',
                'production_start' => 2003,
                'production_end' => 2008,
                'is_active' => true,
            ],
        ],
        'mazda3-bl' => [
            'name' => 'Mazda 3',
            'aliases' => ['Mazda3', '3', 'Axela'],
        ],
        'mazda3-bm' => [
            'name' => 'Mazda 3',
            'aliases' => ['Mazda3', '3', 'Axela'],
        ],
        'mazda3-bp' => [
            'name' => 'Mazda 3',
            'aliases' => ['Mazda3', '3', 'Axela'],
        ],
        'mazda6-gg' => [
            'name' => 'Mazda 6',
            'aliases' => ['Mazda6', 'Atenza'],
        ],
        'mazda6-gh' => [
            'name' => 'Mazda 6',
            'aliases' => ['Mazda6', 'Atenza'],
        ],
        'mazda6-gj' => [
            'name' => 'Mazda 6',
            'aliases' => ['Mazda6', 'Atenza'],
        ],
    ];

    public function up(): void
    {
        $marque = DB::table('brands')->where('slug', 'mazda')->first();
        if ($marque === null) {
            return;
        }

        $existantes = DB::table('vehicle_models')
            ->where('brand_id', $marque->id)
            ->whereIn('slug', array_keys(self::FICHES))
            ->get()
            ->keyBy('slug');

        foreach (self::FICHES as $slug => $definition) {
            $fiche = $existantes->get($slug);
            if ($fiche === null && ! isset($definition['create'])) {
                continue;
            }

            $attributes = [
                'name' => $definition['name'],
                'aliases' => json_encode(
                    $this->fusionnerAlias($fiche?->aliases, $definition['aliases']),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ),
            ];
            if ($fiche === null) {
                $attributes = array_merge($definition['create'], $attributes);
            }

            ReferenceCatalogWriter::upsert('vehicle_models', [
                'brand_id' => $marque->id,
                'slug' => $slug,
            ], $attributes);
        }
    }

    public function down(): void
    {
        // Non destructif : le nom et les alias peuvent avoir ete repris par le
        // seeder ou corriges ensuite. Les retirer exigerait une revue explicite.
    }

    /**
     * @param  list<string>  $attendus
     * @return list<string>
     */
    private function fusionnerAlias(mixed $stockes, array $attendus): array
    {
        $existants = [];
        if (is_string($stockes) && $stockes !== '') {
            $decoded = json_decode($stockes, true);
            $existants = is_array($decoded) ? $decoded : [];
        } elseif (is_array($stockes)) {
            $existants = $stockes;
        }

        $vus = [];
        $fusion = [];
        foreach ([...$existants, ...$attendus] as $alias) {
            $alias = trim((string) $alias);
            $cle = strtolower($alias);
            if ($alias === '' || isset($vus[$cle]) || in_array($cle, ['mazda 2', 'mazda 3', 'mazda 6'], true)) {
                continue;
            }
            $vus[$cle] = true;
            $fusion[] = $alias;
        }

        return $fusion;
    }
};
