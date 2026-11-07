<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AppNotification;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\PartnerMedia;
use App\Support\PartnerOwnership;
use App\Support\PrixEffectif;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le tableau de bord d'un partenaire approuvé : UN appel, QUATRE requêtes.
 *
 * L'application l'affiche à l'ouverture de l'espace partenaire. Sans cette
 * route, elle devrait paginer les trois inventaires — relations et médias
 * chargés pour n'en garder que des nombres — puis interroger la boîte aux
 * lettres. Sur le plan gratuit de Render, chaque aller-retour se paie, et le
 * premier du matin se paie vingt-cinq secondes.
 *
 * ── LES COMPTEURS, UN PAR LIGNE ────────────────────────────────────────────
 *
 * Tous portent sur les annonces NON archivées du COMPTE partenaire. Archiver,
 * c'est supprimer en douceur (`CatalogController::deleteProduct`), et la
 * portée globale de `SoftDeletes` les écarte d'elle-même. « Publiée » veut
 * dire `partner_published_at` posé : la définition des boutons publier et
 * dépublier, et aucune autre.
 *
 *   total                  toutes les annonces
 *   published              publiées
 *   drafts                 total − published
 *   without_photo          publiées sans AUCUNE image de galerie (`PartnerMedia::scope`)
 *   on_promotion           publiées, promotion en cours (`PrixEffectif`) ET prix normal présent
 *   promotion_ending_soon  on_promotion dont `deal_ends_at` tombe dans les trois jours
 *   out_of_stock           pièces et accessoires publiés : quantité ≤ 0 OU indisponibles
 *   low_stock              pièces et accessoires publiés : quantité > 0 et ≤ au seuil d'alerte
 *
 * ── CE QUE « PUBLIÉE » NE VEUT PAS DIRE ────────────────────────────────────
 *
 * Ni l'état du véhicule, ni `is_active` : un véhicule vendu que le partenaire
 * n'a pas dépublié compte ici comme publié, alors que `scopeVisibleToPublic`
 * le cache au catalogue. Le tableau de bord dit ce que le partenaire a
 * DÉCIDÉ ; c'est au catalogue de dire ce que l'acheteur voit. Les confondre
 * ferait disparaître de son tableau une annonce qu'il croit en ligne, sans
 * rien qui lui dise pourquoi.
 *
 * « En cours » est la définition de la vitrine (`PrixEffectif::conditionSql`),
 * complétée comme dans `PartnerListingPresentation::promotion()` par la
 * présence du prix normal : une promotion que la carte ne montre pas n'est
 * pas en cours aux yeux de l'acheteur, elle ne doit pas l'être ici non plus.
 */
class DashboardController extends Controller
{
    /** Une promotion qui finit dans ce délai est « bientôt terminée ». */
    private const JOURS_AVANT_LA_FIN = 3;

    public function __invoke(Request $request): JsonResponse
    {
        $accountId = PartnerOwnership::accountId($request->user());

        return response()->json([
            'listings' => [
                'vehicles' => $this->countListings(new Vehicle, $accountId, 'sale_price'),
                'parts' => $this->countListings(new Part, $accountId, 'selling_price', stock: true),
                'accessories' => $this->countListings(new Accessory, $accountId, 'selling_price', stock: true),
            ],
            // Le même compte que `GET /partner/notifications/unread-count`, par
            // la même méthode : la pastille de la tuile et celle de la boîte
            // ne doivent jamais dire deux chiffres.
            'unread_notifications' => AppNotification::unreadCountForPartner($request->user()->id),
        ]);
    }

    /**
     * Tous les compteurs d'un type en UNE requête d'agrégat, sans charger une
     * seule annonce.
     *
     * `COUNT(colonne)` ne compte que les valeurs non nulles, et chaque
     * `SUM(CASE WHEN … THEN 1 ELSE 0 END)` compte les lignes qui satisfont
     * une condition : du SQL standard, que SQLite (les bancs) et PostgreSQL
     * (Render) lisent de la même façon. Huit compteurs en huit requêtes
     * auraient parcouru huit fois la même table pour le même résultat.
     *
     * `drafts` se déduit plutôt que de se compter : trois chiffres qui ne
     * s'additionneraient pas seraient le premier signe d'un bogue, et le
     * calculer ici rend ce signe impossible.
     *
     * @param  string  $normalPrice  `sale_price` ou `selling_price`
     * @return array<string, int>
     */
    private function countListings(Model $model, int $accountId, string $normalPrice, bool $stock = false): array
    {
        $isPublished = 'partner_published_at IS NOT NULL';
        $isOnPromotion = $isPublished.' AND '.PrixEffectif::conditionSql().' AND '.$normalPrice.' IS NOT NULL';
        $gallery = $this->galleryOf($model);

        $query = $model->newQuery()
            ->ownedByPartnerAccount($accountId)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('COUNT(partner_published_at) AS published')
            ->selectRaw(self::nombreDe($isPublished.' AND NOT EXISTS ('.$gallery->toSql().')').' AS without_photo', $gallery->getBindings())
            ->selectRaw(self::nombreDe($isOnPromotion).' AS on_promotion', PrixEffectif::liaisons())
            ->selectRaw(self::nombreDe($isOnPromotion.' AND deal_ends_at <= ?').' AS promotion_ending_soon',
                [...PrixEffectif::liaisons(), now()->addDays(self::JOURS_AVANT_LA_FIN)]);

        if ($stock) {
            // `NOT is_available` plutôt que `= 0` ou `= false` : PostgreSQL
            // refuse de comparer un booléen à un entier, et SQLite n'a pas de
            // booléen. La négation, elle, se lit partout de la même façon.
            $query->selectRaw(self::nombreDe($isPublished.' AND (stock_quantity <= 0 OR NOT is_available)').' AS out_of_stock')
                ->selectRaw(self::nombreDe($isPublished.' AND stock_quantity > 0 AND stock_quantity <= stock_alert_threshold').' AS low_stock');
        }

        $row = $query->toBase()->first();
        $counts = ['total' => (int) $row->total, 'published' => (int) $row->published];
        $counts += [
            'drafts' => $counts['total'] - $counts['published'],
            'without_photo' => (int) $row->without_photo,
            'on_promotion' => (int) $row->on_promotion,
            'promotion_ending_soon' => (int) $row->promotion_ending_soon,
        ];
        if ($stock) {
            $counts += ['out_of_stock' => (int) $row->out_of_stock, 'low_stock' => (int) $row->low_stock];
        }

        return $counts;
    }

    /**
     * Les images que l'inventaire montre pour une annonce de ce type — le
     * même filtre que `CatalogController` (`PartnerMedia::scope`), corrélé à
     * la ligne comptée. Un document, ou un fichier sur un disque privé, n'est
     * pas une photo : une annonce qui n'a que cela est bien « sans photo ».
     */
    private function galleryOf(Model $model): Builder
    {
        return PartnerMedia::scope(Media::query())
            ->where('media.mediable_type', $model->getMorphClass())
            ->whereColumn('media.mediable_id', $model->qualifyColumn('id'))
            ->toBase();
    }

    /** Le nombre de lignes qui satisfont la condition, dans un agrégat. */
    private static function nombreDe(string $condition): string
    {
        return 'SUM(CASE WHEN '.$condition.' THEN 1 ELSE 0 END)';
    }
}
