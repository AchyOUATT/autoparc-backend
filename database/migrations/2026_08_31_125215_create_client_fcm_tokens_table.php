<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('client_fcm_tokens', function (Blueprint $table) {
            $table->id();
            // UID Firebase du client (pas forcément inscrit en base)
            $table->string('firebase_uid')->index();
            // Token FCM de l'appareil (un client peut avoir plusieurs appareils)
            $table->string('fcm_token')->unique();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('client_fcm_tokens');
    }
};
