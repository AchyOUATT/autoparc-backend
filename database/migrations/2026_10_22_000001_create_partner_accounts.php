<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PREVIOUS_ROLES = ['admin', 'manager', 'sales', 'mechanic', 'warehouse', 'viewer', 'client'];

    private const PARTNER_ROLES = [...self::PREVIOUS_ROLES, 'partner'];

    private const INVENTORY_TABLES = ['vehicles', 'parts', 'accessories'];

    public function up(): void
    {
        $this->modifyRoles(self::PARTNER_ROLES);

        Schema::create('partner_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->restrictOnDelete();
            $table->string('company_name', 180);
            $table->string('contact_name', 160);
            $table->string('phone', 40);
            $table->string('whatsapp', 40)->nullable();
            $table->string('status', 20)->default('pending')->index();
            $table->text('review_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
        });

        foreach (self::INVENTORY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                // NULL designe le stock interne : supprimer un compte ne doit
                // jamais transformer ses annonces en annonces du personnel.
                $table->foreignId('owner_partner_account_id')->nullable()
                    ->constrained('partner_accounts')->restrictOnDelete();
                $table->index('owner_partner_account_id');
                $table->timestamp('partner_published_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Aucun reclassement vers viewer : ce role ouvre le back-office.
        // Le refus intervient avant toute modification du schema ou des donnees.
        if (DB::table('users')->where('role', 'partner')->exists()
            || DB::table('partner_accounts')->exists()
            || collect(self::INVENTORY_TABLES)->contains(
                fn (string $name) => DB::table($name)->whereNotNull('owner_partner_account_id')->exists()
            )) {
            throw new RuntimeException(
                'Retour arriere refuse : des comptes ou annonces partenaires existent. '
                .'Prevoir une migration explicite de leurs donnees avant de retirer ce schema.'
            );
        }

        $this->modifyRoles(self::PREVIOUS_ROLES);

        foreach (self::INVENTORY_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropForeign(['owner_partner_account_id']);
                $table->dropIndex(['owner_partner_account_id']);
                $table->dropColumn(['owner_partner_account_id', 'partner_published_at']);
            });
        }

        Schema::dropIfExists('partner_accounts');
    }

    private function modifyRoles(array $roles): void
    {
        $values = implode("','", $roles);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                DB::statement("ALTER TABLE users MODIFY role ENUM('$values') NOT NULL DEFAULT 'viewer'");
                break;

            case 'pgsql':
                DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_role_check');
                DB::statement("ALTER TABLE users ADD CONSTRAINT users_role_check CHECK (role IN ('$values'))");
                break;

            case 'sqlite':
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'users')->value('sql');
                $alreadyAllowsPartners = str_contains((string) $sql, "'partner'");

                if ($alreadyAllowsPartners === in_array('partner', $roles, true)) {
                    break;
                }

                // Une ancienne base SQLite a un CHECK, elle aussi. Laravel
                // reconstruit la table lors du change(). Des FK actives dans
                // une transaction empecheraient leur suspension et pourraient
                // declencher des cascades pendant le remplacement de users.
                if (DB::connection()->transactionLevel() > 0) {
                    throw new RuntimeException(
                        'La modification des roles SQLite doit etre executee hors transaction afin de preserver les relations.'
                    );
                }

                Schema::withoutForeignKeyConstraints(function () use ($roles) {
                    Schema::table('users', function (Blueprint $table) use ($roles) {
                        $table->enum('role', $roles)->default('viewer')->change();
                    });
                });
                break;

            default:
                throw new RuntimeException('Moteur de base non pris en charge pour les comptes partenaires.');
        }
    }
};
