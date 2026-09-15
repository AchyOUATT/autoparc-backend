<?php

namespace App\Support;

/**
 * Decide a quels modeles de vehicule une piece s'applique.
 *
 * Le catalogue de demonstration n'avait aucune compatibilite declaree : 200
 * pieces, 161 modeles, zero ligne dans `part_fitments`. Toute recherche de
 * pieces compatibles rendait donc un ensemble vide, quel que soit le vehicule
 * et quelle que soit la qualite de sa fiche. La cause etait que les deux seuls
 * seeders capables d'en creer n'etaient appeles nulle part, et que la factory
 * qui a reellement peuple la table n'en cree aucune.
 *
 * Deux exigences ont guide ce qui suit.
 *
 * *Coherent* : l'ancien seeder tirait trois modeles au hasard, ce qui declarait
 * un filtre a huile de Corolla compatible avec un Hilux. La portee depend donc
 * de la piece — un filtre se monte sur beaucoup de modeles, un pare-brise sur
 * un seul — et les modeles retenus sont stables pour une piece donnee.
 *
 * *Rejouable* : la selection derive d'une empreinte du couple (piece, modele),
 * jamais d'un tirage. Relancer la commande sur une base deja traitee redonne
 * exactement le meme resultat, donc rien a dedupliquer et rien qui derive entre
 * deux executions.
 */
final class FitmentPlanner
{
    /**
     * Combien de modeles une famille de pieces couvre.
     *
     * Ce ne sont pas des chiffres de catalogue reel : ce sont des ordres de
     * grandeur qui rendent la demonstration credible. Un filtre a huile se
     * monte effectivement sur des dizaines de modeles, un optique avant sur
     * une seule generation d'un seul modele.
     *
     * @var array<string, int>
     */
    private const PORTEE_PAR_SLUG = [
        // Consommables : la meme reference couvre des familles entieres.
        'filtre-huile'        => 16,
        'filtre-air'          => 14,
        'filtre-habitacle'    => 14,
        'filtre-carburant'    => 12,
        'bougies-allumage'    => 14,
        'batterie-12v'        => 20,
        'distribution'        => 10,

        // Mecanique : specifique a la plateforme, pas au modele exact.
        'plaquettes-avant'    => 6,
        'plaquettes-arriere'  => 6,
        'disques-avant'       => 5,
        'disques-arriere'     => 5,
        'amortisseurs-avant'  => 4,
        'amortisseurs-arriere' => 4,
        'rotules-biellettes'  => 5,
        'biellette-barre-stab' => 5,
        'roulements-moyeux'   => 5,
        'soufflets-homocinets' => 5,
        'rotule-direction'    => 4,
        'cremaillere'         => 3,
        'direction'           => 3,
        'kit-embrayage'       => 3,
        'volant-moteur'       => 3,
        'kit-distribution'    => 4,
        'courroie-distribution' => 4,
        'pompe-eau'           => 4,
        'thermostat'          => 6,
        'joint-culasse'       => 3,
        'radiateur'           => 4,
        'alternateur'         => 4,
        'demarreur'           => 4,
        'bobines-allumage'    => 5,
        'injecteurs'          => 4,
        'sonde-lambda'        => 6,
        'capteur-abs'         => 5,
        'catalyseur'          => 3,
        'silencieux'          => 3,

        // Carrosserie et optique : une piece, un modele.
        'phares'              => 1,
        'feux-arriere'        => 1,
        'pare-brise'          => 2,
        'vitre-laterale'      => 2,

        // Accessoires. Ils n'ont pas de categorie de piece : l'appelant leur
        // fournit l'une de ces deux familles selon que l'article se declare
        // universel ou non. Un tapis de sol universel se monte partout, des
        // jantes 18 pouces non.
        'accessoire-universel'  => 24,
        'accessoire-specifique' => 6,
    ];

    /** Portee d'une piece dont la famille n'est pas declaree ci-dessus. */
    private const PORTEE_PAR_DEFAUT = 4;

    /**
     * Une piece sur trois restreint ses annees ; les autres valent pour toutes.
     *
     * Une compatibilite sans plage d'annees vaut pour toutes les annees — c'est
     * deja la regle appliquee par CompatibilityService aux finitions et aux
     * motorisations. La laisser majoritaire evite que des fiches de garage dont
     * l'annee ne tombe pas dans la fenetre de production du modele se retrouvent
     * sans aucune piece, tout en gardant des cas ou la plage sert vraiment.
     */
    private const UNE_SUR = 3;

    /**
     * Nombre de modeles a couvrir pour cette piece.
     */
    public static function portee(?string $slugCategorie): int
    {
        return self::PORTEE_PAR_SLUG[$slugCategorie] ?? self::PORTEE_PAR_DEFAUT;
    }

    /**
     * Modeles retenus pour une piece, du plus pertinent au moins pertinent.
     *
     * `$prioritaires` sont les modeles reellement presents dans les garages et
     * dans le parc : la moitie de la portee leur est reservee. Sans cette
     * reserve, une selection uniforme sur 161 modeles ne toucherait presque
     * jamais les quelques modeles qu'un utilisateur possede, et l'ecran
     * resterait vide malgre des milliers de lignes en base.
     *
     * @param  list<int>  $tousLesModeles
     * @param  list<int>  $prioritaires
     * @return list<int>
     */
    public static function modelesPour(
        string $cle,
        ?string $slugCategorie,
        array $tousLesModeles,
        array $prioritaires = []
    ): array {
        $portee = self::portee($slugCategorie);

        $partPrioritaire = $prioritaires === []
            ? 0
            : max(1, intdiv($portee, 2));

        $retenus = self::echantillon($cle.':prioritaires', $prioritaires, $partPrioritaire);

        // Le reste se prend sur l'ensemble du catalogue ; les doublons avec la
        // part prioritaire disparaissent au dedoublonnage, la piece couvre donc
        // parfois un modele de moins que sa portee. C'est sans consequence.
        $reste = self::echantillon(
            $cle.':tous',
            $tousLesModeles,
            $portee - count($retenus)
        );

        return array_values(array_unique([...$retenus, ...$reste]));
    }

    /**
     * Plage d'annees d'une compatibilite, ou [null, null] pour « toutes ».
     *
     * Quand une plage est posee, elle epouse la fenetre de production du
     * modele : declarer une piece compatible avec une generation en dehors des
     * annees ou elle a ete produite n'aurait aucun sens.
     *
     * @return array{0: int|null, 1: int|null}
     */
    public static function plageAnnees(
        string $cle,
        ?int $productionDebut,
        ?int $productionFin
    ): array {
        if ($productionDebut === null) {
            return [null, null];
        }

        if (self::empreinte($cle) % self::UNE_SUR !== 0) {
            return [null, null];
        }

        return [$productionDebut, $productionFin];
    }

    /**
     * Tire `$combien` elements de facon stable pour une meme cle.
     *
     * On ordonne par empreinte du couple (cle, element) et on prend la tete :
     * la selection parait aleatoire, ne depend d'aucun generateur global, et
     * redonne le meme resultat a chaque execution.
     *
     * @param  list<int>  $elements
     * @return list<int>
     */
    private static function echantillon(string $cle, array $elements, int $combien): array
    {
        if ($combien <= 0 || $elements === []) {
            return [];
        }

        $classes = [];

        foreach ($elements as $element) {
            $classes[$element] = self::empreinte($cle.':'.$element);
        }

        asort($classes);

        return array_slice(array_keys($classes), 0, $combien);
    }

    /** Entier stable tire d'une chaine — le meme partout, a chaque fois. */
    private static function empreinte(string $cle): int
    {
        return (int) hexdec(substr(md5($cle), 0, 8));
    }
}
