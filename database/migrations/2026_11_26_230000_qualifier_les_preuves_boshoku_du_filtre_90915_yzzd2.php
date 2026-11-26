<?php

use App\Support\Toyota90915Yzzd2PreuvesBoshoku;
use Illuminate\Database\Migrations\Migration;

/**
 * Filtre plateforme 90915-YZZD2 : preuves Toyota Boshoku (aftermarket_crossref)
 * sur les quatre lignes Hilux / Fortuner, huit couples génération × moteur
 * ajoutés au même niveau (candidats), notes sur les deux lignes Prado,
 * description corrigée. Voir docs/compatibilites-toyota-90915-yzzd2.md.
 *
 * Idempotente, additive ; aucun marché, mois, châssis ni niveau confirmable ;
 * aucune relation ni héritage depuis la 90915-YZZD1. Cible par SKU, slug,
 * moteur et années, jamais par id. down() « tout ou rien » par ligne.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota90915Yzzd2PreuvesBoshoku::run();
    }

    public function down(): void
    {
        Toyota90915Yzzd2PreuvesBoshoku::rollback();
    }
};
