<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Critères restrictifs des compatibilités : châssis, marché et mois.
 *
 * Tout est additif et nullable. Une colonne vide ne restreint rien, donc les
 * 143 compatibilités en production gardent leur comportement : leur
 * `chassis_code` actuel reste informatif tant que `chassis_restricted` n'est
 * pas posé explicitement sur la ligne.
 *
 * Idempotente : chaque table et chaque colonne n'est créée que si elle manque,
 * et les marchés sont insérés par code (updateOrInsert).
 *
 * Voir docs/proposition-moteur-compatibilite-chassis-marche.md.
 */
return new class extends Migration
{
    /**
     * Copie de App\Support\Market::ALL au moment de la migration. Aucune
     * hiérarchie entre marchés (pas de colonne parent) : correspondance
     * strictement explicite. `epc_region` seulement pour les 4 catalogues EPC.
     */
    private const MARCHES = [
        ['code' => 'JPN', 'name' => 'Japon', 'epc_region' => 'JPN', 'sort' => 10],
        ['code' => 'EUR', 'name' => 'Europe', 'epc_region' => 'EUR', 'sort' => 20],
        ['code' => 'GEN', 'name' => 'Général', 'epc_region' => 'GEN', 'sort' => 30],
        ['code' => 'USA', 'name' => 'États-Unis', 'epc_region' => 'USA', 'sort' => 40],
        ['code' => 'CAN', 'name' => 'Canada', 'epc_region' => null, 'sort' => 50],
        ['code' => 'MDE', 'name' => 'Moyen-Orient / Golfe', 'epc_region' => null, 'sort' => 60],
        ['code' => 'AFR', 'name' => 'Afrique', 'epc_region' => null, 'sort' => 70],
        ['code' => 'AUS', 'name' => 'Australie', 'epc_region' => null, 'sort' => 80],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('markets')) {
            Schema::create('markets', function (Blueprint $table) {
                $table->id();
                $table->string('code', 8)->unique();
                $table->string('name', 80);
                // Catalogue EPC Toyota homonyme (JPN, EUR, GEN, USA) ; informatif.
                $table->string('epc_region', 8)->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }

        foreach (self::MARCHES as $marche) {
            DB::table('markets')->updateOrInsert(
                ['code' => $marche['code']],
                $marche + ['created_at' => now(), 'updated_at' => now()],
            );
        }

        foreach (['part_fitments', 'oem_number_fitments'] as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                if (! Schema::hasColumn($nom, 'chassis_code')) {
                    $table->string('chassis_code', 80)->nullable();
                }
                if (! Schema::hasColumn($nom, 'chassis_restricted')) {
                    // Null ou faux : le code châssis est informatif.
                    $table->boolean('chassis_restricted')->nullable();
                }
                if (! Schema::hasColumn($nom, 'market_codes')) {
                    // Liste de codes de la table markets ; null = tous marchés.
                    $table->json('market_codes')->nullable();
                }
                if (! Schema::hasColumn($nom, 'month_from')) {
                    // Mois de fabrication de year_from (1-12) ; null = janvier.
                    $table->unsignedTinyInteger('month_from')->nullable();
                }
                if (! Schema::hasColumn($nom, 'month_to')) {
                    // Mois de fabrication de year_to (1-12) ; null = décembre.
                    $table->unsignedTinyInteger('month_to')->nullable();
                }
                // Preuve de la COMPATIBILITE (ce véhicule reçoit cette pièce),
                // distincte de la preuve d'un REMPLACEMENT de référence, qui vit
                // dans oem_number_relations. Null = déclaration nue : jamais
                // confirmable (voir App\Support\FitmentEvidence).
                if (! Schema::hasColumn($nom, 'evidence_level')) {
                    // manufacturer_official | manufacturer_epc | dealer_catalog
                    // | human_verified | aftermarket_crossref | unverified
                    $table->string('evidence_level', 40)->nullable();
                }
                if (! Schema::hasColumn($nom, 'evidence_sources')) {
                    // Liste JSON d'URL http(s) ; au moins une pour confirmer.
                    $table->json('evidence_sources')->nullable();
                }
                if (! Schema::hasColumn($nom, 'verified_by_id')) {
                    // Compte du personnel qui a validé (human_verified).
                    $table->foreignId('verified_by_id')->nullable()->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn($nom, 'verified_on')) {
                    $table->date('verified_on')->nullable();
                }
            });
        }

        foreach (['owned_vehicles', 'vehicles'] as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                if (! Schema::hasColumn($nom, 'chassis_code')) {
                    $table->string('chassis_code', 40)->nullable();
                }
                if (! Schema::hasColumn($nom, 'manufacturing_month')) {
                    $table->unsignedTinyInteger('manufacturing_month')->nullable();
                }
                if (! Schema::hasColumn($nom, 'specification_market')) {
                    // Marché de destination COMMERCIALE d'origine (comparé).
                    $table->string('specification_market', 20)->nullable();
                }
                if (! Schema::hasColumn($nom, 'circulation_country')) {
                    // Pays où le véhicule circule aujourd'hui (ISO 3166-1
                    // alpha-2) : affichage seulement, jamais comparé.
                    $table->string('circulation_country', 2)->nullable();
                }
            });
        }

        // Nature et niveau de preuve d'un lien entre deux références.
        // `oem_numbers.is_superseded / superseded_by_id` restent en place
        // (lecture historique, « supersession non qualifiée »).
        if (! Schema::hasTable('oem_number_relations')) {
            Schema::create('oem_number_relations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('from_oem_number_id')->constrained('oem_numbers')->cascadeOnDelete();
                $table->foreignId('to_oem_number_id')->constrained('oem_numbers')->cascadeOnDelete();
                // manufacturer_supersession | distributor_replacement
                $table->string('kind', 40);
                // manufacturer_official | manufacturer_epc | dealer_catalog | aftermarket_crossref
                $table->string('evidence_level', 40);
                // Liste JSON des URL consultées pour CE remplacement.
                $table->json('sources')->nullable();
                $table->text('source_note')->nullable();
                $table->date('verified_on')->nullable();
                $table->timestamps();

                $table->unique(['from_oem_number_id', 'to_oem_number_id', 'kind'], 'oem_number_relations_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('oem_number_relations');

        foreach (['owned_vehicles', 'vehicles'] as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                foreach (['chassis_code', 'manufacturing_month', 'circulation_country'] as $col) {
                    if (Schema::hasColumn($nom, $col)) {
                        $table->dropColumn($col);
                    }
                }
                // owned_vehicles.specification_market existait avant : on ne
                // retire que celle de vehicles.
                if ($nom === 'vehicles' && Schema::hasColumn($nom, 'specification_market')) {
                    $table->dropColumn('specification_market');
                }
            });
        }

        foreach (['part_fitments', 'oem_number_fitments'] as $nom) {
            Schema::table($nom, function (Blueprint $table) use ($nom) {
                if (Schema::hasColumn($nom, 'verified_by_id')) {
                    $table->dropConstrainedForeignId('verified_by_id');
                }
                $colonnes = ['chassis_restricted', 'market_codes', 'month_from', 'month_to', 'evidence_level', 'evidence_sources', 'verified_on'];
                // part_fitments.chassis_code existait avant.
                if ($nom === 'oem_number_fitments') {
                    $colonnes[] = 'chassis_code';
                }
                foreach ($colonnes as $col) {
                    if (Schema::hasColumn($nom, $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }

        Schema::dropIfExists('markets');
    }
};
