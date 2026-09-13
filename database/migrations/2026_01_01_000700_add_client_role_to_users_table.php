<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ALL_ROLES = ['admin', 'manager', 'sales', 'mechanic', 'warehouse', 'viewer', 'client'];
    private const NO_CLIENT = ['admin', 'manager', 'sales', 'mechanic', 'warehouse', 'viewer'];

    public function up(): void
    {
        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlModify(self::ALL_ROLES),
            // Rien a faire : la migration 000500 lit UserRole::values(), donc la
            // contrainte creee sur une base neuve contient deja « client ».
            // Cette migration-ci ne sert qu'aux bases deja deployees — et
            // SQLite, qui ne sait pas modifier une contrainte en place, n'en
            // heberge aucune : c'est la base des tests, recreee a chaque fois.
            'sqlite'           => null,
            default            => $this->pgsqlModify(self::ALL_ROLES),
        };
    }

    public function down(): void
    {
        // Les comptes 'client' doivent être réassignés avant de revenir en arrière.
        DB::statement("UPDATE users SET role = 'viewer' WHERE role = 'client'");

        match (DB::getDriverName()) {
            'mysql', 'mariadb' => $this->mysqlModify(self::NO_CLIENT),
            'sqlite'           => null,
            default            => $this->pgsqlModify(self::NO_CLIENT),
        };
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function mysqlModify(array $values): void
    {
        $list = implode("','", $values);
        DB::statement("ALTER TABLE users MODIFY role ENUM('$list') NOT NULL DEFAULT 'viewer'");
    }

    private function pgsqlModify(array $values): void
    {
        // Sur PostgreSQL, Laravel crée un CHECK constraint nommé users_role_check.
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
        $list = implode("','", $values);
        DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('$list'))");
    }
};
