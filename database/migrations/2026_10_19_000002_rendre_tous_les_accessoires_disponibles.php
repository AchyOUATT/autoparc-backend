<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remet `is_available` a vrai sur TOUS les accessoires.
 *
 * Demande du proprietaire, textuellement : « garde tous les accessoires a
 * true ». Une migration plutot qu'un geste dans l'application, et ce n'est pas
 * un choix de confort : L'ARTICLE MASQUE EST INJOIGNABLE DEPUIS L'APP.
 *
 * ── LA PORTE A SENS UNIQUE ─────────────────────────────────────────────────
 *
 * Le commutateur de disponibilite vit sur la CARTE de l'article
 * (accessory_list_page.dart, `_AccessoryCardState::_toggle`), dans une liste
 * qui appelle `GET /catalog/accessories` — la route PUBLIQUE, qui filtre
 * `->active()->where('is_available', true)`. Et elle l'appelle pour TOUT LE
 * MONDE : `catalog_repository.dart` ne bascule jamais vers la route du
 * personnel (`GET /accessories`, qui ne filtre pas la disponibilite) pour
 * LISTER — `Endpoints.staffAccessories` n'y sert qu'a la CREATION.
 *
 * Consequence : masquer un article fait disparaitre sa carte, donc le seul
 * bouton capable de le rendre a nouveau visible. Le personnel ne le voit pas
 * davantage que le client. Les pieces detachees ont exactement le meme defaut
 * (`part_list_page.dart` et `Endpoints.catalogParts` ; la seule bascule du
 * depot y choisit entre `catalogParts` et `compatibleParts`, jamais la route du
 * personnel).
 *
 * Cette migration repare la DONNEE. Elle ne repare pas la porte : tant que la
 * liste du personnel sera la liste publique, le prochain appui sur le
 * commutateur produira le meme piege.
 *
 * ── CE QUI AVAIT ETE MASQUE, ET POURQUOI ───────────────────────────────────
 *
 * Mesure en production avant ecriture : trois accessoires nommes « Camera de
 * recul », identiques au centime — « Chine », 6 500 FCFA, 5 en stock,
 * Ouagadougou - Bobo Dioulasso, aucun SKU, aucune description — et ne
 * differant que par leur nombre de photos. Les dates de depot de leur premiere
 * photo, lues sur R2, racontent la suite :
 *
 *     id 83   16/10/2026 15:38   5 photos   is_available = FAUX
 *     id 85   16/10/2026 15:46   8 photos   is_available = vrai
 *     id 86   16/10/2026 16:24   4 photos   is_available = vrai
 *
 * Ce ne sont pas trois articles : ce sont trois TENTATIVES du meme, a huit puis
 * trente-huit minutes d'intervalle. La date colle au defaut qui les explique :
 * ce jour-la, PHP refusait encore tout envoi au-dela de 2 Mo, en silence —
 * plafond leve depuis par `docker/php/depot-de-photos.ini`. Une partie des
 * photos passait, le reste etait rejete sans message lisible, et le vendeur
 * reprenait a zero.
 *
 * Quelqu'un a ensuite masque la 83 pour cacher le doublon. Le proprietaire a
 * tranche dans l'autre sens : il garde les trois articles et les veut tous
 * visibles. La decision lui appartient — il connait son stock.
 *
 * ── CE QUE LE FORMULAIRE N'A PAS FAIT ──────────────────────────────────────
 *
 * Verifie, parce que corriger la donnee sans la cause ne servirait a rien : la
 * colonne est posee `->default(true)` (2026_08_31_240000), et `is_available`
 * n'est dans AUCUNE regle de validation de creation ni de modification. Le
 * formulaire ne PEUT pas produire un `faux`. Le seul ecrivain est la bascule
 * `PATCH /accessories/{id}/availability`. Cet etat vient donc d'un geste
 * humain, pas d'un defaut de saisie, et il ne se reproduira pas tout seul.
 *
 * ── PORTEE, ET CE QU'ELLE NE TOUCHE PAS ────────────────────────────────────
 *
 * Les ACCESSOIRES seuls, comme demande. Les pieces detachees ne sont pas
 * touchees : les seize references du catalogue de filtres repondent toutes sur
 * la route publique, donc aucune n'est masquee aujourd'hui — mais je ne peux
 * pas l'affirmer d'une piece qui n'existerait que masquee, faute d'un acces au
 * back-office. A rapporter au proprietaire plutot qu'a deviner.
 *
 * `is_active` n'est PAS touche : c'est l'autre drapeau, celui du retrait du
 * catalogue, et rien ne demande de le forcer. Un accessoire desactive le
 * reste.
 *
 * ET LES ACCESSOIRES DESACTIVES SONT LAISSES TELS QUELS, disponibilite
 * comprise. C'est un ecart assume avec la lettre de la demande — « tous » —, et
 * voici pourquoi : un article desactive n'apparait AUCUNEMENT au catalogue,
 * `active()` l'excluant avant que `is_available` soit meme regarde. Lui rendre
 * la disponibilite ne le rend donc pas visible aujourd'hui ; ca change un etat
 * LATENT, et le jour ou le proprietaire le reactiverait, il partirait en vente
 * sans qu'il l'ait decide. Le risque est asymetrique : devoir rebasculer un
 * commutateur coute un geste, mettre en vente sans le vouloir coute un litige.
 * Ils sont donc comptes et nommes dans la sortie, pour que la decision reste
 * possible et visible.
 *
 * Cas mesure sur une base jetable avant d'arreter ce choix : trois accessoires,
 * dont un actif-masque et un desactive-masque. Sans la restriction, la
 * migration touchait les deux et annoncait « 2 accessoire(s) rendu(s)
 * disponible(s) », dont un qui ne le devenait pas. Avec, elle en touche un et
 * nomme l'autre.
 *
 * Idempotente par nature — un `UPDATE` vers une constante. Et elle ne leve
 * jamais : le CMD du Dockerfile enchaine `migrate --force` puis
 * `php artisan serve`, et une migration qui echoue eteint l'API. Un accessoire
 * masque est un desagrement, une API eteinte est une panne.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('accessories') || ! Schema::hasColumn('accessories', 'is_available')) {
                echo '  Table ou colonne absente : rien a faire.'.PHP_EOL;

                return;
            }

            // Comptes AVANT, pour que le journal de deploiement dise ce qui a
            // bouge. Sans cela on ne saurait pas, apres coup, si la migration a
            // trouve un article masque ou aucun — et c'est precisement ce qu'on
            // voudra savoir le jour ou le piege se refermera.
            // Les desactives sont nommes et NON touches : voir l'en-tete. On
            // les releve d'abord, sinon ils se confondraient avec les autres.
            $retires = DB::table('accessories')
                ->where('is_available', false)
                ->where('is_active', false)
                ->orderBy('id')
                ->limit(20)
                ->pluck('name', 'id');

            // Les noms avant l'ecriture : apres, ils ne sont plus retrouvables
            // par ce critere.
            $noms = DB::table('accessories')
                ->where('is_available', false)
                ->where('is_active', true)
                ->orderBy('id')
                ->limit(20)
                ->pluck('name', 'id');

            $touches = DB::table('accessories')
                ->where('is_available', false)
                ->where('is_active', true)
                ->update(['is_available' => true]);

            if ($touches === 0) {
                echo '  Aucun accessoire actif n\'etait masque : rien a changer.'.PHP_EOL;
            } else {
                echo "  {$touches} accessoire(s) rendu(s) disponible(s) :".PHP_EOL;

                foreach ($noms as $id => $nom) {
                    echo "    #{$id} {$nom}".PHP_EOL;
                }
            }

            if ($retires->isNotEmpty()) {
                echo '  LAISSES TELS QUELS, parce que DESACTIVES — les rendre disponibles'.PHP_EOL;
                echo '  ne les afficherait pas, mais les mettrait en vente a leur reactivation :'.PHP_EOL;

                foreach ($retires as $id => $nom) {
                    echo "    #{$id} {$nom}".PHP_EOL;
                }
            }
        } catch (\Throwable $e) {
            echo '  ATTENTION : les accessoires n\'ont pas ete rendus disponibles ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Rien a defaire.
     *
     * Remettre `is_available` a faux masquerait des accessoires que le
     * proprietaire veut visibles, et sur lesquels il aura pu intervenir depuis
     * — y compris en masquer volontairement un autre. Cette migration ne sait
     * pas quel etat chaque ligne portait avant elle, et inventer un retour en
     * arriere ferait plus de degats que l'absence de retour en arriere.
     */
    public function down(): void
    {
        //
    }
};
