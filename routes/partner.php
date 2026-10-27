<?php

use App\Http\Controllers\Api\Admin\PartnerApplicationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Partner\AccessoryController;
use App\Http\Controllers\Api\Partner\AuthController as PartnerAuthController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\Partner\MediaController;
use App\Http\Controllers\Api\Partner\PartController;
use App\Http\Controllers\Api\Partner\StockController;
use App\Http\Controllers\Api\Partner\VehicleController;
use Illuminate\Support\Facades\Route;

// `partner.space` ferme le groupe ENTIER tant que la chaine de livraison n'est
// pas prete — l'inscription d'abord, puisque c'est elle qui cree des comptes
// que personne ne peut encore approuver. Defaut : ferme. Le raisonnement et
// l'ordre dans lequel ouvrir sont dans `config/partenaires.php`.
//
// L'administration du personnel, plus bas, n'est PAS fermee : c'est par elle
// que passera l'approbation du premier partenaire.
Route::prefix('partner')->name('partner.')->middleware('partner.space')->group(function () {
    Route::post('register', [PartnerAuthController::class, 'register'])->middleware('throttle:public-write')->name('register');
    Route::post('login', [PartnerAuthController::class, 'login'])->middleware('throttle:login')->name('login');

    Route::middleware(['auth:sanctum', 'partner'])->group(function () {
        Route::get('me', [PartnerAuthController::class, 'me'])->name('me');
        Route::post('logout', [AuthController::class, 'logout'])->name('logout');

        // ── LA BOITE AUX LETTRES DU PARTENAIRE ────────────────────────────
        //
        // HORS de `partner.approved`, et c'est voulu : un compte en attente
        // doit pouvoir lire la notification qui lui annonce son approbation.
        // L'y enfermer la rendrait illisible par celui a qui elle s'adresse.
        //
        // La DIFFUSION, elle, ne vise que les comptes approuves — voir
        // `AppNotification::notifyAllPartners()`.
        Route::get('notifications', [NotificationController::class, 'indexPartner'])->name('notifications.index');
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCountPartner'])->name('notifications.unread');
        Route::post('notifications/read-all', [NotificationController::class, 'markAllReadPartner'])->name('notifications.read-all');
        Route::post('notifications/{notification}/read', [NotificationController::class, 'markReadPartner'])->name('notifications.read');

        Route::middleware('partner.approved')->group(function () {
            Route::apiResource('vehicles', VehicleController::class);
            Route::apiResource('parts', PartController::class);
            Route::apiResource('accessories', AccessoryController::class);

            Route::post('vehicles/{vehicle}/publish', [VehicleController::class, 'publish'])->name('vehicles.publish');
            Route::post('vehicles/{vehicle}/unpublish', [VehicleController::class, 'unpublish'])->name('vehicles.unpublish');
            Route::post('parts/{part}/publish', [PartController::class, 'publish'])->name('parts.publish');
            Route::post('parts/{part}/unpublish', [PartController::class, 'unpublish'])->name('parts.unpublish');
            Route::post('accessories/{accessory}/publish', [AccessoryController::class, 'publish'])->name('accessories.publish');
            Route::post('accessories/{accessory}/unpublish', [AccessoryController::class, 'unpublish'])->name('accessories.unpublish');

            Route::get('vehicles/{vehicle}/media', [MediaController::class, 'indexForVehicle'])->name('vehicles.media.index');
            Route::post('vehicles/{vehicle}/media', [MediaController::class, 'storeForVehicle'])->name('vehicles.media.store');
            Route::patch('vehicles/{vehicle}/media/reorder', [MediaController::class, 'reorderForVehicle'])->name('vehicles.media.reorder');
            Route::get('parts/{part}/media', [MediaController::class, 'indexForPart'])->name('parts.media.index');
            Route::post('parts/{part}/media', [MediaController::class, 'storeForPart'])->name('parts.media.store');
            Route::patch('parts/{part}/media/reorder', [MediaController::class, 'reorderForPart'])->name('parts.media.reorder');
            Route::get('accessories/{accessory}/media', [MediaController::class, 'indexForAccessory'])->name('accessories.media.index');
            Route::post('accessories/{accessory}/media', [MediaController::class, 'storeForAccessory'])->name('accessories.media.store');
            Route::patch('accessories/{accessory}/media/reorder', [MediaController::class, 'reorderForAccessory'])->name('accessories.media.reorder');
            Route::delete('media/{media}', [MediaController::class, 'destroy'])->name('media.destroy');
            Route::patch('media/{media}/cover', [MediaController::class, 'setCover'])->name('media.cover');

            Route::post('parts/{part}/stock', [StockController::class, 'adjustPart'])->name('parts.stock');
            Route::patch('parts/{part}/availability', [StockController::class, 'togglePartAvailability'])->name('parts.availability');
            Route::post('accessories/{accessory}/stock', [StockController::class, 'adjustAccessory'])->name('accessories.stock');
            Route::patch('accessories/{accessory}/availability', [StockController::class, 'toggleAccessoryAvailability'])->name('accessories.availability');
        });
    });
});

Route::middleware(['auth:sanctum', 'staff', 'capability:manage-partners'])
    ->prefix('partner-applications')
    ->group(function () {
        Route::get('/', [PartnerApplicationController::class, 'index']);
        Route::get('{partnerAccount}', [PartnerApplicationController::class, 'show']);
        Route::post('{partnerAccount}/approve', [PartnerApplicationController::class, 'approve']);
        Route::post('{partnerAccount}/reject', [PartnerApplicationController::class, 'reject']);
        Route::post('{partnerAccount}/suspend', [PartnerApplicationController::class, 'suspend']);
    });
