<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Type de vente `two_wheeler` et configuration JSON optionnelle.
 *
 * `vehicles.body_style` n'est PAS etendu : les applications publiees (v131,
 * build 132) afficheraient une valeur inconnue en clair, et leur menu
 * d'edition peut lever une exception si la valeur n'est pas dans la liste.
 *
 * La colonne `vin` est allongee (cadre moto souvent plus court ou plus long
 * que 17 caracteres). Sous SQLite la longueur n'est pas contrainte ; le
 * changement utile est PostgreSQL.
 */
return new class extends Migration
{
    /** Les migrations qui reconstruisent une table SQLite doivent rester hors transaction. */
    public $withinTransaction = false;

    private const TYPES_AVANT = ['passenger', 'utility', 'heavy'];

    private const TYPES_APRES = ['passenger', 'utility', 'heavy', 'two_wheeler'];

    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->json('two_wheeler_configuration')->nullable()->after('heavy_configuration');
        });
        if (Schema::hasTable('owned_vehicles') && ! Schema::hasColumn('owned_vehicles', 'vehicle_type')) {
            Schema::table('owned_vehicles', function (Blueprint $table) {
                $table->string('vehicle_type', 20)->nullable()->after('brand_id');
            });
        }

        $this->elargirVin('vehicles');
        $this->elargirVin('owned_vehicles');
        $this->changerTypes(self::TYPES_APRES);
    }

    public function down(): void
    {
        if (DB::table('vehicles')->where('vehicle_type', 'two_wheeler')->exists()
            || (Schema::hasTable('customer_needs') && DB::table('customer_needs')->where('vehicle_type', 'two_wheeler')->exists())) {
            throw new RuntimeException(
                'Retour arriere refuse : des fiches ou besoins deux-roues existent. Aucune donnee ne sera supprimee.'
            );
        }

        foreach (['vehicles', 'owned_vehicles'] as $table) {
            $tropLong = DB::table($table)->whereNotNull('vin')->whereRaw('length(vin) > 17')->exists();
            if ($tropLong) {
                throw new RuntimeException(
                    "Retour arriere refuse : $table contient un numero de cadre de plus de 17 caracteres."
                );
            }
        }

        $this->changerTypes(self::TYPES_AVANT);
        $this->retrecirVin('vehicles');
        $this->retrecirVin('owned_vehicles');

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('two_wheeler_configuration');
        });
        if (Schema::hasTable('owned_vehicles') && Schema::hasColumn('owned_vehicles', 'vehicle_type')) {
            Schema::table('owned_vehicles', function (Blueprint $table) {
                $table->dropColumn('vehicle_type');
            });
        }
    }

    /** @param list<string> $valeurs */
    private function changerTypes(array $valeurs): void
    {
        $this->changerEnum('vehicles', 'vehicle_type', $valeurs);
        if (Schema::hasTable('customer_needs') && Schema::hasColumn('customer_needs', 'vehicle_type')) {
            $this->changerEnum('customer_needs', 'vehicle_type', $valeurs);
        }
    }

    /** @param list<string> $valeurs */
    private function changerEnum(string $table, string $colonne, array $valeurs): void
    {
        $liste = implode("','", $valeurs);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                DB::statement("ALTER TABLE $table MODIFY $colonne ENUM('$liste') NULL");
                break;

            case 'pgsql':
                DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_{$colonne}_check");
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_{$colonne}_check CHECK ($colonne IN ('$liste') OR $colonne IS NULL)");
                break;

            case 'sqlite':
                if (DB::connection()->transactionLevel() > 0) {
                    throw new RuntimeException(
                        "La modification de $table.$colonne sous SQLite doit s'executer hors transaction."
                    );
                }
                $this->remplacerCheckSqlite($table, $colonne, $liste);
                break;

            default:
                throw new RuntimeException("Moteur non pris en charge pour $table.$colonne.");
        }
    }

    private function remplacerCheckSqlite(string $table, string $colonne, string $liste): void
    {
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', $table)->value('sql');
        if (! is_string($sql)) {
            throw new RuntimeException("Definition SQLite de $table introuvable.");
        }

        $identifiant = '(?:"'.$colonne.'"|`'.$colonne.'"|\\['.$colonne.'\\]|'.$colonne.')';
        $pattern = '/check\\s*\\(\\s*'.$identifiant.'\\s+in\\s*\\([^)]*\\)(?:\\s*or\\s+'.$identifiant.'\\s+is\\s+null)?\\s*\\)/i';
        $check = "CHECK (\"$colonne\" IN ('$liste') OR \"$colonne\" IS NULL)";
        $sql = preg_replace($pattern, $check, $sql, -1, $checksRemplaces);
        if ($checksRemplaces === 0) {
            $sql = preg_replace('/\\)\\s*$/', ', '.$check.')', $sql, 1, $checksAjoutes);
            if ($checksAjoutes !== 1) {
                throw new RuntimeException("Definition SQLite de $table non reconnue pour $colonne.");
            }
        }

        $tmp = '__tw_'.$table;
        $tableIdent = '(?:"'.$table.'"|`'.$table.'"|\\['.$table.'\\]|'.$table.')';
        $sql = preg_replace(
            '/^(\\s*create\\s+table\\s+)'.$tableIdent.'(?=\\s*\\()/i',
            '$1"'.$tmp.'"',
            $sql,
            1,
            $tablesRemplacees
        );
        if ($tablesRemplacees !== 1) {
            throw new RuntimeException("Nom SQLite de $table non reconnu.");
        }

        $objets = DB::table('sqlite_master')->where('tbl_name', $table)
            ->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->pluck('sql');
        $colonnes = collect(Schema::getColumns($table))->whereNull('generation')
            ->map(fn (array $col) => '"'.str_replace('"', '""', $col['name']).'"')->implode(', ');
        $sequence = DB::table('sqlite_sequence')->where('name', $table)->value('seq');
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');

        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($sql, $colonnes, $objets, $sequence, $table, $tmp): void {
                DB::statement($sql);
                DB::statement("INSERT INTO \"$tmp\" ($colonnes) SELECT $colonnes FROM \"$table\"");
                DB::statement("DROP TABLE \"$table\"");
                DB::statement("ALTER TABLE \"$tmp\" RENAME TO \"$table\"");
                if ($sequence !== null) {
                    $copie = DB::table('sqlite_sequence')->where('name', $table)->value('seq');
                    DB::table('sqlite_sequence')->updateOrInsert(
                        ['name' => $table],
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

    private function elargirVin(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'vin')) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE $table ALTER COLUMN vin TYPE varchar(32)");
        }
        // SQLite: TEXT, la longueur declaree n'est pas appliquee.
    }

    private function retrecirVin(string $table): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'vin')) {
            return;
        }
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE $table ALTER COLUMN vin TYPE varchar(17)");
        }
    }
};
