<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const AVANT = ['manual', 'automatic', 'cvt', 'dual_clutch', 'single_speed'];

    private const APRES = [...self::AVANT, 'automated_manual', 'e_cvt'];

    public function up(): void
    {
        $this->changerEnum(self::APRES);
    }

    public function down(): void
    {
        // Une ancienne application ne sait pas relire ces deux codes, y
        // compris dans les annonces privees dont la colonne est une string.
        // Ne pas les remplacer par « automatic » ou « cvt » sans decision.
        foreach (['vehicles', 'private_vehicle_listings'] as $table) {
            if (DB::table($table)->whereIn('transmission', ['automated_manual', 'e_cvt'])->exists()) {
                throw new RuntimeException(
                    "Retour arriere refuse : $table contient une transmission robotisee ou e-CVT. "
                    .'Reclasser explicitement ces donnees avant de retirer leurs modes.'
                );
            }
        }

        $this->changerEnum(self::AVANT);
    }

    /** @param list<string> $valeurs */
    private function changerEnum(array $valeurs): void
    {
        $liste = implode("','", $valeurs);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                DB::statement("ALTER TABLE vehicles MODIFY transmission ENUM('$liste') NULL DEFAULT NULL");
                break;

            case 'pgsql':
                DB::transaction(function () use ($liste): void {
                    DB::statement('ALTER TABLE vehicles DROP CONSTRAINT IF EXISTS vehicles_transmission_check');
                    DB::statement("ALTER TABLE vehicles ADD CONSTRAINT vehicles_transmission_check CHECK (transmission IN ('$liste'))");
                });
                break;

            case 'sqlite':
                if (DB::connection()->transactionLevel() > 0) {
                    throw new RuntimeException(
                        'La modification de vehicles.transmission sous SQLite doit s executer hors transaction '
                        .'afin de preserver les relations existantes.'
                    );
                }

                $this->changerEnumSqlite($liste);
                break;

            default:
                throw new RuntimeException('Moteur de base non pris en charge pour les modes de transmission.');
        }
    }

    private function changerEnumSqlite(string $liste): void
    {
        // Blueprint::change reconstruit depuis PRAGMA table_info, qui ne
        // restitue pas les CHECK des autres colonnes. Garder la definition
        // complete evite de supprimer les contraintes de statut/disponibilite.
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'vehicles')->value('sql');
        if (! is_string($sql)) {
            throw new RuntimeException('Definition SQLite de vehicles introuvable.');
        }

        $check = "CHECK (\"transmission\" IN ('$liste'))";
        $sql = preg_replace(
            '/check\s*\(\s*(?:"transmission"|`transmission`|\[transmission\]|transmission)\s+in\s*\([^)]*\)\s*\)/i',
            $check,
            $sql,
            -1,
            $checksRemplaces
        );
        // Une ancienne reconstruction Laravel a pu deja retirer ce CHECK.
        if ($checksRemplaces === 0) {
            $sql = preg_replace('/\)\s*$/', ', '.$check.')', $sql, 1, $checksAjoutes);
            if ($checksAjoutes !== 1) {
                throw new RuntimeException('Definition SQLite de vehicles non reconnue.');
            }
        }
        $sql = preg_replace(
            '/^(\s*create\s+table\s+)(?:"vehicles"|`vehicles`|\[vehicles\]|vehicles)(?=\s*\()/i',
            '$1"__transmission_vehicles"',
            $sql,
            1,
            $tablesRemplacees
        );
        if ($tablesRemplacees !== 1) {
            throw new RuntimeException('Nom SQLite de vehicles non reconnu.');
        }

        $objets = DB::table('sqlite_master')->where('tbl_name', 'vehicles')
            ->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->pluck('sql');
        $colonnes = collect(Schema::getColumns('vehicles'))->whereNull('generation')
            ->map(fn (array $colonne) => '"'.str_replace('"', '""', $colonne['name']).'"')->implode(', ');
        $sequence = DB::table('sqlite_sequence')->where('name', 'vehicles')->value('seq');
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');

        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($sql, $colonnes, $objets, $sequence): void {
                DB::statement($sql);
                DB::statement("INSERT INTO \"__transmission_vehicles\" ($colonnes) SELECT $colonnes FROM \"vehicles\"");
                DB::statement('DROP TABLE "vehicles"');
                DB::statement('ALTER TABLE "__transmission_vehicles" RENAME TO "vehicles"');
                // Le plus grand identifiant peut avoir ete supprime : copier
                // les lignes ne suffit pas a conserver AUTOINCREMENT.
                if ($sequence !== null) {
                    $copie = DB::table('sqlite_sequence')->where('name', 'vehicles')->value('seq');
                    DB::table('sqlite_sequence')->updateOrInsert(
                        ['name' => 'vehicles'],
                        ['seq' => max((int) $sequence, (int) $copie)]
                    );
                }
                foreach ($objets as $objet) {
                    DB::statement($objet);
                }
            });
        } finally {
            if ($foreignKeys) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }
};
