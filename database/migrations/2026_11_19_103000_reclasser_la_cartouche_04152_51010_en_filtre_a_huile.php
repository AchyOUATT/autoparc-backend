<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La cartouche 04152-51010 est un filtre a huile (element 1VD-FTV),
 * mais en production elle se retrouve rangee dans « Alternateur ».
 * Elle rejoint la categorie « Filtre a huile ».
 *
 * Ciblee par sa reference de vente (sku / manufacturer_reference),
 * pas par un identifiant : les id different d'une base a l'autre.
 *
 * Idempotente : si la piece est deja dans la bonne categorie, ou si
 * la piece ou la categorie manque, le passage ne fait rien. Aucune
 * autre colonne n'est touchee — ni prix, ni stock, ni compatibilites.
 *
 * Le down est vide : remettre la piece dans Alternateur reconstruirait
 * l'erreur.
 */
return new class extends Migration
{
    private const REFERENCE = '04152-51010';

    public function up(): void
    {
        $categorie = DB::table('part_categories')
            ->where('slug', 'filtre-huile')
            ->orWhere('name', 'Filtre à huile')
            ->first();

        if ($categorie === null) {
            return;
        }

        DB::table('parts')
            ->where(function ($query) {
                $query->where('sku', self::REFERENCE)
                    ->orWhere('manufacturer_reference', self::REFERENCE);
            })
            ->where('part_category_id', '!=', $categorie->id)
            ->update([
                'part_category_id' => $categorie->id,
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        //
    }
};
