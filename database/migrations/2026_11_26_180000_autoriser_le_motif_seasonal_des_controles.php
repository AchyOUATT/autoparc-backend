<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La contrainte PostgreSQL de `reason` n'a pas suivi le controle saisonnier.
 *
 * `2026_09_29_000001_add_seasons_to_vehicle_checks` a transforme l'enum
 * (`trip`, `periodic`) en chaine, pour que le motif puisse s'allonger sans
 * nouvelle migration a chaque valeur. Sur PostgreSQL, `change()` a bien passe
 * la colonne en varchar(20), mais a laisse l'ancienne contrainte CHECK. L'API
 * et l'application mobile envoient `seasonal` : la validation passe, puis
 * l'insertion remonte une erreur 500.
 *
 * Les motifs autorises sont ceux de CheckReason, verifies par un banc : on
 * n'invente pas de valeur. `periodic` n'y figure plus, n'est accepte ni par
 * l'enum ni par l'API, et aucune ligne de production ne le porte.
 *
 * Le `down()` retablit la contrainte constatee en production avant ce correctif
 * (`trip`, `periodic`). Il refuse de s'executer si une ligne porte un motif
 * hors de cette liste, pour ne pas effacer un controle saisonnier deja
 * enregistre.
 */
return new class extends Migration
{
    private const TABLE = 'vehicle_checks';

    private const CONTRAINTE = 'vehicle_checks_reason_check';

    /** Contrainte en place en production avant ce correctif. */
    private const ANCIENS = ['trip', 'periodic'];

    /**
     * Motifs valides d'apres CheckReason. Un banc verrouille l'alignement :
     * ajouter un cas a l'enum sans mettre a jour cette liste fait echouer
     * le test, et signale qu'une nouvelle migration est necessaire une fois
     * celle-ci deja jouee.
     *
     * @var list<string>
     */
    private const MOTIFS = ['trip', 'seasonal'];

    public function up(): void
    {
        $this->appliquer(self::MOTIFS);
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)
            && DB::table(self::TABLE)->whereNotIn('reason', self::ANCIENS)->exists()) {
            throw new RuntimeException(
                'Retour arriere refuse : vehicle_checks contient un motif hors trip/periodic. '
                .'Reclasser explicitement ces lignes avant de retrecir la contrainte.'
            );
        }

        $this->appliquer(self::ANCIENS);
    }

    /**
     * @param  list<string>  $valeurs
     */
    private function appliquer(array $valeurs): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $liste = implode("','", $valeurs);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                $existe = DB::table('information_schema.table_constraints')
                    ->where('table_schema', DB::getDatabaseName())
                    ->where('table_name', self::TABLE)
                    ->where('constraint_name', self::CONTRAINTE)
                    ->where('constraint_type', 'CHECK')
                    ->exists();
                if ($existe) {
                    DB::statement('ALTER TABLE '.self::TABLE.' DROP CHECK '.self::CONTRAINTE);
                }
                DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONTRAINTE." CHECK (reason IN ('$liste'))");
                break;

            case 'pgsql':
                // DROP IF EXISTS puis ADD : rejouer la migration est inoffensif.
                DB::statement('ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::CONTRAINTE);
                DB::statement('ALTER TABLE '.self::TABLE.' ADD CONSTRAINT '.self::CONTRAINTE." CHECK (reason IN ('$liste'))");
                break;

            case 'sqlite':
                if (DB::connection()->transactionLevel() > 0) {
                    throw new RuntimeException(
                        'La modification de vehicle_checks.reason sous SQLite doit s executer hors transaction '
                        .'afin de preserver les relations existantes.'
                    );
                }
                $this->appliquerSqlite($liste);
                break;

            default:
                throw new RuntimeException('Moteur de base non pris en charge pour vehicle_checks.reason.');
        }
    }

    private function appliquerSqlite(string $liste): void
    {
        // Blueprint::change reconstruit depuis PRAGMA table_info, qui ne
        // restitue pas les CHECK des autres colonnes. Garder la definition
        // complete evite de supprimer la contrainte du verdict, les index et
        // les cles etrangeres.
        $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', self::TABLE)->value('sql');
        if (! is_string($sql)) {
            throw new RuntimeException('Definition SQLite de vehicle_checks introuvable.');
        }

        $temporaire = '__reason_vehicle_checks';
        $check = "CHECK (\"reason\" IN ('$liste'))";
        $sql = preg_replace(
            '/check\s*\(\s*(?:"reason"|`reason`|\[reason\]|reason)\s+in\s*\([^)]*\)\s*\)/i',
            $check,
            $sql,
            -1,
            $checksRemplaces
        );
        if ($checksRemplaces === 0) {
            $sql = preg_replace('/\)\s*$/', ', '.$check.')', $sql, 1, $checksAjoutes);
            if ($checksAjoutes !== 1) {
                throw new RuntimeException('Definition SQLite de vehicle_checks non reconnue.');
            }
        }
        $sql = preg_replace(
            '/^(\s*create\s+table\s+)(?:"vehicle_checks"|`vehicle_checks`|\[vehicle_checks\]|vehicle_checks)(?=\s*\()/i',
            '$1"'.$temporaire.'"',
            $sql,
            1,
            $tablesRemplacees
        );
        if ($tablesRemplacees !== 1) {
            throw new RuntimeException('Nom SQLite de vehicle_checks non reconnu.');
        }

        $objets = DB::table('sqlite_master')->where('tbl_name', self::TABLE)
            ->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->pluck('sql');
        $colonnes = collect(Schema::getColumns(self::TABLE))->whereNull('generation')
            ->map(fn (array $colonne) => '"'.str_replace('"', '""', $colonne['name']).'"')->implode(', ');
        $sequence = DB::table('sqlite_sequence')->where('name', self::TABLE)->value('seq');
        $foreignKeys = (bool) DB::scalar('PRAGMA foreign_keys');

        if ($foreignKeys) {
            Schema::disableForeignKeyConstraints();
        }
        try {
            DB::transaction(function () use ($sql, $colonnes, $objets, $sequence, $temporaire): void {
                DB::statement($sql);
                DB::statement("INSERT INTO \"$temporaire\" ($colonnes) SELECT $colonnes FROM \"".self::TABLE.'"');
                DB::statement('DROP TABLE "'.self::TABLE.'"');
                DB::statement('ALTER TABLE "'.$temporaire.'" RENAME TO "'.self::TABLE.'"');
                if ($sequence !== null) {
                    $copie = DB::table('sqlite_sequence')->where('name', self::TABLE)->value('seq');
                    DB::table('sqlite_sequence')->updateOrInsert(
                        ['name' => self::TABLE],
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
