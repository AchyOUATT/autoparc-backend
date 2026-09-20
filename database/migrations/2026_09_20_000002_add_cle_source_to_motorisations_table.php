<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Une cle d'unicite qui resiste aux valeurs nulles.
 *
 * L'unicite portait sur huit colonnes, dont quatre peuvent etre nulles —
 * cylindree, cylindres, boite, carburant. Or MySQL comme PostgreSQL
 * considerent deux NULL comme distincts : deux lignes identiques dont la
 * cylindree est inconnue ne se seraient jamais reconnues, et chaque relance de
 * l'import en aurait ajoute une copie. En silence.
 *
 * Les donnees canadiennes n'ont aucun nul sur ces colonnes — verifie, zero sur
 * 29 175 — donc le defaut ne s'est jamais manifeste. La source europeenne, qui
 * arrive, en aura : une electrique n'a ni cylindree ni cylindres.
 *
 * D'ou cette empreinte, calculee a l'import : elle traite le nul comme une
 * valeur, et la comparaison redevient franche.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('motorisations', function (Blueprint $table) {
            $table->string('cle_source', 64)->nullable()->after('cycle');
        });

        // Les lignes deja importees recoivent leur empreinte, selon la meme
        // recette que la commande — voir ImportConsommations::empreinte().
        DB::table('motorisations')->orderBy('id')->chunkById(1000, function ($lignes) {
            foreach ($lignes as $ligne) {
                DB::table('motorisations')->where('id', $ligne->id)->update([
                    'cle_source' => md5(implode('|', [
                        $ligne->source,
                        $ligne->model_year,
                        mb_strtolower(trim((string) $ligne->make_raw)),
                        mb_strtolower(trim((string) $ligne->model_raw)),
                        $ligne->engine_l ?? '',
                        $ligne->cylinders ?? '',
                        $ligne->transmission_code ?? '',
                        $ligne->fuel_code ?? '',
                    ])),
                ]);
            }
        });

        Schema::table('motorisations', function (Blueprint $table) {
            $table->unique('cle_source');
        });

        // L'ancien index reste : il ne gene pas, et le supprimer sur SQLite
        // — la base des tests — coute plus qu'il ne rapporte.
    }

    public function down(): void
    {
        Schema::table('motorisations', function (Blueprint $table) {
            $table->dropUnique(['cle_source']);
            $table->dropColumn('cle_source');
        });
    }
};
