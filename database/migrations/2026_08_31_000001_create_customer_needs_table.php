<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_needs', function (Blueprint $table) {
            $table->id();

            // Ce que le client cherche
            $table->enum('type', ['vehicle', 'part', 'accessory'])->default('part');
            $table->text('description');            // description libre de ce qu'il cherche
            $table->decimal('budget_max', 12, 2)->nullable(); // budget maximum optionnel
            $table->string('currency', 3)->default('XOF');

            // Contact (optionnel si client Firebase connecté)
            $table->string('contact_name', 120)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 180)->nullable();

            // Lien avec un compte client Firebase si connecté
            $table->string('firebase_uid')->nullable()->index();

            // Suivi par le staff
            $table->enum('status', ['pending', 'contacted', 'fulfilled', 'cancelled'])
                  ->default('pending')
                  ->index();
            $table->text('staff_notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_needs');
    }
};
