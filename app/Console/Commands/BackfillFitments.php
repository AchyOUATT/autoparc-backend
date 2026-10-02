<?php

namespace App\Console\Commands;

use App\Enums\FitmentSource;
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
 *
 * Ce qu'elle ecrit porte l'origine `generated`, et c'est la seule chose qu'elle
 * s'autorise a detruire. `--fresh` effacait auparavant toutes les lignes d'une
 * piece avant de les reecrire : une compatibilite saisie a la main disparaissait
 * sans un mot. Desormais une piece qui porte au moins une ligne declaree est
 * laissee entierement tranquille — on ne complete pas au jugé le travail de
 * quelqu'un qui a verifie.
 */
class BackfillFitments extends Command
{
    protected $signature = 'catalog:backfill-fitments
                            {--fresh : Recalculer les compatibilites fabriquees (les saisies sont epargnees)}
                            {--dry-run : Afficher ce qui serait ecrit, sans rien ecrire}
                            {--force : Ne pas demander confirmation avant un recalcul}
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

        if ($this->option('fresh') && ! $this->confirmerRecalcul()) {
            $this->line('Abandon : rien n\'a ete touche.');

            return self::FAILURE;
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
     * Annonce ce qu'un recalcul va detruire, et demande l'accord.
     *
     * Les lignes declarees sont epargnees par construction. Reste que le
     * classement des lignes anterieures a la colonne d'origine est une
     * deduction : une saisie reduite a « cette marque, ce modele » porte la
     * meme signature qu'une fabrication et a pu etre rendue au recalcul. C'est
     * la raison de cette question — un chiffre affiche avant la suppression
     * valait mieux qu'une decouverte apres.
     */
    private function confirmerRecalcul(): bool
    {
        $fabriquees = PartFitment::where('source', FitmentSource::Generated)->count()
            + AccessoryFitment::where('source', FitmentSource::Generated)->count();

        $declarees = PartFitment::where('source', FitmentSource::Declared)->count()
            + AccessoryFitment::where('source', FitmentSource::Declared)->count();

        $this->newLine();
        $this->line(sprintf('Recalcul : %d ligne(s) fabriquee(s) seront remplacees.', $fabriquees));
        $this->line(sprintf('%d ligne(s) declaree(s) seront epargnees.', $declarees));

        if ($this->option('dry-run') || $this->option('force')) {
            return true;
        }

        return $this->confirm('Continuer ?', default: false);
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

        // Une piece dont quelqu'un a declare la compatibilite est laissee
        // tranquille, y compris en recalcul : completer au jugé une liste
        // verifiee reviendrait a melanger du vrai et du vraisemblable sans
        // que personne puisse plus les distinguer a l'ecran.
        $requete->whereDoesntHave('fitments', fn ($f) => $f->where('source', FitmentSource::Declared));

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
                $cle     = self::cleStable('piece', $piece->id, $piece->sku);
                $choisis = FitmentPlanner::modelesPour($cle, $slug, $tous, $prioritaires);

                $lignes = [];

                foreach ($choisis as $modeleId) {
                    $modele = $modeles[$modeleId];

                    [$de, $a] = FitmentPlanner::plageAnnees(
                        $cle.':'.$modeleId,
                        $modele->production_start,
                        $modele->production_end,
                    );

                    $lignes[] = [
                        'part_id'          => $piece->id,
                        'vehicle_model_id' => $modeleId,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        // insert() contourne les casts du modele : la valeur
                        // brute de l'enum, pas l'enum.
                        'source'           => FitmentSource::Generated->value,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($piece, $lignes, &$oem) {
                        // En mode --fresh la piece peut deja porter des lignes :
                        // on remplace plutot que d'empiler, sans quoi chaque
                        // execution doublerait le nombre de compatibilites.
                        // Seules les lignes fabriquees sont remplacees — la
                        // selection a deja ecarte les pieces qui portent une
                        // declaration, ce filtre est la ceinture.
                        PartFitment::where('part_id', $piece->id)->fabriquees()->delete();
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
     * reference derive de la cle stable de la piece, donc elle ne bouge pas
     * d'une execution a l'autre et ne peut pas entrer en collision avec celle
     * d'une autre piece.
     */
    private function attacherOem(Part $piece): bool
    {
        if ($piece->oemNumbers()->exists()) {
            return false;
        }

        $cle       = self::cleStable('piece', $piece->id, $piece->sku);
        $empreinte = strtoupper(substr(md5($cle), 0, 9));
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

        // Voir traiterPieces() : une declaration met l'accessoire hors d'atteinte.
        $requete->whereDoesntHave('fitments', fn ($f) => $f->where('source', FitmentSource::Declared));

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
                $cle     = self::cleStable('accessoire', $accessoire->id, $accessoire->sku);
                $choisis = FitmentPlanner::modelesPour(
                    $cle,
                    self::familleAccessoire($accessoire->name),
                    $tous,
                    $prioritaires,
                );

                $lignes = [];

                foreach ($choisis as $modeleId) {
                    $modele = $modeles[$modeleId];

                    [$de, $a] = FitmentPlanner::plageAnnees(
                        $cle.':'.$modeleId,
                        $modele->production_start,
                        $modele->production_end,
                    );

                    $lignes[] = [
                        'accessory_id'     => $accessoire->id,
                        'vehicle_model_id' => $modeleId,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        'source'           => FitmentSource::Generated->value,
                        'created_at'       => now(),
                        'updated_at'       => now(),
                    ];
                }

                if (! $this->option('dry-run')) {
                    DB::transaction(function () use ($accessoire, $lignes) {
                        AccessoryFitment::where('accessory_id', $accessoire->id)->fabriquees()->delete();
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
     * Cle stable d'un article, pour une selection rejouable.
     *
     * Le SKU tenait ce role seul. Mais il est facultatif : l'ecran de saisie
     * ne l'impose pas, l'API non plus, et la colonne accepte NULL depuis
     * qu'elle a cesse de rendre un 500 sur une piece sans reference. Un
     * article sans SKU arretait alors la commande en pleine boucle —
     * `modelesPour()` attend un `string` — et `md5(null)` donnait la meme
     * empreinte a tous, donc un seul numero OEM partage par tous les articles
     * sans reference.
     *
     * L'identifiant tient le meme role : unique, et stable dans le temps. La
     * selection reste donc rejouable, et un article qui recoit plus tard un
     * SKU change simplement de cle — sans consequence, puisque la commande
     * laisse tranquille tout article deja rattache.
     */
    private static function cleStable(string $prefixe, int $id, ?string $sku): string
    {
        return $sku ?? $prefixe.':'.$id;
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
