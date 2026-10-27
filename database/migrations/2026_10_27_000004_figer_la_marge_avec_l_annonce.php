<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le taux de marge, et le prix propose, conserves AVEC l'annonce.
 *
 * ── POURQUOI STOCKER PLUTOT QUE RECALCULER ────────────────────────────────
 *
 * La boutique prend un pourcentage sur les prix proposes par un partenaire.
 * Le plus simple serait de garder le prix propose en base et d'appliquer le
 * taux a l'affichage. C'est aussi le plus dangereux :
 *
 *   — changer le reglage ferait bouger le prix de TOUTES les annonces
 *     existantes, d'un coup, sans que personne n'y ait touche ;
 *   — un lien de partage deja envoye annoncerait un autre prix que celui que
 *     l'acheteur avait vu — ce depot a deja paye une page de partage qui
 *     annoncait un prix pour un vehicule vendu ;
 *   — et toutes les routes publiques qui TRIENT ou FILTRENT par prix
 *     (`?price_max=`, le tri par prix, la bande des bonnes affaires)
 *     travailleraient sur une colonne qui n'est pas le prix affiche. Un
 *     acheteur filtrant « moins de 5 000 000 » verrait des annonces a
 *     5 500 000.
 *
 * Le prix EN COLONNE est donc le prix public, marge comprise. `partner_pricing`
 * garde la trace de ce qui l'a produit : le prix que le partenaire a propose,
 * le taux applique, et quand. C'est ce qu'on lui reaffiche — « votre prix
 * 4 500 000, marge 10 %, total 4 950 000 » — et c'est juste meme six mois plus
 * tard, quand le reglage aura change.
 *
 * ── POURQUOI UNE COLONNE JSON ─────────────────────────────────────────────
 *
 * Trois informations liees, qui n'ont de sens qu'ensemble et dont aucune ne
 * sert a filtrer ni a trier. Trois colonnes plates — plus une par prix
 * propose, soit cinq de plus sur `vehicles` — alourdiraient le schema pour un
 * bloc qu'on lit d'un seul tenant et qu'on n'interroge jamais.
 *
 * ── LES ANNONCES EXISTANTES ───────────────────────────────────────────────
 *
 * Nulles. Et c'est exact : elles ont ete enregistrees quand aucune marge
 * n'existait, donc leur prix EST celui que le partenaire a propose. Leur
 * inventer un taux de 0 % ne dirait rien de plus et laisserait croire qu'un
 * calcul a eu lieu.
 */
return new class extends Migration
{
    private const TABLES = ['vehicles', 'parts', 'accessories'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('partner_pricing')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('partner_pricing');
            });
        }
    }
};
