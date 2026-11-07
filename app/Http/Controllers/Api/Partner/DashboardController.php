<?php

namespace App\Http\Controllers\Api\Partner;

use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\AppNotification;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\PartnerOwnership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Le tableau de bord d'un partenaire approuvé, en UN appel.
 *
 * L'application l'affiche à l'ouverture de l'espace partenaire. Sans cette
 * route, elle devrait paginer les trois inventaires et interroger la boîte
 * aux lettres : quatre allers-retours, dont trois chargent des annonces
 * entières — relations et médias compris — pour n'en garder que le nombre.
 * Sur le plan gratuit de Render, chaque aller-retour se paie, et le premier
 * du matin se paie vingt-cinq secondes.
 *
 * ── CE QUI EST COMPTÉ, ET CE QUI NE L'EST PAS ──────────────────────────────
 *
 * Les annonces du COMPTE partenaire, pas de l'utilisateur : c'est le compte
 * qui possède (`owner_partner_account_id`), comme partout dans l'inventaire.
 *
 * Les archivées n'entrent nulle part. Archiver, c'est supprimer en douceur
 * (`CatalogController::deleteProduct`), et la portée globale de `SoftDeletes`
 * les écarte d'elle-même : un partenaire qui a archivé ses dix annonces de
 * l'an dernier doit lire « 0 », pas « 10 ».
 *
 * « Publiée » veut dire `partner_published_at` posé — la définition des
 * boutons publier/dépublier, et aucune autre : ni l'état du véhicule, ni
 * `is_active`. Le tableau de bord dit ce que le partenaire a DÉCIDÉ, pas ce
 * que le catalogue public laisse voir ; les deux divergent dès qu'un véhicule
 * est vendu ou qu'une pièce est en rupture, et c'est au catalogue de le dire.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $accountId = PartnerOwnership::accountId($request->user());

        return response()->json([
            'listings' => [
                'vehicles' => $this->countListings(Vehicle::class, $accountId),
                'parts' => $this->countListings(Part::class, $accountId),
                'accessories' => $this->countListings(Accessory::class, $accountId),
            ],
            // Le même compte que `GET /partner/notifications/unread-count`, par
            // la même méthode : la pastille de la tuile et celle de la boîte
            // ne doivent jamais dire deux chiffres.
            'unread_notifications' => AppNotification::unreadCountForPartner($request->user()->id),
        ]);
    }

    /**
     * Une seule requête d'agrégat par type, et aucune collection chargée.
     *
     * `COUNT(colonne)` ne compte que les valeurs non nulles — du SQL standard,
     * que SQLite, MySQL et PostgreSQL lisent de la même façon — de sorte que
     * le total et les publiées sortent du même passage sur la table. Les non
     * publiées se déduisent plutôt que de se compter : trois chiffres qui ne
     * s'additionneraient pas seraient le premier signe d'un bogue, et le
     * calculer ici rend ce signe impossible.
     *
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $modelClass
     * @return array{total: int, published: int, unpublished: int}
     */
    private function countListings(string $modelClass, int $accountId): array
    {
        $counts = $modelClass::query()
            ->ownedByPartnerAccount($accountId)
            ->toBase()
            ->selectRaw('COUNT(*) AS total, COUNT(partner_published_at) AS published')
            ->first();

        $total = (int) $counts->total;
        $published = (int) $counts->published;

        return ['total' => $total, 'published' => $published, 'unpublished' => $total - $published];
    }
}
