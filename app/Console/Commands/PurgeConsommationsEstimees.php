<?php

namespace App\Console\Commands;

use App\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Efface les consommations portees par les fiches du catalogue.
 *
 * Ces valeurs venaient de la factory, qui les derivait du gabarit : un SUV
 * essence autour de 10 l/100, une citadine autour de 6. Plausibles, et
 * entierement fabriquees — aucune des 80 fiches n'avait de cylindree pour les
 * justifier. Rien, dans l'application, ne les distinguait d'une cote
 * d'homologation.
 *
 * Depuis l'import des cotes officielles, la consommation se lit dans
 * `motorisations`, a la motorisation. Laisser ces chiffres sur les fiches,
 * c'est garder deux verites dont l'une est inventee — et la voir ressortir un
 * jour dans un filtre ou une fiche produit.
 *
 * La commande ne sait pas distinguer une valeur inventee d'une valeur saisie a
 * la main par un vendeur : elle efface tout ce qui est renseigne. D'ou la
 * confirmation, et le mode analyse pour regarder avant.
 */
class PurgeConsommationsEstimees extends Command
{
    protected $signature = 'catalog:purge-consommations
                            {--dry-run : Afficher ce qui serait efface, sans rien ecrire}
                            {--force : Ne pas demander de confirmation (usage non interactif)}';

    protected $description = 'Efface les consommations estimees portees par les fiches du catalogue';

    /** Colonnes fabriquees par la factory a partir de la consommation. */
    private const COLONNES = [
        'consumption_urban',
        'consumption_extra_urban',
        'consumption_combined',
        'electric_consumption_kwh',
        'battery_capacity_kwh',
        'electric_range_km',
        'co2_g_km',
        'fuel_tank_liters',
    ];

    public function handle(): int
    {
        $concernees = Vehicle::query()->where(function ($q) {
            foreach (self::COLONNES as $colonne) {
                $q->orWhereNotNull($colonne);
            }
        });

        $total = (clone $concernees)->count();

        if ($total === 0) {
            $this->info('Aucune fiche ne porte de consommation : rien a faire.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d fiches portent au moins une valeur :', $total));
        foreach (self::COLONNES as $colonne) {
            $n = Vehicle::query()->whereNotNull($colonne)->count();
            if ($n > 0) {
                $this->line(sprintf('  %-26s %d', $colonne, $n));
            }
        }

        $this->newLine();
        foreach ((clone $concernees)->with(['brand', 'vehicleModel'])->take(3)->get() as $v) {
            $this->line(sprintf(
                '  ex. %s %s %d — %s l/100',
                $v->brand?->name, $v->vehicleModel?->name, $v->manufacturing_year, $v->consumption_combined ?? '—'
            ));
        }
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->info('Mode analyse : rien n\'a ete efface.');

            return self::SUCCESS;
        }

        // Une valeur saisie a la main par un vendeur disparaitrait aussi : on
        // ne l'efface pas sans le dire.
        if (! $this->option('force') && ! $this->confirm("Effacer ces valeurs sur $total fiches ?", false)) {
            $this->line('Abandon.');

            return self::SUCCESS;
        }

        $efface = $concernees->update(array_fill_keys(self::COLONNES, null));

        $this->info(sprintf('%d fiches nettoyees.', $efface));
        $this->line('La consommation se lit desormais dans `motorisations` (catalog:import-consommations).');

        return self::SUCCESS;
    }
}
