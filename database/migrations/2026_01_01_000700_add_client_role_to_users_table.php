<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Colonne enum MySQL : alteration en SQL brut (pas de doctrine/dbal requis).
        DB::statement(
            "ALTER TABLE users MODIFY role ENUM('admin','manager','sales','mechanic','warehouse','viewer','client') NOT NULL DEFAULT 'viewer'"
        );
    }

    public function down(): void
    {
        // Les comptes 'client' doivent etre reassignes avant de revenir en arriere.
        DB::statement("UPDATE users SET role = 'viewer' WHERE role = 'client'");
        DB::statement(
            "ALTER TABLE users MODIFY role ENUM('admin','manager','sales','mechanic','warehouse','viewer') NOT NULL DEFAULT 'viewer'"
        );
    }
};
