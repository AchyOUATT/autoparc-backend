<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emplacement par defaut d'un compte partenaire, et ville / lieu
 * propres a chaque annonce (vehicule, piece, accessoire).
 *
 * `locations` reste le referentiel des agences AutoParc. Un partenaire
 * n'y a pas sa place : son lieu est un texte, comme la ville d'une
 * annonce de particulier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('whatsapp');
            $table->string('address', 120)->nullable()->after('city');
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('site');
            $table->index('city');
        });

        Schema::table('parts', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('location_id');
            $table->string('site', 120)->nullable()->after('city');
            $table->index('city');
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->string('city', 100)->nullable()->after('location_id');
            $table->string('site', 120)->nullable()->after('city');
            $table->index('city');
        });
    }

    public function down(): void
    {
        Schema::table('accessories', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn(['city', 'site']);
        });
        Schema::table('parts', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn(['city', 'site']);
        });
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropIndex(['city']);
            $table->dropColumn('city');
        });
        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->dropColumn(['city', 'address']);
        });
    }
};
