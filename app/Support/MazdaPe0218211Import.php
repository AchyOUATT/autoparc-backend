<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crée ou met à jour le capteur MAP Mazda PE02-18-211 et pose uniquement
 * les applications sourcées dont le modèle est déjà au catalogue.
 *
 * Idempotent : retrouve la pièce par SKU, ne réécrit jamais un prix, un
 * stock ou une publication déjà posés, et ne duplique pas les lignes.
 */
final class MazdaPe0218211Import
{
    public const SKU = 'PE02-18-211';

    public const PRIX = 20000;

    public const STOCK = 2;

    public const NOM = 'Capteur MAP (pression d\'admission) Mazda PE02-18-211';

    public const DESCRIPTION = 'Capteur MAP (pression d\'admission) d\'origine Mazda, référence PE02-18-211. '
        .'Uniquement pour les moteurs essence SKYACTIV-G atmosphériques. '
        .'Exclut le 2,5 l Turbo, qui prend PY8W-18-211, et les Diesel (2,2 l : SH01-18-211). '
        .'Sur Mazda 3, certaines 2,0 l d\'assemblage mexicain (VIN commençant par 3MZ) et d\'autres applications prennent PEDD-18-211 : vérifier le VIN. '
        .'Les CX-30 et CX-50 utilisent PEDD-18-211 (atmosphérique) ou PY8W-18-211 (turbo). '
        .'Le MX-5 ND et le CX-4 ne sont pas au catalogue. '
        .'Sources : MazdaPartsNow / Mazda USA ; catalogues européens Bosch 0 261 230 315 (Mazda 2 1,5 l, Mazda 3 1,5 l, Mazda 6 2,0 l).';

    /** @return list<string> */
    public const MODELES_RETENUS = [
        'mazda2-dj',
        'mazda3-bl',
        'mazda3-bm',
        'mazda3-bp',
        'mazda6-gj',
        'cx-3-dk',
        'cx-5-ke',
        'cx-5-kf',
    ];

    /**
     * Applications déjà posées par erreur et non retenues par les sources.
     *
     * @var list<string>
     */
    public const MODELES_A_RETIRER = [
        'mazda6-gh',
    ];

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'part_id' => null,
                'part_created' => false,
                'part_updated' => false,
                'fitments_inserted' => 0,
                'fitments_updated' => 0,
                'fitments_removed' => 0,
                'fitments_skipped_missing_model' => [],
                'commerce_preserved' => false,
            ];

            $part = self::trouverOuCreerLaPiece($rapport);
            if ($part === null) {
                return $rapport;
            }

            $rapport['part_id'] = (int) $part->id;
            self::rattacherLeNumeroOem((int) $part->id);
            $touched = self::poserLesApplications($part, $rapport);
            $touched = self::retirerLesApplicationsNonRetenues($part, $rapport) || $touched;

            if ($touched || $rapport['part_created'] || $rapport['part_updated']) {
                DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
            }

            return $rapport;
        });
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    private static function trouverOuCreerLaPiece(array &$rapport): ?object
    {
        $parts = DB::table('parts')->where('sku', self::SKU)
            ->whereNull('owner_partner_account_id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($parts->count() > 1) {
            throw new RuntimeException('Import PE02-18-211 refusé : plusieurs articles plateforme portent ce SKU.');
        }

        $existant = $parts->first();
        $categorie = DB::table('part_categories')
            ->where('slug', 'capteur-pression')
            ->orWhere('name', 'Capteur de pression')
            ->first();
        $fabricantId = self::fabricantId();

        if ($existant !== null) {
            if ($existant->deleted_at !== null) {
                return null;
            }

            $maj = [
                'name' => self::NOM,
                'description' => self::DESCRIPTION,
                'manufacturer_reference' => self::SKU,
                'type' => PartType::Oem->value,
                'updated_at' => now(),
            ];
            if ($categorie !== null) {
                $maj['part_category_id'] = $categorie->id;
            }
            if ($fabricantId !== null) {
                $maj['manufacturer_id'] = $fabricantId;
            }
            DB::table('parts')->where('id', $existant->id)->update($maj);
            $rapport['part_updated'] = true;
            $rapport['commerce_preserved'] = true;

            return DB::table('parts')->where('id', $existant->id)->first();
        }

        if ($categorie === null) {
            return null;
        }

        $id = (int) DB::table('parts')->insertGetId([
            'sku' => self::SKU,
            'name' => self::NOM,
            'description' => self::DESCRIPTION,
            'part_category_id' => $categorie->id,
            'manufacturer_id' => $fabricantId,
            'manufacturer_reference' => self::SKU,
            'type' => PartType::Oem->value,
            'condition' => 'new',
            'selling_price' => self::PRIX,
            'currency' => 'XOF',
            'stock_quantity' => self::STOCK,
            'is_active' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rapport['part_created'] = true;

        return DB::table('parts')->where('id', $id)->first();
    }

    private static function fabricantId(): ?int
    {
        $existant = Manufacturer::query()
            ->where('slug', 'mazda')
            ->orWhere('name', 'Mazda')
            ->first();
        if ($existant !== null) {
            return (int) $existant->id;
        }

        $cree = Manufacturer::create([
            'name' => 'Mazda',
            'is_oem_supplier' => true,
        ]);

        return (int) $cree->id;
    }

    private static function rattacherLeNumeroOem(int $partId): void
    {
        $brandId = DB::table('brands')->where('slug', 'mazda')->value('id');
        $numero = OemNumber::firstOrCreate(
            [
                'normalized_number' => OemNumber::normalize(self::SKU),
                'brand_id' => $brandId !== null ? (int) $brandId : null,
            ],
            [
                'number' => self::SKU,
                'label' => self::NOM,
                'is_superseded' => false,
            ],
        );
        $deja = DB::table('oem_number_part')
            ->where('part_id', $partId)
            ->where('oem_number_id', $numero->id)
            ->exists();
        if ($deja) {
            return;
        }
        DB::table('oem_number_part')->insert([
            'part_id' => $partId,
            'oem_number_id' => $numero->id,
            'is_primary' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    private static function poserLesApplications(object $part, array &$rapport): bool
    {
        $touched = false;

        foreach (self::applications() as $fitment) {
            $modele = DB::table('vehicle_models')->where('slug', $fitment['model_slug'])->first();
            if ($modele === null) {
                $rapport['fitments_skipped_missing_model'][] = $fitment['model_slug'];

                continue;
            }

            $existants = DB::table('part_fitments')
                ->where('part_id', $part->id)
                ->where('vehicle_model_id', $modele->id)
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            if ($existants->isNotEmpty()) {
                $premier = $existants->first();
                $changement = (int) $premier->year_from !== $fitment['year_from']
                    || (int) ($premier->year_to ?? 0) !== (int) ($fitment['year_to'] ?? 0)
                    || (string) ($premier->notes ?? '') !== $fitment['notes']
                    || trim((string) ($premier->engine_code ?? '')) !== ''
                    || trim((string) ($premier->chassis_code ?? '')) !== '';

                if ($changement) {
                    DB::table('part_fitments')->where('id', $premier->id)->update([
                        'year_from' => $fitment['year_from'],
                        'year_to' => $fitment['year_to'],
                        'engine_code' => null,
                        'chassis_code' => null,
                        'notes' => $fitment['notes'],
                        'source' => FitmentSource::Declared->value,
                        'updated_at' => now(),
                    ]);
                    $rapport['fitments_updated']++;
                    $touched = true;
                }

                $extras = $existants->skip(1)->pluck('id');
                if ($extras->isNotEmpty()) {
                    DB::table('part_fitments')->whereIn('id', $extras->all())->delete();
                    $rapport['fitments_removed'] += $extras->count();
                    $touched = true;
                }

                continue;
            }

            DB::table('part_fitments')->insert([
                'part_id' => $part->id,
                'vehicle_model_id' => $modele->id,
                'trim_id' => null,
                'engine_type_id' => null,
                'drivetrain_id' => null,
                'engine_code' => null,
                'year_from' => $fitment['year_from'],
                'year_to' => $fitment['year_to'],
                'position' => null,
                'notes' => $fitment['notes'],
                'source' => FitmentSource::Declared->value,
                'chassis_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $rapport['fitments_inserted']++;
            $touched = true;
        }

        return $touched;
    }

    /**
     * @param  array<string, mixed>  $rapport
     */
    private static function retirerLesApplicationsNonRetenues(object $part, array &$rapport): bool
    {
        $ids = DB::table('part_fitments as pf')
            ->join('vehicle_models as vm', 'vm.id', '=', 'pf.vehicle_model_id')
            ->where('pf.part_id', $part->id)
            ->whereIn('vm.slug', self::MODELES_A_RETIRER)
            ->pluck('pf.id');

        if ($ids->isEmpty()) {
            return false;
        }

        DB::table('part_fitments')->whereIn('id', $ids->all())->delete();
        $rapport['fitments_removed'] += $ids->count();

        return true;
    }

    /**
     * @return list<array{model_slug: string, year_from: int, year_to: ?int, notes: string}>
     */
    public static function applications(): array
    {
        return [
            [
                'model_slug' => 'mazda2-dj',
                'year_from' => 2014,
                'year_to' => null,
                'notes' => '1,5 l SKYACTIV-G essence atmosphérique, dès août 2014. Source : catalogues européens Bosch 0 261 230 315.',
            ],
            [
                'model_slug' => 'mazda3-bl',
                'year_from' => 2012,
                'year_to' => 2013,
                'notes' => '2,0 l SKYACTIV-G essence, 2012-2013 (fin de génération BL). Source : MazdaPartsNow / Mazda USA.',
            ],
            [
                'model_slug' => 'mazda3-bm',
                'year_from' => 2013,
                'year_to' => 2018,
                'notes' => '1,5 / 2,0 / 2,5 l SKYACTIV-G essence atmosphérique. Vérifier le VIN : certaines 2,0 l d\'assemblage mexicain (3MZ) prennent PEDD-18-211. Sources : MazdaPartsNow / Mazda USA ; Bosch 0 261 230 315 (1,5 l Europe). Commercialisation jusqu\'au début 2019 sur certains marchés.',
            ],
            [
                'model_slug' => 'mazda3-bp',
                'year_from' => 2019,
                'year_to' => 2024,
                'notes' => '1,5 / 2,0 / 2,5 l SKYACTIV-G atmosphérique (hors 2,5 Turbo PY8W-18-211). Millésime 2019 et suivants (commercialisation dès nov. 2018). Vérifier le VIN vs PEDD-18-211. Sources : MazdaPartsNow / Mazda USA ; Bosch 0 261 230 315.',
            ],
            [
                'model_slug' => 'mazda6-gj',
                'year_from' => 2013,
                'year_to' => 2021,
                'notes' => '2,0 / 2,5 l SKYACTIV-G atmosphérique (hors 2,5 Turbo PY8W-18-211). Mazda USA : 2014-2021 (2,5 l) ; catalogues européens Bosch : 2,0 l dès 2013.',
            ],
            [
                'model_slug' => 'cx-3-dk',
                'year_from' => 2016,
                'year_to' => 2020,
                'notes' => '2,0 l SKYACTIV-G essence. Source : MazdaPartsNow / Mazda USA, 2016-2020.',
            ],
            [
                'model_slug' => 'cx-5-ke',
                'year_from' => 2013,
                'year_to' => 2016,
                'notes' => '2,0 / 2,5 l SKYACTIV-G essence atmosphérique. Source : MazdaPartsNow / Mazda USA, dès 2013.',
            ],
            [
                'model_slug' => 'cx-5-kf',
                'year_from' => 2017,
                'year_to' => 2024,
                'notes' => '2,0 / 2,5 l SKYACTIV-G atmosphérique uniquement (hors 2,5 Turbo PY8W-18-211). Source : MazdaPartsNow / Mazda USA, jusqu\'à 2024.',
            ],
        ];
    }
}
