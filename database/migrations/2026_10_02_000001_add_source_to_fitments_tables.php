<?php

use App\Enums\FitmentSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Donne une origine a chaque ligne de compatibilite.
 *
 * La valeur par defaut est `declared`, la plus protectrice : un chemin
 * d'ecriture qui oublierait de renseigner la colonne verra sa ligne epargnee
 * par le recalcul du catalogue, au lieu de la voir disparaitre.
 *
 * Restait a classer les lignes deja en base. `BackfillFitments` n'ecrit que
 * quatre colonnes — la cle etrangere, le modele de vehicule et la plage
 * d'annees. Une ligne qui renseigne une finition, une motorisation, une
 * transmission, un code moteur, une position ou une note ne peut donc pas
 * venir de lui : c'est quelqu'un qui l'a saisie. Les autres sont rendues a la
 * commande.
 *
 * Ce classement est une deduction, pas une certitude : une saisie reduite a
 * « cette marque, ce modele » porte la meme signature que la fabrication
 * automatique et sera rendue a la commande. C'est pourquoi `--fresh` annonce
 * desormais ce qu'il va detruire et demande confirmation.
 */
return new class extends Migration
{
    /**
     * Les colonnes qu'un humain peut renseigner et que la commande laisse
     * toujours vides, table par table.
     *
     * @var array<string, list<string>>
     */
    private const EMPREINTE_HUMAINE = [
        'part_fitments' => [
            'trim_id', 'engine_type_id', 'drivetrain_id', 'engine_code', 'position', 'notes',
        ],
        'accessory_fitments' => [
            'trim_id', 'engine_type_id', 'drivetrain_id', 'notes',
        ],
    ];

    public function up(): void
    {
        foreach (self::EMPREINTE_HUMAINE as $table => $colonnes) {
            $cle = $table === 'part_fitments' ? 'part_id' : 'accessory_id';

            Schema::table($table, function (Blueprint $t) use ($cle) {
                $t->string('source', 10)
                    ->default(FitmentSource::Declared->value)
                    ->after('vehicle_model_id');

                // La commande de backfill interroge « les lignes fabriquees de
                // cette piece » a chaque tour de boucle.
                $t->index([$cle, 'source']);
            });

            $rendues = DB::table($table)
                ->where('source', FitmentSource::Declared->value)
                ->where(function ($q) use ($colonnes) {
                    foreach ($colonnes as $colonne) {
                        $q->whereNull($colonne);
                    }
                })
                ->update(['source' => FitmentSource::Generated->value]);

            $gardees = DB::table($table)
                ->where('source', FitmentSource::Declared->value)
                ->count();

            echo sprintf(
                '  %s : %d ligne(s) rendue(s) au recalcul, %d conservee(s) comme saisie(s).%s',
                $table,
                $rendues,
                $gardees,
                PHP_EOL,
            );
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::EMPREINTE_HUMAINE) as $table) {
            $cle = $table === 'part_fitments' ? 'part_id' : 'accessory_id';

            Schema::table($table, function (Blueprint $t) use ($cle, $table) {
                $t->dropIndex($table.'_'.$cle.'_source_index');
                $t->dropColumn('source');
            });
        }
    }
};
