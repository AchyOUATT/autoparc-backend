<?php

namespace Database\Seeders;

use App\Enums\PartType;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use Illuminate\Database\Seeder;

/**
 * La chemise de cylindre Cummins 3919937 photographiee, et elle seule.
 *
 * Les autres pieces du meme lot (kit d'embrayage SACHS, servos-debrayage,
 * pompe d'alimentation a main, piston, autre chemise) n'avaient pas de
 * reference lisible sur les photos transmises : elles ne sont pas inventees.
 *
 * L'article entre hors vente (prix et stock a zero, non publie) jusqu'a ce
 * que l'equipe pose un tarif depuis l'application. Aucune machine n'est
 * rattachee : seuls des familles moteur sourcées figurent au descriptif.
 *
 * Idempotente : piece retrouvee par SKU, le commerce n'est pas reecrit
 * apres creation, une reference mise a la corbeille y reste.
 *
 * Inerte en test tant que la categorie « Moteur » n'existe pas : cette
 * migration ne cree pas l'arbre des categories, pour ne pas polluer les
 * tests qui posent les leurs.
 */
class ChemiseCummins3919937Seeder extends Seeder
{
    private const SKU = '3919937';

    private const NOM = 'Chemise de cylindre Cummins 3919937';

    private const DESCRIPTION = 'Chemise de cylindre Cummins, marquage ORIGINAL 3919937. '
        .'La boutique officielle Cummins décrit le kit de chemise 3802407 comme destiné '
        .'aux moteurs C « 8.3 liter » non certifiés ; les catalogues de pièces donnent '
        .'3919937 pour les moteurs 6C / 6CT / 6CTA 8.3 (y compris 6CTA8.3 F1, F2 et F3). '
        .'Le 3802407 est un KIT, pas un équivalent de la chemise seule, et n\'est pas '
        .'enregistré comme tel. Des revendeurs l\'étendent aussi aux ISC / QSC 8.3 et '
        .'citent d\'autres n° (3948095, 3944344, Case IH J919937) : ces rapprochements '
        .'ne sont pas retenus, faute de confirmation sur la fiche officielle de 3919937. '
        .'Aucune machine n\'est enregistrée : une famille moteur ne suffit pas. '
        .'Sources : shop.cummins.com (kit 3802407) ; FinditParts (3919937).';

    public function run(): void
    {
        $categorie = $this->garantirCategorie();

        if ($categorie === null) {
            return;
        }

        $fabricant = Manufacturer::firstOrCreate(['name' => 'Cummins']);

        $piece = Part::withTrashed()->where('sku', self::SKU)->first();

        if ($piece !== null && $piece->trashed()) {
            $this->command?->warn('  3919937 est à la corbeille : non ressuscitée.');

            return;
        }

        $description = [
            'name'                   => self::NOM,
            'description'            => self::DESCRIPTION,
            'part_category_id'       => $categorie->id,
            'manufacturer_id'        => $fabricant->id,
            'manufacturer_reference' => self::SKU,
            'type'                   => PartType::Oem->value,
            'condition'              => 'new',
        ];

        if ($piece === null) {
            $piece = Part::create(array_merge(
                ['sku' => self::SKU],
                $description,
                ['selling_price' => 0, 'stock_quantity' => 0, 'is_active' => false],
            ));
            $this->command?->info('  Chemise Cummins 3919937 créée, NON PUBLIÉE (prix à fixer).');
        } else {
            $piece->update($description);
            $this->command?->info('  Chemise Cummins 3919937 mise à jour (commerce inchangé).');
        }

        $numero = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize(self::SKU), 'brand_id' => null],
            ['number' => self::SKU, 'label' => self::NOM, 'is_superseded' => false],
        );
        $piece->oemNumbers()->syncWithoutDetaching([$numero->id => ['is_primary' => true]]);
    }

    /**
     * Cree « Chemise de cylindre » sous « Moteur » si besoin.
     *
     * Sans la racine « Moteur », on s'abstient : c'est ce qui rend la
     * migration inerte en test.
     */
    private function garantirCategorie(): ?PartCategory
    {
        $existante = PartCategory::query()
            ->where('slug', 'chemise-cylindre')
            ->orWhere('name', 'Chemise de cylindre')
            ->first();

        if ($existante !== null) {
            return $existante;
        }

        $moteur = PartCategory::where('slug', 'moteur')->first();

        if ($moteur === null) {
            $this->command?->error(
                '  Categorie « Moteur » absente : 3919937 ignorée. Lancer PartCategoriesSeeder d\'abord.'
            );

            return null;
        }

        return PartCategory::create([
            'parent_id' => $moteur->id,
            'name'      => 'Chemise de cylindre',
            'slug'      => 'chemise-cylindre',
        ]);
    }
}
