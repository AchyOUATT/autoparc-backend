<?php

use App\Support\ReferenceCatalogWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Le modele existait deja sous le nom colle « Mazda3 » (generations BL, BM, BP).
 * Le formulaire de l'application affiche ce nom tel quel : la forme usuelle
 * « Mazda 3 » n'y figurait pas. Cette migration renomme les trois fiches
 * existantes, pose les alias « Mazda3 » et « 3 », et laisse les slugs et les
 * identifiants inchanges pour ne pas casser les vehicules deja rattaches.
 */
return new class extends Migration
{
    private const SLUGS = ['mazda3-bl', 'mazda3-bm', 'mazda3-bp'];

    private const ALIAS = ['Mazda3', '3'];

    public function up(): void
    {
        $marque = DB::table('brands')->where('slug', 'mazda')->first();
        if ($marque === null) {
            return;
        }

        $fiches = DB::table('vehicle_models')
            ->where('brand_id', $marque->id)
            ->whereIn('slug', self::SLUGS)
            ->orderBy('id')
            ->get();

        foreach ($fiches as $fiche) {
            $aliases = $this->fusionnerAlias($fiche->aliases);

            ReferenceCatalogWriter::upsert('vehicle_models', [
                'brand_id' => $marque->id,
                'slug' => $fiche->slug,
            ], [
                'name' => 'Mazda 3',
                'aliases' => json_encode($aliases, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    public function down(): void
    {
        // Non destructif : le nom et les alias peuvent avoir ete repris par le
        // seeder ou corriges ensuite. Les retirer exigerait une revue explicite.
    }

    /**
     * @return list<string>
     */
    private function fusionnerAlias(mixed $stockes): array
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
        foreach ([...$existants, ...self::ALIAS] as $alias) {
            $alias = trim((string) $alias);
            if ($alias === '' || strcasecmp($alias, 'Mazda 3') === 0) {
                continue;
            }
            $cle = strtolower($alias);
            if (isset($vus[$cle])) {
                continue;
            }
            $vus[$cle] = true;
            $fusion[] = $alias;
        }

        return $fusion;
    }
};
