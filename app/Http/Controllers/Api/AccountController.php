<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Models\OwnedVehicle;
use App\Services\FirebaseAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Suppression du compte client.
 *
 * Google Play l'exige de toute application qui permet d'en creer un : il doit
 * etre possible de demander la suppression depuis l'application elle-meme, pas
 * seulement par courriel. Rien ne le permettait.
 */
class AccountController extends Controller
{
    public function __construct(private readonly FirebaseAuthService $firebase)
    {
    }

    /**
     * DELETE /api/my/account
     *
     * L'ordre des operations n'est pas indifferent. On efface d'abord les
     * donnees locales, ensuite seulement le compte d'identification.
     *
     * Dans l'autre sens, un echec apres la suppression Firebase laisserait les
     * donnees en place sans que la personne puisse encore se connecter pour
     * redemander leur effacement — exactement ce que cette fonction existe pour
     * empecher. Dans cet ordre-ci, le meme echec laisse un compte vide, qui se
     * reconnecte et redemande la suppression.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $uid  = $request->attributes->get('firebase_uid');

        DB::transaction(function () use ($user, $uid) {
            // Les vehicules sont en suppression douce : l'effacement du compte
            // doit etre definitif, on force donc la suppression reelle.
            OwnedVehicle::withTrashed()->where('user_id', $user->id)->forceDelete();

            // Ces trois tables designent le client par son uid Firebase, sans
            // cle etrangere : rien ne les emporterait avec le compte.
            if ($uid !== null) {
                CustomerNeed::where('firebase_uid', $uid)->delete();
                ClientFcmToken::where('firebase_uid', $uid)->delete();
                AppNotification::where('recipient_type', 'client')
                    ->where('recipient_id', $uid)
                    ->delete();
            }

            // Les fiches client rattachees a des ventes ou des locations sont
            // detachees, pas supprimees : la comptabilite impose de conserver
            // les operations honorees. La cle etrangere est en nullOnDelete,
            // c'est donc automatique — on ne fait que le dire ici, pour que la
            // relecture n'ait pas a le deviner.

            $user->delete();
        });

        // Hors transaction : un appel reseau ne se defait pas, et le garder
        // dedans tiendrait la base ouverte le temps d'un aller-retour chez
        // Google.
        if ($uid !== null) {
            try {
                $this->firebase->deleteUser($uid);
            } catch (\Throwable $e) {
                // Les donnees sont parties, c'est l'essentiel. Le compte
                // d'identification survit : la personne pourra se reconnecter
                // sur un compte vide et redemander la suppression. On le trace
                // pour pouvoir le nettoyer a la main.
                Log::error('[Compte] Suppression Firebase impossible', [
                    'uid'    => $uid,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'message' => 'Votre compte et vos donnees ont ete supprimes.',
        ]);
    }
}
