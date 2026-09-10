<?php

namespace App\Support;

use App\Models\PartCategory;

/**
 * Rattache un nom de piece a sa categorie.
 *
 * La factory tirait le nom et la categorie independamment au hasard : le
 * catalogue affichait « Alternateur » range dans « Flexibles de frein » et
 * « Amortisseur arriere droit » dans « Liquide de frein ». Les filtres par
 * categorie ne renvoyaient donc rien de coherent, et la demonstration
 * donnait l'impression d'une application cassee.
 *
 * La correspondance vit ici plutot que dans la factory parce que la commande
 * `parts:recategorize` s'en sert aussi pour reparer les lignes deja creees.
 */
class PartTaxonomy
{
    /**
     * Nom de piece → slug de categorie (voir PartCategoriesSeeder).
     *
     * Les noms sont ceux que la factory tire au sort : toute entree ajoutee
     * la-bas doit apparaitre ici, sinon la piece retombe sur un rattachement
     * aleatoire.
     */
    public const BY_NAME = [
        // ── Moteur ────────────────────────────────────────────────────────
        'Filtre à huile'                => 'filtre-huile',
        'Filtre à air'                  => 'filtre-air',
        'Filtre habitacle'              => 'filtre-habitacle',
        'Filtre carburant'              => 'filtre-carburant',
        'Filtre à carburant'            => 'filtre-carburant',
        'Bougie d\'allumage'            => 'bougies-allumage',
        'Bobine d\'allumage'            => 'bobines-allumage',
        'Injecteur'                     => 'injecteurs',
        'Kit de distribution'           => 'kit-distribution',
        'Courroie de distribution'      => 'courroie-distribution',
        // Ni l'une ni l'autre n'entraine l'arbre a cames, mais elles se
        // vendent avec le kit : elles restent dans la meme famille.
        'Courroie accessoires'          => 'distribution',
        'Galet tendeur'                 => 'distribution',
        'Joint de culasse'              => 'joint-culasse',
        'Pompe à eau'                   => 'pompe-eau',
        'Thermostat moteur'             => 'thermostat',
        'Radiateur de refroidissement'  => 'radiateur',

        // ── Freinage ──────────────────────────────────────────────────────
        'Plaquettes de frein avant'     => 'plaquettes-avant',
        'Plaquettes de frein arrière'   => 'plaquettes-arriere',
        'Disque de frein avant'         => 'disques-avant',
        'Disque de frein arrière'       => 'disques-arriere',

        // ── Suspension & Direction ────────────────────────────────────────
        'Amortisseur avant'             => 'amortisseurs-avant',
        'Amortisseur avant gauche'      => 'amortisseurs-avant',
        'Amortisseur avant droit'       => 'amortisseurs-avant',
        'Amortisseur arrière gauche'    => 'amortisseurs-arriere',
        'Amortisseur arrière droit'     => 'amortisseurs-arriere',
        'Rotule de direction'           => 'rotule-direction',
        'Rotule de suspension'          => 'rotules-biellettes',
        'Biellette de barre stab.'      => 'biellette-barre-stab',
        'Bras de suspension'            => 'rotules-biellettes',
        'Silent-bloc de berceau'        => 'rotules-biellettes',
        'Roulement de roue avant'       => 'roulements-moyeux',
        'Roulement de roue arrière'     => 'roulements-moyeux',
        'Kit roulement moyeu'           => 'roulements-moyeux',
        'Soufflet de cardan'            => 'soufflets-homocinets',
        'Crémaillère de direction'      => 'cremaillere',
        'Pompe de direction assistée'   => 'direction',

        // ── Transmission ──────────────────────────────────────────────────
        'Kit embrayage'                 => 'kit-embrayage',
        'Volant moteur bi-masse'        => 'volant-moteur',

        // ── Électricité ───────────────────────────────────────────────────
        'Alternateur'                   => 'alternateur',
        'Démarreur'                     => 'demarreur',
        'Batterie 12V 60Ah'             => 'batterie-12v',
        'Batterie 12V 70Ah'             => 'batterie-12v',
        'Batterie 12V 75Ah'             => 'batterie-12v',
        'Batterie 60Ah'                 => 'batterie-12v',
        'Sonde lambda'                  => 'sonde-lambda',
        'Capteur ABS'                   => 'capteur-abs',
        'Optique avant gauche'          => 'phares',
        'Optique avant droit'           => 'phares',
        'Feu arrière gauche'            => 'feux-arriere',
        'Feu arrière droit'             => 'feux-arriere',

        // ── Carrosserie ───────────────────────────────────────────────────
        'Pare-brise'                    => 'pare-brise',
        'Vitre latérale avant gauche'   => 'vitre-laterale',

        // ── Échappement ───────────────────────────────────────────────────
        'Catalyseur'                    => 'catalyseur',
        'Silencieux arrière'            => 'silencieux',

        // ── Divers ────────────────────────────────────────────────────────
        'Radiateur'                     => 'radiateur',
    ];

    /** Slug de categorie attendu pour ce nom de piece, null si inconnu. */
    public static function slugFor(string $partName): ?string
    {
        return self::BY_NAME[$partName] ?? null;
    }

    /**
     * Slugs → ids, en une requete.
     *
     * @return array<string, int>
     */
    public static function slugToId(): array
    {
        return PartCategory::query()
            ->whereIn('slug', array_values(array_unique(self::BY_NAME)))
            ->pluck('id', 'slug')
            ->all();
    }
}
