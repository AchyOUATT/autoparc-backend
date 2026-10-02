<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le SKU devient facultatif en base, comme il l'etait deja partout ailleurs.
 *
 * `StorePartRequest` et `StoreAccessoryRequest` l'annoncent `nullable` et ne
 * controlent son unicite que parmi les lignes qui en portent une
 * (`whereNotNull`) — une clause qui n'a de sens que sur une colonne vide par
 * endroits. L'ecran de saisie mobile le presente comme optionnel et retire la
 * cle de la charge utile quand le champ est vide ; les modeles Dart le lisent
 * en `String?` et chaque affichage le teste avant de le rendre.
 *
 * Les colonnes, elles, etaient NOT NULL. Une piece enregistree sans reference
 * ne rendait donc pas un 422 explicable mais un 500 (« NOT NULL constraint
 * failed: parts.sku »), cote mobile un « erreur serveur » sans rien a
 * corriger dans le formulaire.
 *
 * L'index unique reste en place : la norme SQL autorise plusieurs NULL sous
 * une contrainte d'unicite, et c'est precisement ce qu'il faut ici — les
 * references renseignees restent uniques, les absences ne s'excluent pas
 * entre elles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parts', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->string('sku')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Revenir a NOT NULL exige qu'aucune ligne ne soit vide. Les articles
        // saisis sans reference en recoivent une, derivee de l'identifiant et
        // reconnaissable comme fabriquee : sans cela le retour arriere
        // echouerait sur les donnees que cette migration a rendues possibles.
        // Une boucle plutot qu'une concatenation SQL, dont la syntaxe differe
        // entre MySQL et SQLite.
        foreach (['parts' => 'SANS-SKU-PRT-', 'accessories' => 'SANS-SKU-ACC-'] as $table => $prefixe) {
            DB::table($table)->whereNull('sku')->orderBy('id')->pluck('id')
                ->each(fn ($id) => DB::table($table)->where('id', $id)->update(['sku' => $prefixe.$id]));
        }

        Schema::table('parts', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });

        Schema::table('accessories', function (Blueprint $table) {
            $table->string('sku')->nullable(false)->change();
        });
    }
};
