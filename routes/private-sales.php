<?php

use App\Http\Controllers\Api\PrivateVehicleListingController as Listings;
use App\Http\Controllers\Api\Admin\PrivateListingReviewController as Reviews;
use Illuminate\Support\Facades\Route;

Route::middleware('private-sales')->group(function () {
    Route::get('private-listings', [Listings::class, 'index']);
    Route::get('private-listings/{listing}', [Listings::class, 'show']);

    Route::middleware('firebase')->prefix('my/private-listings')->group(function () {
        Route::get('/', [Listings::class, 'mine']);
        Route::post('/', [Listings::class, 'store'])->middleware('throttle:public-write');
        Route::get('{listing}', [Listings::class, 'ownedShow']);
        Route::put('{listing}', [Listings::class, 'update']);
        Route::post('{listing}/submit', [Listings::class, 'submit'])->middleware('throttle:public-write');
        Route::post('{listing}/withdraw', [Listings::class, 'withdraw']);
        Route::post('{listing}/sold', [Listings::class, 'sold']);
        // Seau a part : `throttle:public-write` est cle sur l'adresse et
        // partage entre toutes les routes qui le portent, donc les dix photos
        // d'un depot y epuisaient le plafond avant l'envoi. Voir
        // AppServiceProvider, limiteur `depot-de-photos`.
        Route::post('{listing}/photos', [Listings::class, 'upload'])->middleware('throttle:depot-de-photos');
        Route::delete('{listing}/photos/{photo}', [Listings::class, 'deletePhoto'])->whereNumber('photo');
    });

    Route::middleware(['auth:sanctum', 'staff', 'capability:manage-catalog'])->prefix('private-listing-reviews')->group(function () {
        Route::get('/', [Reviews::class, 'index']);
        Route::post('{listing}/approve', [Reviews::class, 'approve']);
        Route::post('{listing}/reject', [Reviews::class, 'reject']);
    });
});
