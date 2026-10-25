<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Genere des references sequentielles lisibles : VEH-2026-000123, LOC-2026-000045...
 *
 * ── LE DEFAUT QUE CECI CORRIGE : UN 500 PERMANENT ──────────────────────────
 *
 * La version precedente lisait le maximum par `orderByDesc($column)`, c'est-a-dire
 * par TRI DE CHAINES sur une colonne texte. Tant que toutes les suites tiennent
 * sur six chiffres, le tri de chaines coincide avec l'ordre numerique et
 * personne ne peut s'en apercevoir. Au-dela, il s'en ecarte — et ne revient
 * jamais.
 *
 * Mesure du 25/10/2026, faite sur le vrai chemin HTTP :
 *
 *   une reference `VEH-2026-999999` est posee  -> 201
 *   creation suivante                          -> 201, `VEH-2026-1000000`
 *   creation d'apres                           -> 500
 *   et toutes les suivantes                    -> 500
 *
 * Pourquoi : `str_pad` ne TRONQUE pas, donc la premiere creation au-dela du
 * million passe. Mais le maximum par tri de chaines reste `VEH-2026-999999`,
 * puisque '9' > '1' au dixieme caractere. `next()` reproduit donc
 * indefiniment `VEH-2026-1000000`, deja pris, et la violation de contrainte
 * remonte nue : 500. Plus aucun vehicule, aucune vente, aucune location ne se
 * cree pour le reste de l'annee.
 *
 * Le declencheur n'a rien d'exotique : il suffit qu'une reference soit saisie
 * A LA MAIN avec une suite elevee. Le garde partenaire ferme cette porte aux
 * tiers (`AuthorizesPartnerCatalog`), mais le personnel garde la main sur
 * `reference` — et c'est voulu. Le generateur devait donc tenir de lui-meme.
 *
 * ── LA CORRECTION ─────────────────────────────────────────────────────────
 *
 * Le maximum est calcule NUMERIQUEMENT, et seules les references de la forme
 * attendue y participent : `prefix-annee-chiffres`. Tout le reste est ignore
 * plutot que de produire un `(int)` fantaisiste — `(int) 'ABC'` vaut 0, et une
 * suite repartant de 1 heurterait une reference existante.
 *
 * ── UNE SECONDE GARDE A ETE ECRITE, PUIS RETIREE ──────────────────────────
 *
 * Elle verifiait que la valeur calculee etait libre, et avancait sinon. Les
 * tests par mutation ont montre qu'elle etait INATTEIGNABLE : toute valeur de
 * la forme `base+chiffres` entre dans le maximum ci-dessus, donc la candidate
 * `max+1` est strictement superieure a toutes ; et une valeur qui n'a pas
 * cette forme ne peut pas, par construction, etre egale a `base+chiffres`.
 * Aucune collision n'est donc possible, et la boucle ne tournait jamais.
 *
 * C'est note ici parce qu'un garde mort est pire que pas de garde : il promet
 * une protection qui n'existe pas, et le prochain lecteur s'y fierait.
 *
 * ── CE QUE CECI NE CORRIGE PAS ─────────────────────────────────────────────
 *
 * La course. `next()` lit puis ecrit sans verrou : deux creations simultanees
 * obtiendraient la meme suite, et la seconde heurterait la contrainte. Ce
 * n'est pas atteignable aujourd'hui — `php artisan serve` est mono-processus
 * et ne sert qu'une requete a la fois, c'est documente dans le Dockerfile — et
 * le jour ou l'API passera a plusieurs ouvriers, c'est une sequence en base ou
 * un verrou de table qu'il faudra, pas un rafistolage ici.
 *
 * Le cout en memoire est assume : on lit les references de l'annee pour le
 * prefixe demande, et non une seule ligne. Quelques milliers de chaines
 * courtes au pire, sur une instance qui en tient 512 Mo. Si une table venait a
 * porter des centaines de milliers de lignes par an, c'est ici qu'il faudrait
 * revenir — avec une colonne de suite entiere, plutot qu'en reparant un tri.
 */
class ReferenceGenerator
{
    /** Largeur de la suite, complétée par des zéros : `000123`. */
    public const LARGEUR = 6;

    public function next(string $prefix, ?string $table = null, string $column = 'reference'): string
    {
        $base = "{$prefix}-".now()->format('Y').'-';

        $table ??= match ($prefix) {
            'VEH' => 'vehicles',
            'LOC' => 'rentals',
            'VTE' => 'sales',
            'CMD' => 'part_orders',
            'CLI' => 'customers',
            'PAY' => 'payments',
            default => 'vehicles',
        };

        $column = $prefix === 'CLI' ? 'code' : $column;

        $suite = DB::table($table)
            ->where($column, 'like', $base.'%')
            ->pluck($column)
            ->map(fn ($valeur) => self::suiteDe($base, (string) $valeur))
            ->max() ?? 0;

        return $base.str_pad((string) ($suite + 1), self::LARGEUR, '0', STR_PAD_LEFT);
    }

    /**
     * La suite portee par une reference, ou 0 si elle n'a pas la forme attendue.
     *
     * L'ancrage des deux cotes est ce qui compte : sans `$`, `VEH-2026-12-bis`
     * rendrait 12 et une suite pourrait se croire plus avancee qu'elle n'est.
     */
    private static function suiteDe(string $base, string $reference): int
    {
        return preg_match('/^'.preg_quote($base, '/').'(\d+)$/', $reference, $trouve) === 1
            ? (int) $trouve[1]
            : 0;
    }
}
