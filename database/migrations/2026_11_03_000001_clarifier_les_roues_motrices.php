<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Ces valeurs viennent des anciens seeders. Garder les identifiants,
        // codes, relations et descriptions personnalisees deja enregistres.
        $libelles = [
            'FWD' => ['Traction avant', []],
            'RWD' => ['Propulsion arrière', ['Propulsion']],
            'AWD' => ['Intégrale (AWD)', ['Transmission intégrale permanente', 'Intégrale permanente']],
            '4WD' => ['4×4 (4WD)', ['4x4 débrayable', '4x4 enclenchable']],
        ];

        foreach ($libelles as $code => [$label, $anciens]) {
            foreach (DB::table('drivetrains')->where('code', $code)->get(['id', 'label', 'updated_at']) as $row) {
                // Comparaison PHP stricte : les collations MySQL peuvent
                // ignorer accents/casse et inclure un libelle personnalise.
                if (in_array($row->label, $anciens, true)) {
                    DB::table('drivetrains')->where('id', $row->id)
                        ->update(['label' => $label, 'updated_at' => now()]);
                } elseif ($row->label === $label && $row->updated_at === null) {
                    // Les anciens upsert Query Builder laissaient parfois
                    // cette date vide : le delta doit voir la reference.
                    DB::table('drivetrains')->where('id', $row->id)->update(['updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        // Correction de presentation compatible avec les anciennes versions.
        // Plusieurs anciens textes ont le meme remplacement : les recreer
        // arbitrairement perdrait l'origine et pourrait ecraser une correction.
    }
};
