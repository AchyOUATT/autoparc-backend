<?php

use App\Enums\PartType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * L'origine d'une piece (OEM, OES, aftermarket, occasion) n'est plus saisie
 * dans les formulaires : seule la condition (neuve, reconditionnee, occasion)
 * est demandee. La colonne `parts.type` devient donc nullable et SANS valeur
 * par defaut, pour ne jamais attribuer une origine inventee aux nouvelles
 * pieces. Les origines deja enregistrees sont conservees telles quelles.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->changer(nullable: true, defaut: null);
    }

    public function down(): void
    {
        // Retour arriere sans perte : une origine inconnue redevient la valeur
        // historique par defaut, seule possible pour une colonne NOT NULL.
        DB::table('parts')->whereNull('type')->update(['type' => PartType::Aftermarket->value]);
        $this->changer(nullable: false, defaut: PartType::Aftermarket->value);
    }

    private function changer(bool $nullable, ?string $defaut): void
    {
        $valeurs = PartType::values();
        $liste = implode("','", $valeurs);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                $null = $nullable ? 'NULL' : 'NOT NULL';
                $suffixe = $defaut === null ? '' : " DEFAULT '$defaut'";
                DB::statement("ALTER TABLE parts MODIFY type ENUM('$liste') $null$suffixe");
                break;

            case 'pgsql':
                DB::statement('ALTER TABLE parts ALTER COLUMN type '.($nullable ? 'DROP NOT NULL' : 'SET NOT NULL'));
                DB::statement($defaut === null
                    ? 'ALTER TABLE parts ALTER COLUMN type DROP DEFAULT'
                    : "ALTER TABLE parts ALTER COLUMN type SET DEFAULT '$defaut'");
                break;

            case 'sqlite':
                Schema::withoutForeignKeyConstraints(function () use ($valeurs, $nullable, $defaut) {
                    Schema::table('parts', function (Blueprint $table) use ($valeurs, $nullable, $defaut) {
                        $champ = $table->enum('type', $valeurs)->nullable($nullable);
                        $champ->default($defaut);
                        $champ->change();
                    });
                });
                break;

            default:
                throw new RuntimeException('Moteur de base non pris en charge.');
        }
    }
};
