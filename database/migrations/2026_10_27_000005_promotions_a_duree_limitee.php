<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une promotion a duree limitee sur une annonce.
 *
 * ── CE QU'ELLE N'EST PAS ──────────────────────────────────────────────────
 *
 * Pas `deal_type`. Celui-ci est une PASTILLE editoriale que le personnel pose
 * et retire : elle ne change pas le prix et n'expire pas d'elle-meme (voir
 * `App\Enums\VehicleDealType`). La promotion, elle, BAISSE le prix et porte
 * une duree. Les deux coexistent et alimentent toutes deux `?deal=1`.
 *
 * ── POURQUOI LA COLONNE DE PRIX GARDE LE PRIX NORMAL ──────────────────────
 *
 * La tentation est d'ecrire le prix promotionnel directement dans
 * `sale_price` / `selling_price` : tout le reste suivrait sans rien toucher —
 * les filtres, le tri, le panier, et jusqu'a l'application deja publiee.
 *
 * C'est un piege, et il est sans retour. L'hebergement n'a ni shell ni tache
 * planifiee fiable : RIEN ne viendrait remettre le prix normal a l'expiration.
 * Une promotion de trois jours baisserait le prix pour toujours, et personne
 * ne s'en apercevrait avant la vente.
 *
 * La colonne garde donc le prix NORMAL, la promotion porte le sien, et le prix
 * effectif se calcule a la requete — `App\Support\PrixEffectif`. L'expiration
 * devient alors un fait de lecture : passe la date, la fenetre est fermee, le
 * prix normal ressort tout seul. Rien a executer.
 *
 * ── POURQUOI DES COLONNES, ET NON UNE TABLE DE PROMOTIONS ─────────────────
 *
 * Une annonce n'a qu'UNE promotion a la fois : on ne superpose pas deux
 * remises sur le meme vehicule. Une table imposerait une jointure a CHAQUE
 * requete publique des trois catalogues, pour y lire au plus une ligne.
 *
 * L'historique des promotions passees n'a ete demande par personne, et
 * `partner_pricing` garde deja, fige, ce que le vendeur a propose.
 *
 * ── LES TROIS INVENTAIRES ─────────────────────────────────────────────────
 *
 * Vehicules, pieces ET accessoires : un partenaire vend les trois, et rien ne
 * justifierait qu'il puisse promouvoir un vehicule mais pas un jeu de tapis.
 *
 * ── LES ANNONCES EXISTANTES ───────────────────────────────────────────────
 *
 * Nulles, donc sans promotion. Leur prix est deja leur prix.
 */
return new class extends Migration
{
    private const TABLES = ['vehicles', 'parts', 'accessories'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                // Le prix pendant la promotion. Nul = pas de promotion.
                //
                // Meme precision que les colonnes de prix voisines : un prix
                // promotionnel qui ne se compare pas au prix normal dans le
                // meme type produirait des arrondis divergents entre le filtre
                // et l'affichage.
                $blueprint->decimal('deal_price', 14, 2)->nullable();

                // La fenetre. Les deux sont obligatoires des qu'il y a un
                // prix : une promotion sans fin n'est pas une promotion, c'est
                // un prix — et c'est le garde de la requete qui le dit, la
                // colonne restant nullable pour les annonces sans promotion.
                $blueprint->timestamp('deal_starts_at')->nullable();
                $blueprint->timestamp('deal_ends_at')->nullable();

                // ── POURQUOI CET INDEX, ET SUR CES DEUX COLONNES ──────────
                //
                // Toute requete publique qui calcule un prix effectif teste
                // `deal_price IS NOT NULL AND NOW() BETWEEN starts AND ends`.
                // L'index sur (deal_ends_at, deal_price) laisse le moteur
                // ecarter d'un coup les annonces sans promotion et les
                // promotions finies — qui seront la majorite, et de loin.
                $blueprint->index(['deal_ends_at', 'deal_price']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['deal_ends_at', 'deal_price']);
                $blueprint->dropColumn(['deal_price', 'deal_starts_at', 'deal_ends_at']);
            });
        }
    }
};
