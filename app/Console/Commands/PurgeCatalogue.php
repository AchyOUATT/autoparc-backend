<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vide le catalogue pour repartir sur des donnees reelles.
 *
 * Le catalogue en place est une demonstration : 80 vehicules, 200 pieces et 80
 * accessoires produits par les seeders, dont les compatibilites et les numeros
 * constructeur ont ete fabriques a partir du SKU. Rien de tout cela ne decrit
 * un vehicule ou une piece qui existe. Tant que ces lignes cohabitent avec de
 * vraies saisies, personne ne peut plus les distinguer.
 *
 * Deux precautions gouvernent ce qui suit.
 *
 * *La suppression est definitive.* Les cinq tables concernees sont en
 * suppression douce : un `delete()` ordinaire laisserait les lignes en base
 * avec leur `deleted_at`, et leurs SKU et VIN continueraient d'occuper les
 * index uniques. La premiere vraie piece portant un SKU de demonstration
 * serait alors refusee pour « reference deja utilisee », sur une ligne que
 * l'ecran n'affiche plus. On supprime donc en base, pas par le modele.
 *
 * *Un historique de commandes arrete tout.* Les lignes de commande sont en
 * RESTRICT sur les pieces et les accessoires : la suppression echouerait de
 * toute facon, mais sur une erreur SQL que personne ne sait lire. La commande
 * verifie donc d'abord, et refuse en nommant ce qu'elle a trouve. Une base qui
 * porte de vraies commandes n'est pas une base a vider.
 *
 * Ce qui est conserve, et pourquoi : les comptes du personnel, sans lesquels
 * plus personne n'entre dans le back-office ; les vehicules de garage des
 * utilisateurs, qui sont leurs vehicules a eux et non du catalogue ; et tout le
 * referentiel — marques, modeles, finitions, motorisations, categories,
 * equipementiers — dont les vraies saisies ont justement besoin.
 */
class PurgeCatalogue extends Command
{
    protected $signature = 'catalog:purge
                            {--dry-run : Afficher ce qui serait supprime, sans rien supprimer}
                            {--force : Ne pas demander confirmation}';

    protected $description = 'Vide le catalogue de demonstration (vehicules, pieces, accessoires, clients)';

    /**
     * Les tables a vider, dans l'ordre, avec la raison de leur place.
     *
     * L'ordre n'est pas cosmetique : `rentals` et `sales` sont en RESTRICT sur
     * `vehicles` et sur `customers`, donc ils passent avant les deux. Le reste
     * part en cascade et n'a pas a figurer ici — les enumerer aurait donne
     * l'illusion d'une liste complete qui aurait derive au premier changement
     * de schema.
     *
     * @var array<string, string>
     */
    private const ORDRE = [
        'rentals'     => 'locations — en RESTRICT sur les vehicules et les clients',
        'sales'       => 'ventes — idem',
        'vehicles'    => 'vehicules du catalogue (equipements, details d\'import et d\'immatriculation suivent)',
        'parts'       => 'pieces (compatibilites, numeros rattaches et mouvements de stock suivent)',
        'accessories' => 'accessoires (compatibilites suivent)',
        'oem_numbers' => 'numeros constructeur, fabriques a partir du SKU des pieces',
        'customers'   => 'clients de demonstration',
    ];

    /** Ce qui doit rester vide pour qu'un vidage soit legitime. */
    private const HISTORIQUE = ['part_order_items', 'accessory_order_items'];

    public function handle(): int
    {
        if (($bloquant = $this->historiqueDeCommandes()) !== []) {
            $this->error('Vidage refuse : cette base porte un historique de commandes.');
            $this->newLine();

            foreach ($bloquant as $table => $nombre) {
                $this->line(sprintf('  %-24s %d ligne(s)', $table, $nombre));
            }

            $this->newLine();
            $this->line('Supprimer les pieces ou les accessoires qu\'elles designent effacerait');
            $this->line('ce qui s\'est reellement vendu. Rien n\'a ete touche.');

            return self::FAILURE;
        }

        $compte = $this->compter();
        $total  = array_sum($compte);

        $this->line('A supprimer :');
        $this->newLine();

        foreach (self::ORDRE as $table => $raison) {
            $this->line(sprintf('  %-14s %5d   %s', $table, $compte[$table], $raison));
        }

        $this->newLine();
        $this->line('Conserves : comptes du personnel, vehicules de garage, et tout le referentiel.');
        $this->newLine();

        if ($total === 0) {
            $this->info('Rien a supprimer : le catalogue est deja vide.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn('Mode essai : rien n\'a ete supprime.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Supprimer definitivement ces lignes ?', default: false)) {
            $this->line('Abandon : rien n\'a ete touche.');

            return self::FAILURE;
        }

        $supprimees = $this->vider();

        $this->newLine();
        $this->info(sprintf('%d ligne(s) supprimee(s).', array_sum($supprimees)));

        foreach ($supprimees as $table => $nombre) {
            if ($nombre > 0) {
                $this->line(sprintf('  %-14s %d', $table, $nombre));
            }
        }

        return self::SUCCESS;
    }

    /**
     * Les lignes de commande deja enregistrees, s'il y en a.
     *
     * @return array<string, int>
     */
    private function historiqueDeCommandes(): array
    {
        $trouve = [];

        foreach (self::HISTORIQUE as $table) {
            $nombre = DB::table($table)->count();

            if ($nombre > 0) {
                $trouve[$table] = $nombre;
            }
        }

        return $trouve;
    }

    /** @return array<string, int> */
    private function compter(): array
    {
        $compte = [];

        foreach (array_keys(self::ORDRE) as $table) {
            $compte[$table] = DB::table($table)->count();
        }

        return $compte;
    }

    /**
     * Supprime en base, dans l'ordre, et d'un seul tenant.
     *
     * La transaction n'est pas une precaution de style : une suppression
     * interrompue au milieu laisserait un catalogue sans vehicules mais avec
     * ses ventes, c'est-a-dire un etat qu'aucun ecran ne sait afficher.
     *
     * @return array<string, int>
     */
    private function vider(): array
    {
        $supprimees = [];

        DB::transaction(function () use (&$supprimees) {
            foreach (array_keys(self::ORDRE) as $table) {
                $supprimees[$table] = DB::table($table)->delete();
            }
        });

        return $supprimees;
    }
}
