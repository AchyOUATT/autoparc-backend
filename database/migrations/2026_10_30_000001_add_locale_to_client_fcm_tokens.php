<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_fcm_tokens', function (Blueprint $table) {
            $table->string('locale', 2)->nullable()->default('fr');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('fcm_locale', 2)->nullable()->default('fr');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('fcm_locale');
        });
        Schema::table('client_fcm_tokens', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
