<?php

namespace App\Console\Commands;

use App\Models\Accessory;
use App\Models\AccessoryFitment;
use App\Models\OemNumber;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use App\Support\FitmentPlanner;
use App\Support\PartTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Declare quelles pieces et quels accessoires vont sur quels modeles.
 *
 * La table `part_fitments` etait vide en local comme en ligne : les deux
 * seeders capables d'en creer n'etaient appeles nulle part, et la factory qui a
 * reellement peuple le catalogue n'en cree aucune. Consequence, la recherche de
 * pieces compatibles rendait toujours un ensemble vide — sans erreur, sans
 * message, juste une liste vide que rien n'expliquait.
 *
 * Reseeder n'etait pas envisageable : la base en ligne porte des vrais comptes,
 * de vrais garages et de vraies commandes. Cette commande complete l'existant
 * sans y toucher, et se relance sans risque : la selection etant deterministe,
 * une piece deja traitee retrouve exactement les memes modeles.
 */
class BackfillFitments extends Command
{
    protected $signature = 'catalog:backfill-fitments
                            {--fresh : Recalculer aussi les compatibilites existantes}
                            {--dry-run : Afficher ce qui serait ecrit, sans rien ecrire}
                            {--only= : Se limiter a "parts" ou "accessories"}';

    protected $description = 'Genere les compatibilites manquantes entre le catalogue et les modeles de vehicule';

    public function handle(): int
    {
        $seulement = $this->option('only');

        if ($seulement !== null && ! in_array($seulement, ['parts', 'accessories'], true)) {
            $this->error('--only accepte "parts" ou "accessories".');

            return self::FAILURE;
        }

        $modeles = VehicleModel::query()
            ->get(['id', 'production_start', 'production_end'])
            ->keyBy('id');

        if ($modeles->isEmpty()) {
            $this->error('Aucun modele de vehicule en base : rien a rattacher.');

            return self::FAILURE;
        }

        $tous          = $modeles->keys()->all();
        $prioritaires  = $this->modelesPrioritaires();

        $this->line(sprintf(
            '%d modeles, dont %d reellement utilises (garages et parc).',
            count($tous),
            count($prioritaires)
        ));

        if ($this->option('dry-run')) {
            $this->warn('Mode essai : rien ne sera ecrit.');
        }

        if ($seulement !== 'accessories') {
            $this->traiterPieces($modeles, $tous, $prioritaires);
        }

        if ($seulement !== 'parts') {
            $this->traiterAccessoires($modeles, $tous, $prioritaires);
        }

        return self::SUCCESS;
    }

    /**
     * Modeles presents dans les garages clients et dans le parc.
     *
     * Ce sont eux qu'il faut couvrir en priorite : une selection uniforme sur
     * cent soixante modeles ne toucherait presque jamais les quelques modeles
     * qu'une personne possede, et son ecran resterait vide malgre des milliers
     * de lignes ecrites.
     *
     * @return list<int>
     */
    private function modelesPrioritaires(): array
    {
        return array_values(array_unique([
            ...OwnedVehicle::query()->distinct()->pluck('vehicle_model_id')->all(),
            ...Vehicle::query()->distinct()->pluck('vehicle_model_id')->all(),
        ]));
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VehicleModel>  $modeles
     * @param  list<int>  $tous
     * @param  list<int>  $prioritaires
     */
    private function traiterPieces($modeles, array $tous, array $prioritaires): void
    {
        $this->newLine();
        $this->info('Pieces');

        $requete = Part::query()->select(['id', 'sku', 'name']);

        if (! $this->option('fresh')) {
            $requete->whereDoesntHave('fitments');
        }

        $total   = (clone $requete)->count();
        $ecrites = 0;
        $traitees = 0;
        $oem     = 0;

        if ($total === 0) {
            $this->line('  Rien a faire : toutes les pieces ont deja une compatibilite.');

            return;
        }

        $barre = $this->output->createProgressBar($total);

        $requete->chunkById(200, function ($lot) use (
            $modeles, $tous, $prioritaires, &$ecrites, &$traitees, &$oem, $barre
        ) {
            foreach ($lot as $piece) {
                $slug    = PartTaxonomy::slugFor($piece->name);
                $choisis = FitmentPlanner::modelesPour($piece->sku, $slug, $tous, $prioritaires);

                $lignes = [];

                foreach ($choisis as $modeleId) {
                    $modele = $modeles[$modeleId];

                    [$de, $a] = FitmentPlanner::plageAnnees(
                        $piece->sku.':'.$modeleId,
                        $modele->production_start,
                        $modele->production_end,
                    );

                    $lignes[] = [
                        'part_id'          => $piece->id,
                        'vehicle_model_id' => $modeleId,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($piece, $lignes, &$oem) {
                        // En mode --fresh la piece peut deja porter des lignes :
                        // on remplace plutot que d'empiler, sans quoi chaque
                        // execution doublerait le nombre de compatibilites.
                        PartFitment::where('part_id', $piece->id)->delete();
                        PartFitment::insert($lignes);

                        if ($this->attacherOem($piece)) {
                            $oem++;
                        }
                    });
                }

                $ecrites += count($lignes);
                $traitees++;
                $barre->advance();
            }
        });

        $barre->finish();
        $this->newLine();

        $this->line(sprintf(
            '  %d pieces, %d compatibilites%s, %d references OEM creees.',
            $traitees,
            $ecrites,
            $this->option('dry-run') ? ' (non ecrites)' : '',
            $oem,
        ));
    }

    /**
     * Donne a la piece une reference constructeur si elle n'en a pas.
     *
     * La table etait vide elle aussi, et c'est le pivot de la recherche par
     * numero OEM : sans elle, saisir une reference ne renvoie jamais rien. La
     * reference derive du SKU, donc elle est stable et ne peut pas entrer en
     * collision avec celle d'une autre piece.
     */
    private function attacherOem(Part $piece): bool
    {
        if ($piece->oemNumbers()->exists()) {
            return false;
        }

        $empreinte = strtoupper(substr(md5($piece->sku), 0, 9));
        $numero    = substr($empreinte, 0, 5).'-'.substr($empreinte, 5, 4);

        $oem = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize($numero)],
            ['number' => $numero, 'label' => $piece->name, 'is_superseded' => false],
        );

        $piece->oemNumbers()->attach($oem->id, ['is_primary' => true]);

        return true;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, VehicleModel>  $modeles
     * @param  list<int>  $tous
     * @param  list<int>  $prioritaires
     */
    private function traiterAccessoires($modeles, array $tous, array $prioritaires): void
    {
        $this->newLine();
        $this->info('Accessoires');

        $requete = Accessory::query()->select(['id', 'sku', 'name']);

        if (! $this->option('fresh')) {
            $requete->whereDoesntHave('fitments');
        }

        $total    = (clone $requete)->count();
        $ecrites  = 0;
        $traites  = 0;

        if ($total === 0) {
            $this->line('  Rien a faire : tous les accessoires ont deja une compatibilite.');

            return;
        }

        $barre = $this->output->createProgressBar($total);

        $requete->chunkById(200, function ($lot) use (
            $modeles, $tous, $prioritaires, &$ecrites, &$traites, $barre
        ) {
            foreach ($lot as $accessoire) {
                $choisis = FitmentPlanner::modelesPour(
                    $accessoire->sku,
                    self::familleAccessoire($accessoire->name),
                    $tous,
                    $prioritaires,
                );

                $lignes = [];

                foreach ($choisis as $modeleId) {
                    $modele = $modeles[$modeleId];

                    [$de, $a] = FitmentPlanner::plageAnnees(
                        $accessoire->sku.':'.$modeleId,
                        $modele->production_start,
                        $modele->production_end,
                    );

                    $lignes[] = [
                        'accessory_id'     => $accessoire->id,
                        'vehicle_model_id' => $modeleId,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($accessoire, $lignes) {
                        AccessoryFitment::where('accessory_id', $accessoire->id)->delete();
                        AccessoryFitment::insert($lignes);
                    });
                }

                $ecrites += count($lignes);
                $traites++;
                $barre->advance();
            }
        });

        $barre->finish();
        $this->newLine();

        $this->line(sprintf(
            '  %d accessoires, %d compatibilites%s.',
            $traites,
            $ecrites,
            $this->option('dry-run') ? ' (non ecrites)' : '',
        ));
    }

    /**
     * Un accessoire qui se dit universel se monte partout, les autres non.
     *
     * Le mot figure dans le nom de l'article : « Tapis de sol universel »,
     * « Housses de siege universelles ». C'est grossier, mais c'est la seule
     * information disponible, et elle suffit a eviter de declarer des jantes
     * 18 pouces compatibles avec cent soixante modeles.
     */
    private static function familleAccessoire(string $nom): string
    {
        return str_contains(mb_strtolower($nom), 'universel')
            ? 'accessoire-universel'
            : 'accessoire-specifique';
    }
}
