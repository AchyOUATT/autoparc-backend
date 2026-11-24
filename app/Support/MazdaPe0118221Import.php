<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crée ou met à jour le capteur de vilebrequin compatible Mazda PE01-18-221
 * (adaptable Chine, 20 000 F, stock 2) et pose uniquement les applications
 * sourcées dont le modèle est déjà au catalogue.
 *
 * Idempotent : retrouve la pièce par SKU, ne réécrit jamais un prix, un
 * stock, un prix d'achat ou une publication déjà posés, et ne duplique
 * pas les lignes.
 */
final class MazdaPe0118221Import
{
    public const SKU = 'PE01-18-221';

    public const PRIX = 20000;

    public const STOCK = 2;

    public const NOM = 'Capteur de vilebrequin (CKP / PMH) compatible Mazda PE01-18-221';

    public const DESCRIPTION = 'Capteur de position de vilebrequin (CKP / PMH, 3 broches) adaptable, compatible avec la référence d\'origine Mazda PE01-18-221. '
        .'Couvre les motorisations Mazda SKYACTIV-G atmosphériques et 2,5 l Turbo, ainsi que les Diesel SKYACTIV-D 1,5 l (S5), 1,8 l (S8) et 2,2 l (SH) indiqués dans les compatibilités. '
        .'Sur Mazda 3 BL (2012-2013), uniquement le 2,0 l SKYACTIV-G : exclus les moteurs MZR (1,6 / 2,0 / 2,3 / 2,5 l) et Mazdaspeed. '
        .'Le CX-9 TB V6 (2007-2015) est exclu. '
        .'Le CX-50 Hybride, dont la mécanique vient de Toyota, est exclu par prudence. '
        .'Le MX-5 ND, le CX-4, le CX-70 et le CX-90 ne sont pas au catalogue. '
        .'Sources : MazdaPartsNow / Mazda USA ; catalogues européens Valeo / Hella ; manuel Denso SKYACTIV-D.';

    /**
     * @var list<string>
     */
    public const MODELES_RETENUS = [
        'mazda2-dj',
        'mazda3-bl',
        'mazda3-bm',
        'mazda3-bp',
        'mazda6-gj',
        'cx-3-dk',
        'cx-30-dm',
        'cx-5-ke',
        'cx-5-kf',
        'cx-50-va',
        'cx-9-tc',
    ];

    /**
     * Applications explicitement exclues (CX-9 TB V6 d'avant 2016).
     *
     * @var list<string>
     */
    public const MODELES_A_RETIRER = [
        'cx-9-tb',
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
            throw new RuntimeException('Import PE01-18-221 refusé : plusieurs articles plateforme portent ce SKU.');
        }

        $existant = $parts->first();
        $categorie = DB::table('part_categories')
            ->where('slug', 'capteurs-sondes')
            ->orWhere('name', 'Capteurs & Sondes')
            ->first();

        if ($existant !== null) {
            if ($existant->deleted_at !== null) {
                return null;
            }

            $maj = [
                'name' => self::NOM,
                'description' => self::DESCRIPTION,
                'manufacturer_reference' => self::SKU,
                'type' => PartType::Aftermarket->value,
                'updated_at' => now(),
            ];
            if ($categorie !== null) {
                $maj['part_category_id'] = $categorie->id;
            }
            $fabricantId = self::fabricantChineId(creerSiAbsent: true);
            if ($fabricantId !== null) {
                $maj['manufacturer_id'] = $fabricantId;
            }
            DB::table('parts')->where('id', $existant->id)->update($maj);
            $rapport['part_updated'] = true;
            $rapport['commerce_preserved'] = true;

            return DB::table('parts')->where('id', $existant->id)->first();
        }

        // Ne pas créer le fabricant tant que la catégorie manque : la
        // migration doit rester inerte sur une base encore vide (bancs).
        if ($categorie === null) {
            return null;
        }

        $fabricantId = self::fabricantChineId(creerSiAbsent: true);
        if ($fabricantId === null) {
            return null;
        }

        $id = (int) DB::table('parts')->insertGetId([
            'sku' => self::SKU,
            'name' => self::NOM,
            'description' => self::DESCRIPTION,
            'part_category_id' => $categorie->id,
            'manufacturer_id' => $fabricantId,
            'manufacturer_reference' => self::SKU,
            'type' => PartType::Aftermarket->value,
            'condition' => 'new',
            'cost_price' => null,
            'selling_price' => self::PRIX,
            'currency' => 'XOF',
            'stock_quantity' => self::STOCK,
            'storage_location' => 'Ouagadougou',
            'is_active' => true,
            'is_available' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $rapport['part_created'] = true;

        return DB::table('parts')->where('id', $id)->first();
    }

    private static function fabricantChineId(bool $creerSiAbsent = false): ?int
    {
        $existant = Manufacturer::query()
            ->where('slug', 'chine')
            ->orWhere('name', 'Chine')
            ->first();
        if ($existant !== null) {
            return (int) $existant->id;
        }

        if (! $creerSiAbsent) {
            return null;
        }

        $cree = Manufacturer::create([
            'name' => 'Chine',
            'is_oem_supplier' => false,
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
                'notes' => '1,5 l essence SKYACTIV-G (P5) et 1,5 l Diesel SKYACTIV-D (S5), dès 2014 (génération encore au catalogue).',
            ],
            [
                'model_slug' => 'mazda3-bl',
                'year_from' => 2012,
                'year_to' => 2013,
                'notes' => 'Uniquement 2,0 l SKYACTIV-G, 2012-2013 (fin de génération BL). Exclut les moteurs MZR (1,6 / 2,0 / 2,3 / 2,5 l) et Mazdaspeed.',
            ],
            [
                'model_slug' => 'mazda3-bm',
                'year_from' => 2013,
                'year_to' => 2018,
                'notes' => '1,5 l (P5), 2,0 l (PE) et 2,5 l (PY) essence SKYACTIV-G, ainsi que les Diesel SKYACTIV-D 1,5 l (S5), 1,8 l (S8) et 2,2 l (SH).',
            ],
            [
                'model_slug' => 'mazda3-bp',
                'year_from' => 2019,
                'year_to' => 2025,
                'notes' => '1,5 / 2,0 / 2,5 l essence SKYACTIV-G (atmosphérique et 2,5 l Turbo) et 1,8 l SKYACTIV-D. Millésime 2019 et suivants.',
            ],
            [
                'model_slug' => 'mazda6-gj',
                'year_from' => 2012,
                'year_to' => 2021,
                'notes' => '2,0 / 2,5 l essence SKYACTIV-G (atmosphérique et 2,5 l Turbo) et 2,2 l SKYACTIV-D, 2012-2021.',
            ],
            [
                'model_slug' => 'cx-3-dk',
                'year_from' => 2015,
                'year_to' => 2021,
                'notes' => '2,0 l SKYACTIV-G essence et Diesel SKYACTIV-D 1,5 l (S5) et 1,8 l (S8), 2015-2021.',
            ],
            [
                'model_slug' => 'cx-30-dm',
                'year_from' => 2020,
                'year_to' => 2025,
                'notes' => '2,0 / 2,5 l essence SKYACTIV-G (atmosphérique et 2,5 l Turbo) et 1,8 l SKYACTIV-D, 2020-2025.',
            ],
            [
                'model_slug' => 'cx-5-ke',
                'year_from' => 2012,
                'year_to' => 2016,
                'notes' => '2,0 / 2,5 l essence SKYACTIV-G et 2,2 l SKYACTIV-D, 2012-2016.',
            ],
            [
                'model_slug' => 'cx-5-kf',
                'year_from' => 2017,
                'year_to' => 2025,
                'notes' => '2,0 / 2,5 l essence SKYACTIV-G (atmosphérique et 2,5 l Turbo) et 2,2 l SKYACTIV-D, 2017-2025.',
            ],
            [
                'model_slug' => 'cx-50-va',
                'year_from' => 2023,
                'year_to' => 2025,
                'notes' => '2,5 l essence SKYACTIV-G et 2,5 l Turbo, 2023-2025. Hors version Hybride (mécanique Toyota, exclu par prudence).',
            ],
            [
                'model_slug' => 'cx-9-tc',
                'year_from' => 2016,
                'year_to' => 2023,
                'notes' => '2,5 l SKYACTIV-G Turbo uniquement, 2016-2023. Exclut le CX-9 TB V6 (2007-2015).',
            ],
        ];
    }
}
