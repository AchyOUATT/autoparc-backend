<?php

namespace App\Support;

use App\Enums\PartType;
use App\Models\Manufacturer;
use Illuminate\Support\Facades\DB;

/**
 * Recalifie le capteur MAP PE02-18-211 en pièce adaptable (Chine),
 * sans toucher au prix, au stock, au numéro OEM ni aux compatibilités.
 *
 * Idempotent : retrouve la pièce par SKU, le fabricant par slug / nom,
 * et ne réécrit le descriptif que s'il change encore.
 */
final class CorrigerPe0218211Aftermarket
{
    public const SKU = 'PE02-18-211';

    public const NOM = 'Capteur MAP (pression d\'admission) compatible Mazda PE02-18-211';

    public const DESCRIPTION = 'Capteur MAP (pression d\'admission) adaptable, compatible avec la référence d\'origine Mazda PE02-18-211. '
        .'Uniquement pour les moteurs essence SKYACTIV-G atmosphériques. '
        .'Exclut le 2,5 l Turbo, qui prend PY8W-18-211, et les Diesel (2,2 l : SH01-18-211). '
        .'Sur Mazda 3, certaines 2,0 l d\'assemblage mexicain (VIN commençant par 3MZ) et d\'autres applications prennent PEDD-18-211 : vérifier le VIN. '
        .'Les CX-30 et CX-50 utilisent PEDD-18-211 (atmosphérique) ou PY8W-18-211 (turbo). '
        .'Le MX-5 ND et le CX-4 ne sont pas au catalogue. '
        .'Sources : MazdaPartsNow / Mazda USA ; catalogues européens Bosch 0 261 230 315 (Mazda 2 1,5 l, Mazda 3 1,5 l, Mazda 6 2,0 l).';

    /** @return array<string, mixed> */
    public static function run(): array
    {
        return DB::transaction(function (): array {
            $rapport = [
                'part_id' => null,
                'updated' => false,
                'skipped_missing_part' => false,
                'skipped_missing_manufacturer' => false,
                'skipped_trashed' => false,
            ];

            $parts = DB::table('parts')->where('sku', self::SKU)
                ->whereNull('owner_partner_account_id')
                ->lockForUpdate()
                ->limit(2)
                ->get();

            if ($parts->count() > 1) {
                throw new \RuntimeException('Correction PE02-18-211 refusée : plusieurs articles plateforme portent ce SKU.');
            }

            $piece = $parts->first();
            if ($piece === null) {
                $rapport['skipped_missing_part'] = true;

                return $rapport;
            }
            if ($piece->deleted_at !== null) {
                $rapport['skipped_trashed'] = true;
                $rapport['part_id'] = (int) $piece->id;

                return $rapport;
            }

            $chine = self::fabricantChine();
            if ($chine === null) {
                $rapport['skipped_missing_manufacturer'] = true;
                $rapport['part_id'] = (int) $piece->id;

                return $rapport;
            }

            $rapport['part_id'] = (int) $piece->id;

            $maj = [
                'name' => self::NOM,
                'description' => self::DESCRIPTION,
                'type' => PartType::Aftermarket->value,
                'manufacturer_id' => $chine,
            ];

            $deja = (string) $piece->name === $maj['name']
                && (string) $piece->description === $maj['description']
                && (string) $piece->type === $maj['type']
                && (int) $piece->manufacturer_id === $maj['manufacturer_id'];

            if ($deja) {
                return $rapport;
            }

            $maj['updated_at'] = now();
            DB::table('parts')->where('id', $piece->id)->update($maj);
            $rapport['updated'] = true;

            return $rapport;
        });
    }

    private static function fabricantChine(): ?int
    {
        $existant = Manufacturer::query()
            ->where('slug', 'chine')
            ->orWhere('name', 'Chine')
            ->first();

        if ($existant !== null) {
            return (int) $existant->id;
        }

        return null;
    }
}
