<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Journal append-only des reglages modifies depuis l'application. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_setting_changes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->index();
            $table->text('old_value')->nullable();
            $table->text('new_value');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_setting_changes');
    }
};
