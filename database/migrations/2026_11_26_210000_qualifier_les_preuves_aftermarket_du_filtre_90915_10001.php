<?php

use App\Support\Toyota10001PreuvesAftermarket;
use Illuminate\Database\Migrations\Migration;

/**
 * Qualifie les cinq compatibilités du filtre plateforme 90915-10001
 * (après la pose 2026_11_09_000001) : evidence_level aftermarket_crossref,
 * URL du catalogue CUSCO, marché JP, mois des périodes déjà notées.
 *
 * Idempotente : seules les valeurs vides sont remplies, aucun niveau
 * confirmable n'est posé, les lignes restent candidates. La description
 * n'est corrigée que si elle est encore le texte lu en production le
 * 26/11/2026 (borne « pré-2008 » injustifiée ; remplacement YZZN1 présenté
 * comme un fait, alors qu'il n'apparaît que dans des catalogues de
 * concessionnaires américains).
 *
 * Cible par SKU, slug, moteur et années, jamais par id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Toyota10001PreuvesAftermarket::run();
    }

    public function down(): void
    {
        Toyota10001PreuvesAftermarket::rollback();
    }
};
