<?php

use App\Support\CorrigerPe0218211Aftermarket;
use Illuminate\Database\Migrations\Migration;

/**
 * Recalifie le capteur MAP PE02-18-211 en pièce adaptable (Chine).
 *
 * Ne touche ni au prix, ni au stock, ni au numéro OEM, ni aux
 * compatibilités. Idempotent. Le fabricant Mazda éventuellement
 * créé plus tôt n'est pas supprimé.
 */
return new class extends Migration
{
    public function up(): void
    {
        CorrigerPe0218211Aftermarket::run();
    }

    public function down(): void
    {
        // Recalification commerciale déjà utilisable ; le replay est idempotent.
    }
};
