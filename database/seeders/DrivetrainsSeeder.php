<?php

namespace Database\Seeders;

use App\Support\ReferenceCatalogWriter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DrivetrainsSeeder extends Seeder
{
    public const LABELS = [
        'FWD' => 'Traction avant',
        'RWD' => 'Propulsion arrière',
        'AWD' => 'Intégrale (AWD)',
        '4WD' => '4×4 (4WD)',
    ];

    private const ANCIENS_LIBELLES = [
        'RWD' => ['Propulsion'],
        'AWD' => ['Transmission intégrale permanente', 'Intégrale permanente'],
        '4WD' => ['4x4 débrayable', '4x4 enclenchable'],
    ];

    public function run(): void
    {
        foreach (self::LABELS as $code => $label) {
            DB::transaction(function () use ($code, $label): void {
                $existing = DB::table('drivetrains')->where('code', $code)->lockForUpdate()->first();
                // Le code ne prouve ni une transmission permanente, ni un
                // systeme debrayable. Corriger nos anciens libelles seulement,
                // sans remplacer une description personnalisee au deploiement.
                if ($existing !== null && $existing->label !== $label
                    && ! in_array($existing->label, self::ANCIENS_LIBELLES[$code] ?? [], true)) {
                    return;
                }

                ReferenceCatalogWriter::upsert('drivetrains', ['code' => $code], ['label' => $label]);
            });
        }
    }
}
