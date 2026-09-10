<?php

use App\Http\Controllers\Api\AccessoryController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\AccessoryOrderController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BrandController;
use App\Http\Controllers\Api\CatalogSyncController;
use App\Http\Controllers\Api\CompatibilityController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerNeedController;
use App\Http\Controllers\Api\FcmTokenController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\OemNumberController;
use App\Http\Controllers\Api\GarageCompatibilityController;
use App\Http\Controllers\Api\VinDecodeController;
use App\Http\Controllers\Api\OwnedVehicleController;
use App\Http\Controllers\Api\PartController;
use App\Http\Controllers\Api\PartnerController;
use App\Http\Controllers\Api\PartOrderController;
use App\Http\Controllers\Api\ReferenceController;
use App\Http\Controllers\Api\RentalController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\VehicleController;
use App\Http\Controllers\Api\VehicleFaultController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API AutoParc - vente / location de vehicules et pieces detachees
|--------------------------------------------------------------------------
*/

Route::post('login', [AuthController::class, 'login']); // staff uniquement : les clients passent par Firebase Auth cote Flutter


/* ---------------------- Catalogue public (lecture) ---------------------- */
Route::prefix('catalog')->group(function () {
    Route::get('vehicles', [VehicleController::class, 'index']);
    Route::get('vehicles/{vehicle}', [VehicleController::class, 'show']);
    Route::get('parts', [PartController::class, 'index']);
    Route::get('parts/{part}', [PartController::class, 'show']);

    // Recherche de pieces par criteres vehicule (modele + annee + finition + motorisation)
    Route::get('compatible-parts', [CompatibilityController::class, 'partsForCriteria']);

    Route::get('brands', [BrandController::class, 'index']);
    Route::get('brands/{brand}/models', [BrandController::class, 'models']);
    Route::get('references', [ReferenceController::class, 'index']);

    // Accessoires (actifs et en stock uniquement)
    Route::get('accessories', [AccessoryController::class, 'catalog']);
    Route::get('accessories/{accessory}', [AccessoryController::class, 'catalogShow']);

    // Cache local Flutter (SQLite) : marques/modeles/finitions/motorisations, jamais prix ni stock.
    Route::get('sync', [CatalogSyncController::class, 'index']);

    // Décodage VIN : pré-remplit le formulaire "Mon garage" + alimente la recherche de pièces.
    Route::get('vin-decode/{vin}', [VinDecodeController::class, 'decode']);

    // Pays (pour les sélecteurs d'origine import, d'immatriculation, etc.)
    Route::get('countries', fn () => \App\Models\Country::orderBy('name')->get(['id', 'name', 'iso2']));

    // Catégories de pièces détachées (pour les formulaires staff)
    // `parent_id` permet a l'application de ne proposer que les 9 categories
    // racines : les 89 lignes de l'arbre complet feraient une barre de filtres
    // interminable.
    Route::get('part-categories', fn () => \App\Models\PartCategory::orderBy('name')->get(['id', 'parent_id', 'name']));

    // Fabricants / équipementiers (pour les formulaires staff)
    Route::get('manufacturers', fn () => \App\Models\Manufacturer::orderBy('name')->get(['id', 'name']));
});

/* ---------------- Recherche par numero OEM (coeur metier) --------------- */
Route::prefix('oem')->group(function () {
    Route::get('{number}/vehicles', [CompatibilityController::class, 'vehiclesForOem']);
    Route::get('{number}/models', [CompatibilityController::class, 'modelsForOem']);
    Route::get('{number}/cross-references', [CompatibilityController::class, 'crossReferences']);
});

/* ------------------- Besoins clients (public) --------------------------- */
Route::post('needs',     [CustomerNeedController::class, 'store']);
Route::get('needs/mine', [CustomerNeedController::class, 'mine']);  // besoins du client connecté

/* --------- Token FCM client (Firebase auth ou visiteur identifié) -------- */
// Pas besoin d'auth — le firebase_uid est fourni dans le body
Route::post('fcm-token/client', [FcmTokenController::class, 'storeClient']);

/* ------------------- Commun a tout utilisateur connecte ----------------- */
Route::middleware('auth:sanctum')->group(function () {
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
});

/* --------------- Zone client : "mon garage" (auth Firebase) ------------- */
Route::middleware('firebase')->prefix('my')->group(function () {
    // `parameters` est indispensable : apiResource nommerait le parametre
    // {vehicle}, alors que le controleur attend $ownedVehicle. Sans
    // correspondance, Laravel n'injecte aucun modele — il en construit un vide,
    // dont user_id vaut null, et la policy refuse tout : show, update et
    // destroy repondaient 403 quel que soit le proprietaire.
    Route::apiResource('vehicles', OwnedVehicleController::class)
        ->parameters(['vehicles' => 'ownedVehicle'])
        ->names('my-vehicles');
    Route::get('vehicles/{ownedVehicle}/compatible-parts', [CompatibilityController::class, 'partsForOwnedVehicle']);

    // Compatibilité inverse : pour une pièce donnée, quels véhicules du garage sont compatibles ?
    Route::get('parts/{part}/garage-compatibility', [GarageCompatibilityController::class, 'checkPart']);

    // Notifications client
    Route::get('notifications',              [NotificationController::class, 'indexClient']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCountClient']);
    Route::post('notifications/read-all',    [NotificationController::class, 'markAllReadClient']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markReadClient']);
});

/* ------------------- Zone back-office (staff uniquement) ---------------- */
//
// `staff` ne distingue que le personnel des clients : sans ce qui suit, un
// compte « viewer » creait et supprimait des vehicules, un magasinier
// enregistrait des ventes, et n'importe quel employe pouvait notifier tous
// les clients de l'application.
//
// Les capacites (App\Enums\UserRole::can) se posent ici, route par route,
// plutot que dans chaque controleur : la matrice des droits se lit d'un seul
// tenant, et une route ajoutee sans capacite se remarque a la lecture.
//
// La consultation reste ouverte a tout le personnel ; seules les ecritures
// sont filtrees.
Route::middleware(['auth:sanctum', 'staff'])->group(function () {

    Route::get('dashboard', [DashboardController::class, 'index']);

    // --- Referentiels
    Route::apiResource('brands', BrandController::class)
        ->except(['destroy'])
        ->middlewareFor(['store', 'update'], 'capability:manage-catalog');
    Route::post('brands/{brand}/models', [BrandController::class, 'storeModel'])
        ->middleware('capability:manage-catalog');
    Route::post('vehicle-models/{vehicleModel}/trims', [BrandController::class, 'storeTrim'])
        ->middleware('capability:manage-catalog');

    // --- Vehicules
    Route::apiResource('vehicles', VehicleController::class)
        ->middlewareFor(['store', 'update'], 'capability:manage-catalog')
        ->middlewareFor('destroy', 'capability:delete-catalog');
    Route::put('vehicles/{vehicle}/features', [VehicleController::class, 'syncFeatures'])
        ->middleware('capability:manage-catalog');
    Route::post('vehicles/{vehicle}/publish',   [VehicleController::class, 'publish'])
        ->middleware('capability:manage-catalog');
    Route::post('vehicles/{vehicle}/unpublish', [VehicleController::class, 'unpublish'])
        ->middleware('capability:manage-catalog');
    Route::post('vehicles/{vehicle}/register',  [VehicleController::class, 'register'])
        ->middleware('capability:manage-catalog');
    Route::get('vehicles/{vehicle}/compatible-parts', [CompatibilityController::class, 'partsForVehicle']);
    Route::get('vehicles/{vehicle}/rental-availability', [RentalController::class, 'availability']);

    // --- Pannes declarees (le mecanicien y a acces, pas le commercial)
    Route::get('vehicles/{vehicle}/faults', [VehicleFaultController::class, 'index']);
    Route::middleware('capability:manage-faults')->group(function () {
        Route::post('vehicles/{vehicle}/faults', [VehicleFaultController::class, 'store']);
        Route::put('vehicles/{vehicle}/faults/{fault}', [VehicleFaultController::class, 'update']);
        Route::post('vehicles/{vehicle}/faults/{fault}/resolve', [VehicleFaultController::class, 'resolve']);
        Route::delete('vehicles/{vehicle}/faults/{fault}', [VehicleFaultController::class, 'destroy']);
    });

    // --- Partenaires (annuaire)
    Route::apiResource('partners', PartnerController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'capability:manage-partners');

    // --- Pieces detachees
    Route::apiResource('parts', PartController::class)
        ->middlewareFor(['store', 'update'], 'capability:manage-catalog')
        ->middlewareFor('destroy', 'capability:delete-catalog');
    Route::post('parts/{part}/stock',               [PartController::class, 'adjustStock'])
        ->middleware('capability:manage-stock');
    Route::patch('parts/{part}/availability',        [PartController::class, 'toggleAvailability'])
        ->middleware('capability:manage-stock');
    Route::get('parts/{part}/partners',              [PartnerController::class, 'indexForPart']);
    Route::post('parts/{part}/partners',             [PartnerController::class, 'attachToPart'])
        ->middleware('capability:manage-partners');
    Route::delete('parts/{part}/partners/{partner}', [PartnerController::class, 'detachFromPart'])
        ->middleware('capability:manage-partners');

    // --- Accessoires
    Route::apiResource('accessories', AccessoryController::class)
        ->middlewareFor(['store', 'update'], 'capability:manage-catalog')
        ->middlewareFor('destroy', 'capability:delete-catalog');
    Route::post('accessories/{accessory}/stock',             [AccessoryController::class, 'adjustStock'])
        ->middleware('capability:manage-stock');
    Route::patch('accessories/{accessory}/availability',      [AccessoryController::class, 'toggleAvailability'])
        ->middleware('capability:manage-stock');
    Route::get('accessories/{accessory}/partners',            [PartnerController::class, 'indexForAccessory']);
    Route::post('accessories/{accessory}/partners',           [PartnerController::class, 'attachToAccessory'])
        ->middleware('capability:manage-partners');
    Route::delete('accessories/{accessory}/partners/{partner}', [PartnerController::class, 'detachFromAccessory'])
        ->middleware('capability:manage-partners');

    // --- Commandes d'accessoires
    Route::apiResource('accessory-orders', AccessoryOrderController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'capability:manage-orders');
    Route::post('accessory-orders/{accessoryOrder}/confirm', [AccessoryOrderController::class, 'confirm'])
        ->middleware('capability:manage-orders');
    Route::post('accessory-orders/{accessoryOrder}/cancel', [AccessoryOrderController::class, 'cancel'])
        ->middleware('capability:manage-orders');

    // --- Numeros OEM
    Route::apiResource('oem-numbers', OemNumberController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'capability:manage-catalog');
    Route::get('oem-numbers/{oemNumber}/parts', [OemNumberController::class, 'parts']);
    Route::post('oem-numbers/{oemNumber}/supersede', [OemNumberController::class, 'supersede'])
        ->middleware('capability:manage-catalog');

    // --- Clients
    Route::apiResource('customers', CustomerController::class)
        ->middlewareFor(['store', 'update', 'destroy'], 'capability:manage-customers');

    // --- Token FCM staff (chacun enregistre son propre appareil)
    Route::post('fcm-token', [FcmTokenController::class, 'storeStaff']);

    // --- Notifications staff (chacun gere les siennes)
    Route::get('notifications',              [NotificationController::class, 'indexStaff']);
    Route::get('notifications/unread-count', [NotificationController::class, 'unreadCountStaff']);
    Route::post('notifications/read-all',    [NotificationController::class, 'markAllReadStaff']);
    Route::post('notifications/{notification}/read', [NotificationController::class, 'markReadStaff']);

    // --- Broadcast tip (conseil) vers tous les clients
    Route::post('broadcast-tip', [NotificationController::class, 'broadcastTip'])
        ->middleware('capability:broadcast');

    // --- Besoins clients (lecture + mise à jour statut)
    Route::get('needs',          [CustomerNeedController::class, 'index']);
    Route::put('needs/{need}',   [CustomerNeedController::class, 'update'])
        ->middleware('capability:manage-customers');

    // --- Médias (photos véhicules / pièces / accessoires)
    Route::get('vehicles/{vehicle}/media',             [MediaController::class, 'indexForVehicle']);
    Route::get('parts/{part}/media',                   [MediaController::class, 'indexForPart']);
    Route::get('accessories/{accessory}/media',        [MediaController::class, 'indexForAccessory']);
    Route::middleware('capability:manage-catalog')->group(function () {
        Route::post('vehicles/{vehicle}/media',            [MediaController::class, 'storeForVehicle']);
        Route::patch('vehicles/{vehicle}/media/reorder',   [MediaController::class, 'reorderForVehicle']);
        Route::post('parts/{part}/media',                  [MediaController::class, 'storeForPart']);
        Route::patch('parts/{part}/media/reorder',         [MediaController::class, 'reorderForPart']);
        Route::post('accessories/{accessory}/media',       [MediaController::class, 'storeForAccessory']);
        Route::patch('accessories/{accessory}/media/reorder', [MediaController::class, 'reorderForAccessory']);
        Route::delete('media/{media}',                     [MediaController::class, 'destroy']);
        Route::patch('media/{media}/cover',                [MediaController::class, 'setCover']);
    });

    // --- Ventes
    Route::apiResource('sales', SaleController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'capability:manage-orders');

    // --- Locations
    Route::apiResource('rentals', RentalController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'capability:manage-orders');
    Route::post('rentals/{rental}/checkout', [RentalController::class, 'checkout'])
        ->middleware('capability:manage-orders');
    Route::post('rentals/{rental}/checkin', [RentalController::class, 'checkin'])
        ->middleware('capability:manage-orders');

    // --- Commandes de pieces
    Route::apiResource('part-orders', PartOrderController::class)
        ->only(['index', 'store', 'show'])
        ->middlewareFor('store', 'capability:manage-orders');
    Route::post('part-orders/{partOrder}/confirm', [PartOrderController::class, 'confirm'])
        ->middleware('capability:manage-orders');
    Route::post('part-orders/{partOrder}/cancel', [PartOrderController::class, 'cancel'])
        ->middleware('capability:manage-orders');
});
