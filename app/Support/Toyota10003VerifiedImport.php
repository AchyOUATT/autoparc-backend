<?php

namespace App\Support;

use App\Enums\FitmentSource;
use App\Models\OemNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Crée le 90915-10003 et pose uniquement les applications CUSCO dont le
 * modèle existe déjà au catalogue. N'ajoute aucun modèle.
 */
final class Toyota10003VerifiedImport
{
    private const SKU = '90915-10003';

    private const SKU_10001 = '90915-10001';

    /** Import additif, sans prix inventé, sans nouveau modèle. */
    public static function run(): array
    {
        $catalogue = self::catalogue();

        return DB::transaction(function () use ($catalogue): array {
            $rapport = [
                'part_id' => null,
                'part_created' => false,
                'fitments_inserted' => 0,
                'fitments_chassis_filled' => 0,
                'fitments_skipped_missing_model' => [],
                'chassis_10001_filled' => 0,
                'yzzn1_untouched' => true,
            ];

            $avantYzzN1 = self::empreinteYzzN1();

            $part = self::trouverOuCreerLaPiece($catalogue['part'], $rapport);
            if ($part !== null) {
                $rapport['part_id'] = (int) $part->id;
                $touched = self::poserLesApplications($part, $catalogue['fitments'], $rapport);
                if ($touched) {
                    DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
                }
            }

            $rapport['chassis_10001_filled'] = self::renseignerLesChassisDu10001();

            $apresYzzN1 = self::empreinteYzzN1();
            if ($avantYzzN1 !== $apresYzzN1) {
                throw new RuntimeException('Import 90915-10003 refusé : les lignes du 90915-YZZN1 ont été modifiées.');
            }

            return $rapport;
        });
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $rapport
     */
    private static function trouverOuCreerLaPiece(array $definition, array &$rapport): ?object
    {
        $parts = DB::table('parts')->where('sku', self::SKU)
            ->whereNull('owner_partner_account_id')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($parts->count() > 1) {
            throw new RuntimeException('Import 90915-10003 refusé : plusieurs articles plateforme portent ce SKU.');
        }

        $existant = $parts->first();
        if ($existant !== null) {
            if ($existant->deleted_at !== null) {
                return null;
            }
            self::rattacherLeNumeroOem((int) $existant->id, $definition['name']);

            return $existant;
        }

        $categorie = DB::table('part_categories')->where('slug', $definition['category_slug'])->first();
        if ($categorie === null) {
            return null;
        }

        $fabricantId = self::fabricantId();
        $id = (int) DB::table('parts')->insertGetId([
            'sku' => self::SKU,
            'name' => $definition['name'],
            'description' => $definition['description'],
            'part_category_id' => $categorie->id,
            'manufacturer_id' => $fabricantId,
            'manufacturer_reference' => self::SKU,
            'type' => $definition['type'],
            'condition' => $definition['condition'],
            'selling_price' => 0,
            'stock_quantity' => 0,
            'is_active' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        self::rattacherLeNumeroOem($id, $definition['name']);
        $rapport['part_created'] = true;

        return DB::table('parts')->where('id', $id)->first();
    }

    private static function fabricantId(): ?int
    {
        $source = DB::table('parts')->where('sku', self::SKU_10001)
            ->whereNull('owner_partner_account_id')
            ->whereNull('deleted_at')
            ->value('manufacturer_id');
        if ($source !== null) {
            return (int) $source;
        }

        $chine = DB::table('manufacturers')->where('name', 'Chine')->value('id');

        return $chine !== null ? (int) $chine : null;
    }

    private static function rattacherLeNumeroOem(int $partId, string $libelle): void
    {
        $numero = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize(self::SKU), 'brand_id' => null],
            ['number' => self::SKU, 'label' => $libelle, 'is_superseded' => false],
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
     * @param  array<int, array<string, mixed>>  $fitments
     * @param  array<string, mixed>  $rapport
     */
    private static function poserLesApplications(object $part, array $fitments, array &$rapport): bool
    {
        $inseres = 0;
        $remplis = 0;
        $absents = [];

        foreach ($fitments as $fitment) {
            $modele = DB::table('vehicle_models')->where('slug', $fitment['model_slug'])->first();
            if ($modele === null) {
                $absents[] = $fitment['model_slug'];

                continue;
            }

            $chassis = implode('/', $fitment['chassis_codes']);
            $identite = [
                'part_id' => $part->id,
                'vehicle_model_id' => $modele->id,
                'trim_id' => null,
                'engine_type_id' => null,
                'drivetrain_id' => null,
                'engine_code' => $fitment['engine_code'],
                'year_from' => $fitment['year_from'],
                'year_to' => $fitment['year_to'],
                'position' => null,
                'source' => FitmentSource::Declared->value,
            ];

            $existant = DB::table('part_fitments')->where($identite)->lockForUpdate()->first();
            if ($existant !== null) {
                if (trim((string) ($existant->chassis_code ?? '')) === '') {
                    DB::table('part_fitments')->where('id', $existant->id)->update([
                        'chassis_code' => $chassis,
                        'updated_at' => now(),
                    ]);
                    $remplis++;
                }

                continue;
            }

            DB::table('part_fitments')->insert([
                ...$identite,
                'chassis_code' => $chassis,
                'notes' => self::notes($fitment, $chassis),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $inseres++;
        }

        $rapport['fitments_inserted'] = $inseres;
        $rapport['fitments_chassis_filled'] = $remplis;
        $rapport['fitments_skipped_missing_model'] = array_values(array_unique($absents));

        return $inseres > 0 || $remplis > 0;
    }

    /**
     * Reprend les codes châssis déjà sourcés dans le manifeste du 10001.
     *
     * Ne devine rien : seules les lignes dont le manifeste porte des codes,
     * et dont le code en base est encore vide, sont mises à jour.
     */
    private static function renseignerLesChassisDu10001(): int
    {
        $chemin = database_path('catalogue/toyota-10001-verified-applications.json');
        if (! is_file($chemin)) {
            return 0;
        }

        $data = json_decode((string) file_get_contents($chemin), true, flags: JSON_THROW_ON_ERROR);
        $remplis = 0;

        $parts = DB::table('parts')->where('sku', self::SKU_10001)
            ->whereNull('owner_partner_account_id')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->limit(2)
            ->get();
        if ($parts->count() !== 1) {
            return 0;
        }

        $part = $parts->first();
        foreach ($data['fitments'] ?? [] as $fitment) {
            $codes = $fitment['chassis_codes'] ?? [];
            if (! is_array($codes) || $codes === []) {
                continue;
            }
            $modele = DB::table('vehicle_models')->where('slug', $fitment['model_slug'])->first();
            if ($modele === null) {
                continue;
            }
            $lignes = DB::table('part_fitments')->where([
                'part_id' => $part->id,
                'vehicle_model_id' => $modele->id,
                'engine_code' => $fitment['engine_code'],
                'year_from' => $fitment['year_from'],
                'year_to' => $fitment['year_to'],
            ])->lockForUpdate()->get();
            foreach ($lignes as $ligne) {
                if (trim((string) ($ligne->chassis_code ?? '')) !== '') {
                    continue;
                }
                DB::table('part_fitments')->where('id', $ligne->id)->update([
                    'chassis_code' => implode('/', $codes),
                    'updated_at' => now(),
                ]);
                $remplis++;
            }
        }

        if ($remplis > 0) {
            DB::table('parts')->where('id', $part->id)->update(['updated_at' => now()]);
        }

        return $remplis;
    }

    /** @param  array<string, mixed>  $fitment */
    private static function notes(array $fitment, string $chassis): string
    {
        $texte = 'Application documentée, marché Japon ('.$fitment['market'].') : '.$fitment['source_model_name']
            .', châssis '.$chassis.', moteur '.$fitment['engine_code']
            .', période source '.$fitment['source_period'].'. Source : '.$fitment['source_url']
            .', p. '.$fitment['source_page'].' ; consultée le '.$fitment['reviewed_on'].'.';

        if (! empty($fitment['engine_code_as_printed']) && $fitment['engine_code_as_printed'] !== $fitment['engine_code']) {
            $texte .= ' CUSCO imprime le moteur « '.$fitment['engine_code_as_printed'].' », normalisé en '
                .$fitment['engine_code'].'.';
        }
        if (! empty($fitment['hks_url']) && ! empty($fitment['hks_period'])) {
            $texte .= ' Recoupement HKS ('.$fitment['hks_url'].') : '.$fitment['hks_period'].'.';
        }
        if (! empty($fitment['notes_extra'])) {
            $texte .= ' '.$fitment['notes_extra'];
        }

        return $texte.' Bornes annuelles : contrôler le VIN, le châssis et la date de fabrication aux bornes.';
    }

    /** @return array<int, array<string, mixed>> */
    private static function empreinteYzzN1(): array
    {
        $ids = DB::table('parts')->where('sku', '90915-YZZN1')
            ->whereNull('deleted_at')
            ->pluck('id');
        if ($ids->isEmpty()) {
            return [];
        }

        return DB::table('part_fitments')->whereIn('part_id', $ids)
            ->orderBy('id')
            ->get()
            ->map(fn ($ligne) => (array) $ligne)
            ->values()
            ->all();
    }

    private static function catalogue(): array
    {
        $data = json_decode(
            (string) file_get_contents(database_path('catalogue/toyota-10003-verified-applications.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        Validator::make($data, [
            'schema_version' => ['required', 'in:1'],
            'sku' => ['required', 'in:'.self::SKU],
            'part' => ['required', 'array'],
            'part.name' => ['required', 'string'],
            'part.description' => ['required', 'string'],
            'part.category_slug' => ['required', 'in:filtre-huile'],
            'part.type' => ['required', 'in:aftermarket'],
            'part.condition' => ['required', 'in:new'],
            'fitments' => ['required', 'array', 'min:1'],
            'fitments.*.model_slug' => ['required', 'string'],
            'fitments.*.engine_code' => ['required', 'string'],
            'fitments.*.year_from' => ['required', 'integer', 'between:1900,2100'],
            'fitments.*.year_to' => ['present', 'nullable', 'integer', 'between:1900,2100'],
            'fitments.*.market' => ['required', 'in:JP'],
            'fitments.*.source_url' => ['required', 'url'],
            'fitments.*.source_page' => ['required', 'integer', 'min:1'],
            'fitments.*.source_period' => ['required', 'string'],
            'fitments.*.chassis_codes' => ['required', 'array', 'min:1'],
            'fitments.*.chassis_codes.*' => ['required', 'string'],
            'fitments.*.source_model_name' => ['required', 'string'],
            'fitments.*.reviewed_on' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        foreach ($data['fitments'] as $fitment) {
            if ($fitment['year_to'] !== null && $fitment['year_from'] > $fitment['year_to']) {
                throw new RuntimeException('Import 90915-10003 refusé : période source inversée.');
            }
        }

        return $data;
    }
}
