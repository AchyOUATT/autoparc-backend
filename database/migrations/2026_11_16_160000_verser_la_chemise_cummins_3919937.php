<?php

use Database\Seeders\ChemiseCummins3919937Seeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Verse la chemise de cylindre Cummins 3919937 au catalogue de reference.
 *
 * L'article entre hors vente. Render joue `migrate --force` au demarrage,
 * jamais les seeders : cette migration est le point d'entree. Elle ne leve
 * jamais d'exception, pour ne pas eteindre l'API.
 *
 * Elle ne cree pas l'arbre des categories. Sans « Moteur », le seeder
 * s'abstient : la migration reste inerte en test.
 *
 * Idempotente : piece retrouvee par SKU, prix / stock / publication non
 * reecrits apres creation.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('db:seed', [
                '--class' => ChemiseCummins3919937Seeder::class,
                '--force' => true,
            ]);
            echo Artisan::output();
        } catch (\Throwable $e) {
            echo '  ATTENTION : la chemise Cummins 3919937 n\'a pas ete versee ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * On ne retire la piece que si elle est restee inutilisee (non publiee,
     * sans commande ni mouvement de stock).
     */
    public function down(): void
    {
        $piece = \App\Models\Part::withTrashed()->where('sku', '3919937')->first();

        if ($piece === null) {
            return;
        }

        if ($piece->is_active || $piece->selling_price > 0 || $piece->stock_quantity > 0) {
            return;
        }

        $piece->oemNumbers()->detach();
        $piece->fitments()->delete();
        $piece->forceDelete();
    }
};
