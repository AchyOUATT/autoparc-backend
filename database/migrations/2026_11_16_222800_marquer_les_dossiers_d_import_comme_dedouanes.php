<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Marque tous les dossiers d'import existants comme dedouanes.
 *
 * Un vehicule est dedouane ou non : il n'y a pas d'etat intermediaire.
 * Les lignes en base etaient toutes a false — defaut de la colonne, et
 * l'application n'envoyait pas le champ. La demande est de tout passer
 * a dedouane.
 *
 * Idempotente : un second passage ne trouve plus de ligne a false.
 *
 * Le defaut de la colonne passe aussi a true, pour qu'un nouvel import
 * sans le champ (ancienne application) naisse dedouane. Les vehicules
 * SANS dossier d'import ne sont pas couverts : cette migration ne cree
 * aucune ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('vehicle_import_details')
            ->where('customs_cleared', false)
            ->update([
                'customs_cleared' => true,
                'updated_at' => now(),
            ]);

        // Sur Postgres, un ALTER du seul defaut evite de reconstruire la
        // colonne. Ailleurs (SQLite des tests), `change()` retient le meme
        // type et le nouveau defaut.
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE vehicle_import_details ALTER COLUMN customs_cleared SET DEFAULT true');
        } else {
            Schema::table('vehicle_import_details', function (Blueprint $table) {
                $table->boolean('customs_cleared')->default(true)->change();
            });
        }
    }

    /**
     * Intentionnellement vide. L'etat d'avant etait entierement false :
     * le retablir ecraserait aussi les dossiers marques dedouanes apres
     * cette migration, et reconstruirait un etat que personne ne veut
     * retrouver.
     */
    public function down(): void
    {
        //
    }
};
