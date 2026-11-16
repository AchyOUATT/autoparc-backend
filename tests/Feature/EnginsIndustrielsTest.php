<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Part;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Les engins des filtres industriels, et le RANG de leurs sources.
 *
 * ── CE QUE CE BANC PROTEGE ─────────────────────────────────────────────────
 *
 * Les generalisations restent interdites : « ce filtre couvre la famille
 * C7.1 », « 462-1171 couvre C4.4, C6.6 et C7.1 ». Ce qui est pose, ce sont
 * des liens NOMMES par une source — y compris les guides officiels John Deere
 * et les fiches Caterpillar consultes le 16/11/2026, qui ont fait passer
 * plusieurs lignes au premier rang.
 *
 * Le banc tient cette frontiere. Il ne verifie pas que les machines existent
 * — ca, c est le travail des sources — mais que le lot ne REDEVIENNE PAS une
 * generalisation : chaque compatibilite de premier rang est une machine
 * nommee, les autres sont marquees comme telles, et le code moteur est
 * present la ou il separe deux generations.
 *
 * ── LE CAS McCORMICK, ET POURQUOI SON CODE MOTEUR EST VITAL ────────────────
 *
 * La MTX 1re generation (2000-2003) porte un Perkins 1006-60T : c est pour
 * elle que MANN donne le 26560145. La MTX Serie II (MTX 120/135/150,
 * 2004-2007) porte un Iveco. Deux generations, deux moteurs, un seul nom de
 * famille. Le code moteur porte par la compatibilite est ce qui les separe —
 * sans lui, un vendeur poserait un filtre Perkins sur une machine Iveco.
 *
 * C est exactement la faute qui a fait refuser quinze lignes du catalogue
 * Toyota, et celle qu une recherche de ce dossier a reproduite en pretant un
 * C7.1 a une niveleuse 140M2 qui porte un C9.3.
 */
class EnginsIndustrielsTest extends TestCase
{
    use RefreshDatabase;

    private const SLUGS = [
        'cat-de150-gc', 'cat-140-gc', 'cat-de200-gc', 'cat-de220-gc', 'cat-xqp150',
        'jd-5075m', 'jd-5090m', 'jd-5100m', 'jd-5115m', 'jd-5410', 'jd-5510',
        'mccormick-mtx-110', 'mccormick-mtx-125', 'mccormick-mtx-140',
        'jcb-3cx', 'jcb-3dx',
    ];

    private const PREMIER_RANG = [
        'cat-de150-gc', 'cat-140-gc', 'cat-de200-gc', 'cat-de220-gc', 'cat-xqp150',
        'jd-5075m', 'jd-5090m', 'jd-5100m', 'jd-5115m', 'jd-5410', 'jd-5510',
    ];

    /**
     * Le banc pose ses propres categories, puis les deux seeders.
     *
     * La migration est INERTE en test faute de categories — c est voulu, et
     * c est ce qui evite de polluer les 49 tests qui creent les leurs. Voir
     * `FiltresIndustrielsTest`, qui porte la meme amorce et la meme lecon.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\PartCategoriesSeeder::class);

        foreach ([
            \Database\Seeders\FiltresIndustrielsBoutiqueSeeder::class,
            \Database\Seeders\EnginsIndustrielsSeeder::class,
        ] as $seeder) {
            $this->artisan('db:seed', ['--class' => $seeder, '--force' => true])
                ->assertSuccessful();
        }
    }

    public function test_les_quatre_marques_de_machines_entrent(): void
    {
        // Perkins, Fleetguard et Cummins ne doivent PAS y etre : ce sont des
        // motoristes et des equipementiers, pas des constructeurs de machine.
        // Ils vivent dans `manufacturers`, ou les filtres les ont deja poses.
        $this->assertSame(
            ['Caterpillar', 'JCB', 'John Deere', 'McCormick'],
            Brand::whereIn('slug', ['caterpillar', 'jcb', 'john-deere', 'mccormick'])
                ->orderBy('name')->pluck('name')->all(),
        );

        $this->assertFalse(
            Brand::whereIn('name', ['Perkins', 'Fleetguard', 'Cummins'])->exists(),
            'Un motoriste est entre dans les marques de vehicules : Perkins ne construit aucune machine.'
        );
    }

    public function test_les_machines_sourcees_entrent(): void
    {
        $this->assertSame(
            count(self::SLUGS),
            VehicleModel::whereIn('slug', self::SLUGS)->count()
        );
    }

    public function test_les_compatibilites_de_premier_rang_sont_celles_des_guides_officiels(): void
    {
        // Une source de premier rang nomme la machine ET la piece chez le
        // constructeur. Si cette liste s'allonge sans qu'une source soit
        // ajoutee, c'est qu'une generalisation est revenue.
        $rangs = DB::table('part_fitments')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->whereIn('vehicle_models.slug', self::SLUGS)
            ->select('vehicle_models.slug', 'part_fitments.notes')
            ->get();

        $premiers = $rangs->filter(fn ($r) => str_contains($r->notes, 'PREMIER RANG'))
            ->pluck('slug')->sort()->values()->all();
        $seconds = $rangs->filter(fn ($r) => str_contains($r->notes, 'SECOND RANG'))
            ->pluck('slug')->sort()->values()->all();

        $this->assertSame(
            collect(self::PREMIER_RANG)->sort()->values()->all(),
            $premiers,
            'La liste des compatibilites de premier rang a change.'
        );
        $this->assertSame(
            ['jcb-3cx', 'jcb-3dx', 'mccormick-mtx-110', 'mccormick-mtx-125', 'mccormick-mtx-140'],
            $seconds,
        );
    }

    public function test_chaque_compatibilite_porte_le_rang_de_sa_source(): void
    {
        $sans = DB::table('part_fitments')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->whereIn('vehicle_models.slug', self::SLUGS)
            ->where('part_fitments.notes', 'not like', '%SOURCE DE PREMIER RANG%')
            ->where('part_fitments.notes', 'not like', '%SOURCE DE SECOND RANG%')
            ->pluck('vehicle_models.name');

        $this->assertTrue(
            $sans->isEmpty(),
            'Une compatibilite ne dit pas d ou elle vient : '.$sans->implode(', ')
        );
    }

    public function test_le_de150_gc_porte_la_compatibilite_du_constructeur(): void
    {
        $f = DB::table('part_fitments')
            ->join('parts', 'parts.id', '=', 'part_fitments.part_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('vehicle_models.slug', 'cat-de150-gc')
            ->first(['parts.sku', 'part_fitments.engine_code', 'part_fitments.notes']);

        $this->assertNotNull($f);
        $this->assertSame('462-1171', $f->sku);
        $this->assertSame('C7.1', $f->engine_code);
        $this->assertStringContainsString('610-3587', $f->notes, 'La source exacte doit etre citee.');
    }

    public function test_le_code_moteur_separe_les_deux_generations_mccormick(): void
    {
        // Sans lui, un vendeur poserait un filtre Perkins sur une MTX Serie II
        // a moteur Iveco.
        $moteurs = DB::table('part_fitments')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('vehicle_models.slug', 'like', 'mccormick-%')
            ->pluck('part_fitments.engine_code')
            ->unique();

        $this->assertSame(['1006-60T'], $moteurs->values()->all());
    }

    public function test_aucune_machine_ne_reclame_un_type_de_vehicule(): void
    {
        // `default_vehicle_type` n'a que passenger|utility|heavy : aucune case
        // pour un groupe electrogene ni une tractopelle. Un NULL honnete vaut
        // mieux qu'un champ detourne.
        $this->assertTrue(
            VehicleModel::whereIn('slug', self::SLUGS)->whereNotNull('default_vehicle_type')->get()->isEmpty(),
            'Une machine s est vu attribuer un type de vehicule qui ne lui convient pas.'
        );
    }

    public function test_rejouer_le_seeder_n_empile_pas_les_compatibilites(): void
    {
        $avant = DB::table('part_fitments')->count();

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EnginsIndustrielsSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame($avant, DB::table('part_fitments')->count(), 'Les compatibilites s empilent.');
        $this->assertSame(count(self::SLUGS), VehicleModel::whereIn('slug', self::SLUGS)->count());
    }

    public function test_le_de150_gc_ne_porte_pas_le_435_6493(): void
    {
        // La fiche officielle de 435-6493 cite le DE150GC, mais le kit
        // 610-3587 de cette machine ne contient pas la reference. On ne
        // relie pas les deux.
        $skus = DB::table('part_fitments')
            ->join('parts', 'parts.id', '=', 'part_fitments.part_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('vehicle_models.slug', 'cat-de150-gc')
            ->pluck('parts.sku');

        $this->assertSame(['462-1171'], $skus->all());
        $this->assertNotContains('435-6493', $skus->all());
    }

    public function test_le_435_6493_est_relie_aux_machines_nommees_par_caterpillar(): void
    {
        $slugs = DB::table('part_fitments')
            ->join('parts', 'parts.id', '=', 'part_fitments.part_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('parts.sku', '435-6493')
            ->pluck('vehicle_models.slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['cat-140-gc', 'cat-de200-gc', 'cat-de220-gc', 'cat-xqp150'], $slugs);
    }

    public function test_le_re504836_couvre_les_quatre_tracteurs_5m(): void
    {
        $slugs = DB::table('part_fitments')
            ->join('parts', 'parts.id', '=', 'part_fitments.part_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('parts.sku', 'RE504836')
            ->pluck('vehicle_models.slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['jd-5075m', 'jd-5090m', 'jd-5100m', 'jd-5115m'], $slugs);
    }

    public function test_le_re59754_est_relie_aux_5410_et_5510(): void
    {
        $slugs = DB::table('part_fitments')
            ->join('parts', 'parts.id', '=', 'part_fitments.part_id')
            ->join('vehicle_models', 'vehicle_models.id', '=', 'part_fitments.vehicle_model_id')
            ->where('parts.sku', 'RE59754')
            ->pluck('vehicle_models.slug')
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['jd-5410', 'jd-5510'], $slugs);
    }

    public function test_la_niveleuse_140_gc_n_est_pas_rangee_comme_groupe(): void
    {
        $modele = VehicleModel::where('slug', 'cat-140-gc')->firstOrFail();

        $this->assertSame('Niveleuse', $modele->body_type);
        $this->assertSame('other', $modele->equipment_category);
    }

    public function test_une_machine_desactivee_a_la_main_le_reste(): void
    {
        $modele = VehicleModel::where('slug', 'jcb-3dx')->firstOrFail();
        $modele->update(['is_active' => false]);

        $this->artisan('db:seed', [
            '--class' => \Database\Seeders\EnginsIndustrielsSeeder::class,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFalse(
            (bool) $modele->fresh()->is_active,
            'Une relance a rallume une machine retiree du selecteur a la main.'
        );
    }
}
