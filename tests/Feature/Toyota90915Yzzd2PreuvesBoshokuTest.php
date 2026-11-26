<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Brand;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use App\Services\PartCompatibilityAssessment;
use App\Support\FitmentEvidence;
use App\Support\Toyota90915Yzzd2PreuvesBoshoku as Yzzd2;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Qualification Toyota Boshoku du filtre 90915-YZZD2 (26/11/2026).
 *
 * L'état de départ reproduit la production lue le 26/11/2026 : six lignes
 * déclarées sans preuve, note fournisseur d'origine, description « diesel ».
 */
class Toyota90915Yzzd2PreuvesBoshokuTest extends TestCase
{
    use RefreshDatabase;

    /** Les six lignes du seeder, telles qu'en production. */
    private const ORIGINE = [
        ['hilux-an10', 2005, 2015, '1KD-FTV'],
        ['hilux-an120', 2015, 2020, '2GD-FTV'],
        ['land-cruiser-prado-j120', 2003, 2009, '1KD-FTV'],
        ['land-cruiser-prado-j150', 2009, 2015, '1KD-FTV'],
        ['fortuner-an50', null, null, '2KD-FTV'],
        ['fortuner-an160', 2015, 2020, '2GD-FTV'],
    ];

    private const GENERATIONS = [
        'hilux-an10' => ['Hilux', 'AN10', 2004, 2015],
        'hilux-an120' => ['Hilux', 'AN120', 2015, null],
        'fortuner-an50' => ['Fortuner', 'AN50', 2005, 2015],
        'fortuner-an160' => ['Fortuner', 'AN160', 2015, null],
        'land-cruiser-prado-j120' => ['Land Cruiser Prado', 'J120', 2002, 2009],
        'land-cruiser-prado-j150' => ['Land Cruiser Prado', 'J150', 2009, null],
    ];

    private Part $piece;

    protected function setUp(): void
    {
        parent::setUp();

        // Les migrations ont pu semer le filtre : on repart de l'état exact
        // de la production, construit ici.
        Part::withTrashed()->where('sku', Yzzd2::SKU)->get()->each(function (Part $p) {
            $p->fitments()->delete();
            $p->forceDelete();
        });

        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);
        foreach (self::GENERATIONS as $slug => [$nom, $generation, $debut, $fin]) {
            VehicleModel::firstOrCreate(['slug' => $slug], [
                'brand_id' => $marque->id, 'name' => $nom, 'generation' => $generation,
                'production_start' => $debut, 'production_end' => $fin, 'is_active' => true,
            ]);
        }

        $categorie = PartCategory::firstOrCreate(['name' => 'Filtre à huile'], ['slug' => 'filtre-a-huile']);
        $this->piece = Part::create([
            'sku' => Yzzd2::SKU,
            'name' => 'Filtre a huile visse 90915-YZZD2',
            'description' => Yzzd2::DESCRIPTION_AVANT,
            'part_category_id' => $categorie->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'selling_price' => 3000,
            'stock_quantity' => 15,
            'is_active' => true,
        ])->refresh();

        foreach (self::ORIGINE as [$slug, $de, $a, $moteur]) {
            $this->piece->fitments()->create([
                'vehicle_model_id' => VehicleModel::where('slug', $slug)->value('id'),
                'engine_code' => $moteur,
                'year_from' => $de,
                'year_to' => $a,
                'source' => FitmentSource::Declared,
                'notes' => Yzzd2::NOTE_ORIGINE,
            ]);
        }
    }

    public function test_la_qualification_enrichit_ajoute_et_laisse_tout_candidat(): void
    {
        $rapport = Yzzd2::run();

        $this->assertSame(1, $rapport['description_corrigee']);
        $this->assertSame(4, $rapport['fitments_qualifies']);
        $this->assertSame(8, $rapport['fitments_ajoutes']);
        $this->assertSame(2, $rapport['notes_prado']);
        $this->assertSame([], $rapport['modeles_absents']);
        $this->assertSame(Yzzd2::DESCRIPTION_APRES, $this->piece->fresh()->description);
        $this->assertStringNotContainsString('moteurs diesel', $this->piece->fresh()->description);
        $this->assertSame(14, $this->piece->fitments()->count());

        $evaluation = app(PartCompatibilityAssessment::class);
        foreach (array_merge(Yzzd2::existantes(), Yzzd2::nouvelles()) as $cible) {
            $ligne = $this->ligne($cible);
            $this->assertSame(FitmentEvidence::AFTERMARKET_CROSSREF, $ligne->evidence_level, $cible['model_slug'].' '.$cible['engine_code']);
            $this->assertSame($cible['sources'], $ligne->evidence_sources);
            $this->assertSame(Yzzd2::BOSHOKU_URL, $ligne->evidence_sources[0]);
            $this->assertStringContainsString('non officiel', $ligne->notes);
            // Jamais de critère éliminatoire tiré de Boshoku ou du miroir EPC.
            $this->assertNull($ligne->market_codes);
            $this->assertNull($ligne->month_from);
            $this->assertNull($ligne->month_to);
            $this->assertNull($ligne->chassis_code);
            $this->assertNull($ligne->chassis_restricted);
            $this->assertNull($ligne->verified_by_id);
            $this->assertNull($ligne->verified_on);
            $this->assertFalse(FitmentEvidence::isDocumented($ligne));

            $annee = $cible['year_from'] ?? 2010;
            foreach ([null, 'EU', 'JP', 'AFR'] as $marche) {
                $criteres = ['vehicle_model_id' => $ligne->vehicle_model_id, 'year' => $annee, 'engine_code' => $cible['engine_code']];
                if ($marche !== null) {
                    $criteres['specification_market'] = $marche;
                }
                $verdict = $evaluation->assess($this->piece->fresh(), $evaluation->withModelState($criteres));
                $this->assertSame('candidate', $verdict['status'], $cible['model_slug'].' '.$cible['engine_code'].' '.($marche ?? '-'));
                $this->assertSame('insufficient_evidence', $verdict['reason']);
            }
        }

        // Les bornes déjà posées ne sont pas resserrées ni élargies.
        $this->assertSame(2020, $this->ligne(Yzzd2::existantes()[1])->year_to);
        $this->assertNull($this->ligne(Yzzd2::existantes()[2])->year_from);
    }

    public function test_les_lignes_prado_ne_recoivent_qu_une_note(): void
    {
        Yzzd2::run();

        foreach (Yzzd2::prado() as $cible) {
            $ligne = $this->ligne($cible);
            $this->assertNull($ligne->evidence_level);
            $this->assertNull($ligne->evidence_sources);
            $this->assertNull($ligne->market_codes);
            $this->assertSame(Yzzd2::noteComplete($cible['note']), $ligne->notes);
            $this->assertSame($cible['year_from'], $ligne->year_from);
            $this->assertSame($cible['year_to'], $ligne->year_to);
        }
        $this->assertStringContainsString('NON VERIFIEE', $this->ligne(Yzzd2::prado()[0])->notes);
        $this->assertStringContainsString('Toyota Indus', $this->ligne(Yzzd2::prado()[1])->notes);
        $this->assertStringContainsString("pas une preuve d'incompatibilite", $this->ligne(Yzzd2::prado()[1])->notes);

        // Toujours candidates : le conflit n'exclut pas.
        $evaluation = app(PartCompatibilityAssessment::class);
        foreach (Yzzd2::prado() as $cible) {
            $verdict = $evaluation->assess($this->piece->fresh(), $evaluation->withModelState([
                'vehicle_model_id' => $this->ligne($cible)->vehicle_model_id,
                'year' => $cible['year_from'],
                'engine_code' => '1KD-FTV',
            ]));
            $this->assertSame('candidate', $verdict['status']);
        }
    }

    public function test_aucun_niveau_confirmable_ni_essence_exclue(): void
    {
        Yzzd2::run();

        $this->assertSame(0, $this->piece->fitments()->whereIn('evidence_level', FitmentEvidence::SUFFICIENT)->count());
        $moteurs = $this->piece->fitments()->pluck('engine_code')->unique()->sort()->values()->all();
        $this->assertSame(['1GD-FTV', '1KD-FTV', '2GD-FTV', '2KD-FTV', '2TR-FE'], $moteurs);
    }

    public function test_aucune_relation_ni_heritage_depuis_la_yzzd1(): void
    {
        $yzzd1 = Part::create([
            'sku' => '90915-YZZD1-TEST', 'name' => 'YZZD1 test', 'part_category_id' => $this->piece->part_category_id,
            'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 3000, 'stock_quantity' => 1, 'is_active' => true,
        ]);
        $yzzd1->fitments()->create([
            'vehicle_model_id' => VehicleModel::where('slug', 'hilux-an10')->value('id'),
            'engine_code' => '2RZ-FE', 'source' => FitmentSource::Declared,
            'evidence_level' => FitmentEvidence::MANUFACTURER_OFFICIAL, 'evidence_sources' => ['https://exemple.test/epc'],
        ]);
        $relations = DB::table('oem_number_relations')->count();
        $numeros = DB::table('oem_numbers')->count();
        $pivots = DB::table('oem_number_part')->count();

        Yzzd2::run();

        $this->assertSame($relations, DB::table('oem_number_relations')->count());
        $this->assertSame($numeros, DB::table('oem_numbers')->count());
        $this->assertSame($pivots, DB::table('oem_number_part')->count());
        $this->assertSame(0, $this->piece->fitments()->where('engine_code', '2RZ-FE')->count());
        $this->assertSame(0, $this->piece->fitments()->where('evidence_level', FitmentEvidence::MANUFACTURER_OFFICIAL)->count());
        $this->assertSame(1, $yzzd1->fitments()->count());
    }

    public function test_rejouer_la_qualification_ne_change_rien(): void
    {
        Yzzd2::run();
        $avant = $this->instantane();

        $rapport = Yzzd2::run();

        $this->assertSame(0, $rapport['description_corrigee']);
        $this->assertSame(0, $rapport['fitments_qualifies']);
        $this->assertSame(0, $rapport['fitments_ajoutes']);
        $this->assertSame(8, $rapport['fitments_deja_presents']);
        $this->assertSame(0, $rapport['notes_prado']);
        $this->assertSame($avant, $this->instantane());
    }

    public function test_la_migration_est_reversible_a_l_identique(): void
    {
        $avant = $this->instantane();
        $migration = $this->migration();

        $migration->up();
        $this->assertSame(14, $this->piece->fitments()->count());
        $migration->up();
        $this->assertSame(14, $this->piece->fitments()->count());

        $migration->down();
        $this->assertSame($avant, $this->instantane());
        $migration->down();
        $this->assertSame($avant, $this->instantane());
    }

    public function test_un_champ_deja_renseigne_n_est_pas_ecrase(): void
    {
        $cible = Yzzd2::existantes()[0];
        $this->ligne($cible)->update(['evidence_level' => FitmentEvidence::UNVERIFIED]);
        $autre = Yzzd2::existantes()[1];
        $this->ligne($autre)->update(['notes' => 'Note reprise a la main.']);
        $this->piece->update(['description' => 'Description reprise a la main.']);
        $figees = [$this->ligne($cible)->only(['evidence_level', 'evidence_sources', 'notes']), $this->ligne($autre)->only(['evidence_level', 'evidence_sources', 'notes'])];

        $rapport = Yzzd2::run();

        $this->assertSame(2, $rapport['fitments_qualifies']);
        $this->assertSame(0, $rapport['description_corrigee']);
        $this->assertSame('Description reprise a la main.', $this->piece->fresh()->description);
        $this->assertSame($figees, [$this->ligne($cible)->only(['evidence_level', 'evidence_sources', 'notes']), $this->ligne($autre)->only(['evidence_level', 'evidence_sources', 'notes'])]);
    }

    public function test_un_couple_deja_present_n_est_pas_double(): void
    {
        $cible = Yzzd2::nouvelles()[1]; // Hilux AN10 2TR-FE
        $this->piece->fitments()->create([
            'vehicle_model_id' => VehicleModel::where('slug', $cible['model_slug'])->value('id'),
            'engine_code' => $cible['engine_code'], 'year_from' => 2006, 'year_to' => 2012,
            'source' => FitmentSource::Declared, 'notes' => 'Saisie manuelle.',
        ]);

        $rapport = Yzzd2::run();

        $this->assertSame(7, $rapport['fitments_ajoutes']);
        $this->assertSame(1, $rapport['fitments_deja_presents']);
        $this->assertSame(1, $this->piece->fitments()->where('engine_code', '2TR-FE')
            ->where('vehicle_model_id', VehicleModel::where('slug', 'hilux-an10')->value('id'))->count());
    }

    /** @return array<string, array{0: string, 1: int, 2: array<string, mixed>}> */
    public static function editionsManuelles(): array
    {
        return [
            'existante : source ajoutée' => ['existantes', 0, ['evidence_sources' => [Yzzd2::BOSHOKU_URL, 'https://exemple.test/vin']]],
            'existante : niveau promu' => ['existantes', 3, ['evidence_level' => FitmentEvidence::MANUFACTURER_EPC]],
            'existante : note complétée' => ['existantes', 2, ['notes' => 'Verifie par VIN.']],
            'nouvelle : marché posé' => ['nouvelles', 1, ['market_codes' => ['AFR']]],
            'nouvelle : châssis posé' => ['nouvelles', 4, ['chassis_code' => 'KUN51']],
            'nouvelle : année corrigée' => ['nouvelles', 2, ['year_to' => 2024]],
            'nouvelle : niveau promu' => ['nouvelles', 6, ['evidence_level' => FitmentEvidence::DEALER_CATALOG]],
            'prado : note reprise' => ['prado', 1, ['notes' => 'Prado verifie au VIN.']],
        ];
    }

    /** @param  array<string, mixed>  $edition */
    #[DataProvider('editionsManuelles')]
    public function test_down_tout_ou_rien_par_ligne(string $groupe, int $index, array $edition): void
    {
        $migration = $this->migration();
        $migration->up();
        $cible = Yzzd2::$groupe()[$index];
        $ligne = $this->ligne($cible);
        $ligne->update($edition);
        $champs = ['engine_code', 'year_from', 'year_to', 'evidence_level', 'evidence_sources', 'market_codes', 'chassis_code', 'notes'];
        $figee = $ligne->fresh()->only($champs);
        $id = $ligne->id;

        $migration->down();

        $this->assertSame($figee, PartFitment::findOrFail($id)->only($champs), 'ligne éditée : intacte');
        // Toutes les autres sont rétablies ou supprimées.
        $this->assertSame(6 + ($groupe === 'nouvelles' ? 1 : 0), $this->piece->fitments()->count());
        $this->assertSame($groupe === 'existantes' ? 1 : ($groupe === 'nouvelles' ? 1 : 0),
            $this->piece->fitments()->whereNotNull('evidence_level')->count());
        $this->assertSame(Yzzd2::DESCRIPTION_AVANT, $this->piece->fresh()->description);
    }

    public function test_le_seeder_rejoue_conserve_preuves_et_lignes_ajoutees(): void
    {
        Yzzd2::run();
        $attendu = $this->instantaneSansIds();

        $this->seed(FiltresHuileBoutiqueSeeder::class);
        $this->assertSame($attendu, $this->instantaneSansIds());
        $this->assertSame(Yzzd2::DESCRIPTION_APRES, $this->piece->fresh()->description);

        $this->seed(FiltresHuileBoutiqueSeeder::class);
        $this->assertSame($attendu, $this->instantaneSansIds());
    }

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_230000_qualifier_les_preuves_boshoku_du_filtre_90915_yzzd2.php');
    }

    /** @param  array{model_slug: string, engine_code: string, year_from: ?int, year_to: ?int}  $cible */
    private function ligne(array $cible): PartFitment
    {
        $q = $this->piece->fitments()
            ->where('vehicle_model_id', VehicleModel::where('slug', $cible['model_slug'])->value('id'))
            ->where('engine_code', $cible['engine_code']);
        $cible['year_from'] === null ? $q->whereNull('year_from') : $q->where('year_from', $cible['year_from']);
        $cible['year_to'] === null ? $q->whereNull('year_to') : $q->where('year_to', $cible['year_to']);

        return $q->firstOrFail();
    }

    /** @return array{description: ?string, lignes: list<array<string, mixed>>} */
    private function instantane(): array
    {
        return [
            'description' => $this->piece->fresh()->description,
            'lignes' => $this->piece->fresh()->fitments()->orderBy('id')->get()
                ->map(fn (PartFitment $f) => ['id' => $f->id] + $this->champs($f))->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function instantaneSansIds(): array
    {
        $this->piece = Part::where('sku', Yzzd2::SKU)->firstOrFail();

        return $this->piece->fitments()->get()
            ->map(fn (PartFitment $f) => $this->champs($f))
            ->sortBy(fn ($l) => $l['vehicle_model_id'].'|'.$l['engine_code'].'|'.$l['year_from'])
            ->values()->all();
    }

    /** @return array<string, mixed> */
    private function champs(PartFitment $f): array
    {
        return [
            'vehicle_model_id' => $f->vehicle_model_id,
            'engine_code' => $f->engine_code,
            'year_from' => $f->year_from,
            'year_to' => $f->year_to,
            'evidence_level' => $f->evidence_level,
            'evidence_sources' => $f->evidence_sources,
            'market_codes' => $f->market_codes,
            'month_from' => $f->month_from,
            'month_to' => $f->month_to,
            'chassis_code' => $f->chassis_code,
            'notes' => $f->notes,
            'source' => $f->source?->value ?? $f->source,
        ];
    }
}
