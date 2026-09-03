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
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->id();

            // Destinataire : 'staff' (user_id) ou 'client' (firebase_uid)
            $table->enum('recipient_type', ['staff', 'client']);
            $table->string('recipient_id')->index(); // user_id (int as string) ou firebase_uid

            // Type sémantique — utilisé pour le deep link Flutter
            $table->string('type', 60)->index();
            // new_need | need_status_update | vehicle_match | new_order | tip

            // Contenu affiché
            $table->string('title');
            $table->text('body');

            // Données supplémentaires pour la navigation (vehicle_id, need_id, order_id…)
            $table->json('data')->nullable();

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['recipient_type', 'recipient_id', 'read_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_notifications');
    }
};
