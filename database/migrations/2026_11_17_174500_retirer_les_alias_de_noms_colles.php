<?php

use App\Support\ReferenceCatalogWriter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Les alias Mazda2, Mazda3, Mazda6 et « 3 » apparaissaient dans le
 * selecteur (« Mazda 3 / Mazda3 / 3 »). Ils sont retires. Les noms de
 * marche japonais Demio, Axela et Atenza restent. Le libelle
 * « Mazda5 / Premacy » prend la meme espace que Mazda 2, 3 et 6. Le
 * decodage VIN rapproche encore les formes collees en normalisant les
 * espaces, sans ces alias.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const ALIAS_COLLES = [
        'mazda2-dj' => ['Mazda2'],
        'mazda3-bk' => ['Mazda3', '3'],
        'mazda3-bl' => ['Mazda3', '3'],
        'mazda3-bm' => ['Mazda3', '3'],
        'mazda3-bp' => ['Mazda3', '3'],
        'mazda6-gg' => ['Mazda6'],
        'mazda6-gh' => ['Mazda6'],
        'mazda6-gj' => ['Mazda6'],
    ];

    /** @var array<string, string> */
    private const NOMS = [
        'mazda5-premacy-cp' => 'Mazda 5 / Premacy',
        'mazda5-premacy-cr' => 'Mazda 5 / Premacy',
        'mazda5-premacy-cw' => 'Mazda 5 / Premacy',
    ];

    public function up(): void
    {
        $marque = DB::table('brands')->where('slug', 'mazda')->first();
        if ($marque === null) {
            return;
        }

        $slugs = array_values(array_unique([
            ...array_keys(self::ALIAS_COLLES),
            ...array_keys(self::NOMS),
        ]));

        $fiches = DB::table('vehicle_models')
            ->where('brand_id', $marque->id)
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        foreach ($fiches as $slug => $fiche) {
            $attributes = [];

            if (isset(self::NOMS[$slug]) && $fiche->name !== self::NOMS[$slug]) {
                $attributes['name'] = self::NOMS[$slug];
            }

            if (isset(self::ALIAS_COLLES[$slug])) {
                $restants = $this->sansAliasColles($fiche->aliases, self::ALIAS_COLLES[$slug]);
                $actuels = $this->decoderAlias($fiche->aliases);
                if ($restants !== $actuels) {
                    $attributes['aliases'] = json_encode($restants, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                }
            }

            if ($attributes === []) {
                continue;
            }

            ReferenceCatalogWriter::upsert('vehicle_models', [
                'brand_id' => $marque->id,
                'slug' => $slug,
            ], $attributes);
        }
    }

    public function down(): void
    {
        // Non destructif : les alias retires etaient des doublons typographiques
        // du nom affiche. Les retablir ferait reapparaitre « Mazda 3 / Mazda3 ».
    }

    /**
     * @param  list<string>  $aRetirer
     * @return list<string>
     */
    private function sansAliasColles(mixed $stockes, array $aRetirer): array
    {
        $retrait = array_map('strtolower', $aRetirer);
        $restants = [];
        $vus = [];

        foreach ($this->decoderAlias($stockes) as $alias) {
            $cle = strtolower($alias);
            if (isset($vus[$cle]) || in_array($cle, $retrait, true)) {
                continue;
            }
            $vus[$cle] = true;
            $restants[] = $alias;
        }

        return $restants;
    }

    /**
     * @return list<string>
     */
    private function decoderAlias(mixed $stockes): array
    {
        $existants = [];
        if (is_string($stockes) && $stockes !== '') {
            $decoded = json_decode($stockes, true);
            $existants = is_array($decoded) ? $decoded : [];
        } elseif (is_array($stockes)) {
            $existants = $stockes;
        }

        $alias = [];
        foreach ($existants as $valeur) {
            $valeur = trim((string) $valeur);
            if ($valeur !== '') {
                $alias[] = $valeur;
            }
        }

        return $alias;
    }
};
