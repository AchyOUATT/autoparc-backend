<?php

namespace App\Console\Commands;

use App\Models\Part;
use App\Support\PartTaxonomy;
use Illuminate\Console\Command;

/**
 * Reclasse les pieces mal rangees par l'ancienne factory.
 *
 * Celle-ci tirait le nom et la categorie independamment : les 200 pieces de
 * demonstration sont reparties au hasard dans l'arbre. Corriger la factory ne
 * repare pas les lignes deja creees, et re-seeder effacerait le reste des
 * donnees — d'ou cette commande, sure a relancer autant de fois que voulu.
 */
class RecategorizeParts extends Command
{
    protected $signature = 'parts:recategorize {--dry-run : Affiche les corrections sans les appliquer}';

    protected $description = 'Rattache chaque piece a la categorie correspondant a son nom';

    public function handle(): int
    {
        $slugToId = PartTaxonomy::slugToId();

        if ($slugToId === []) {
            $this->error('Aucune categorie trouvee : lancer PartCategoriesSeeder d\'abord.');

            return self::FAILURE;
        }

        $dryRun    = (bool) $this->option('dry-run');
        $corrected = 0;
        $unknown   = [];

        Part::query()
            ->with('category:id,name')
            ->chunkById(200, function ($parts) use ($slugToId, $dryRun, &$corrected, &$unknown) {
                foreach ($parts as $part) {
                    $slug = PartTaxonomy::slugFor($part->name);

                    if ($slug === null) {
                        $unknown[$part->name] = true;
                        continue;
                    }

                    $expected = $slugToId[$slug] ?? null;

                    if ($expected === null || $part->part_category_id === $expected) {
                        continue;
                    }

                    $this->line(sprintf(
                        '  %-32s %s → %s',
                        $part->name,
                        $part->category?->name ?? '(aucune)',
                        $slug,
                    ));

                    if (! $dryRun) {
                        // Sans timestamps : ce n'est pas une modification
                        // metier, et /catalog/sync s'en sert comme curseur.
                        $part->timestamps = false;
                        $part->update(['part_category_id' => $expected]);
                    }

                    $corrected++;
                }
            });

        if ($unknown !== []) {
            $this->warn(sprintf(
                '%d nom(s) absent(s) de PartTaxonomy, laisses en place : %s',
                count($unknown),
                implode(', ', array_slice(array_keys($unknown), 0, 10)),
            ));
        }

        $this->info($dryRun
            ? "{$corrected} piece(s) seraient reclassees."
            : "{$corrected} piece(s) reclassees.");

        return self::SUCCESS;
    }
}
