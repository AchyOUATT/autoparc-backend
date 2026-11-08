<?php

namespace Tests\Feature;

use Database\Seeders\SinotrukCatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SinotrukCatalogTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Dedicated in-memory connection: never run global seeders or touch real data.
        config()->set('database.connections.sinotruk_test', [
            'driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('sinotruk_test');
        config()->set('catalogue.test_sinotruk_migration', true);
        foreach ([
            '2026_01_01_000100_create_reference_tables.php',
            '2026_09_01_100000_add_defaults_to_vehicle_models_table.php',
        ] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
    }

    protected function tearDown(): void
    {
        DB::purge('sinotruk_test');
        parent::tearDown();
    }

    private function import(): void
    {
        (new SinotrukCatalogSeeder)->run();
    }

    private function snapshot(string $table): array
    {
        return DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function test_empty_catalog_is_imported_exactly_once(): void
    {
        $this->import();
        $before = $this->snapshot('vehicle_models');
        $brand = $this->snapshot('brands');
        $country = $this->snapshot('countries');
        $this->import();
        $this->assertCount(12, $before);
        $this->assertSame($before, $this->snapshot('vehicle_models'));
        $this->assertSame($brand, $this->snapshot('brands'));
        $this->assertSame($country, $this->snapshot('countries'));
        $this->assertSame('ZZ', $brand[0]['oem_prefix']);
        $this->assertSame('CN', $country[0]['iso2']);
        $this->assertEquals($country[0]['id'], $brand[0]['country_id']);
    }

    public function test_all_names_and_unknown_attributes_are_explicit(): void
    {
        $this->import();
        $names = [];
        foreach (DB::table('vehicle_models')->orderBy('id')->get() as $model) {
            $names[] = $model->name;
            $this->assertSame('heavy', $model->default_vehicle_type);
            $this->assertSame('camion', $model->body_type);
            $this->assertNull($model->segment);
            $this->assertNull($model->generation);
            $this->assertNull($model->production_start);
            $this->assertNull($model->production_end);
        }
        $this->assertSame([
            'HOWO A7', 'HOWO T7H', 'HOWO T5G', 'HOWO TX', 'HOWO NX', 'HOWO 7',
            'SITRAK C7H', 'SITRAK C9H', 'SITRAK G7', 'SITRAK G7S', 'HOHAN E7', 'HOHAN N7',
        ], $names);
        $this->assertSame('howo-a7', DB::table('vehicle_models')->where('name', 'HOWO A7')->value('slug'));
    }

    public function test_existing_country_and_inactive_brand_are_unchanged(): void
    {
        DB::table('countries')->insert(['iso2' => 'CN', 'iso3' => 'CHN', 'name' => 'Custom China']);
        DB::table('brands')->insert([
            'name' => 'Sinotruk', 'slug' => 'sinotruk', 'oem_prefix' => 'CUSTOM', 'is_active' => false,
        ]);
        $countries = $this->snapshot('countries');
        $brands = $this->snapshot('brands');
        $this->import();
        $this->assertSame($countries, $this->snapshot('countries'));
        $this->assertSame($brands, $this->snapshot('brands'));
        $this->assertSame(12, DB::table('vehicle_models')->count());
    }

    public function test_partial_catalog_preserves_existing_model_and_other_brands(): void
    {
        $this->import();
        $brandId = DB::table('brands')->value('id');
        DB::table('vehicle_models')->where('slug', '!=', 'howo-a7')->delete();
        DB::table('vehicle_models')->where('slug', 'howo-a7')->update([
            'is_active' => false, 'segment' => 'custom', 'production_start' => 2010,
        ]);
        $existing = (array) DB::table('vehicle_models')->first();
        $otherId = DB::table('brands')->insertGetId(['name' => 'Other', 'slug' => 'other']);
        DB::table('vehicle_models')->insert(['brand_id' => $otherId, 'name' => 'HOWO A7', 'slug' => 'howo-a7']);
        $other = (array) DB::table('vehicle_models')->where('brand_id', $otherId)->first();
        $this->import();
        $this->assertSame(12, DB::table('vehicle_models')->where('brand_id', $brandId)->count());
        $this->assertSame($existing, (array) DB::table('vehicle_models')->where('id', $existing['id'])->first());
        $this->assertSame($other, (array) DB::table('vehicle_models')->where('brand_id', $otherId)->first());
    }

    public function test_a_failure_rolls_back_country_brand_and_models(): void
    {
        DB::unprepared("CREATE TRIGGER reject_sinotruk BEFORE INSERT ON vehicle_models
            WHEN NEW.slug = 'howo-t5g' BEGIN SELECT RAISE(ABORT, 'simulated failure'); END");
        try {
            $this->import();
            $this->fail('Import must propagate database failures.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('simulated failure', $exception->getMessage());
        }
        $this->assertSame([], $this->snapshot('countries'));
        $this->assertSame([], $this->snapshot('brands'));
        $this->assertSame([], $this->snapshot('vehicle_models'));
    }

    public function test_migration_rollback_preserves_preexisting_and_referenced_rows(): void
    {
        $this->import();
        $migration = require database_path('migrations/2026_11_07_000001_add_sinotruk_catalog.php');
        $migration->up();
        Schema::create('sinotruk_references', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('vehicle_model_id')->constrained('vehicle_models');
        });
        DB::table('sinotruk_references')->insert(['vehicle_model_id' => DB::table('vehicle_models')->value('id')]);
        $before = $this->snapshot('vehicle_models');
        $migration->down();
        $this->assertSame($before, $this->snapshot('vehicle_models'));
        $this->assertSame(1, DB::table('sinotruk_references')->count());
        $migration->up();
        $this->assertSame($before, $this->snapshot('vehicle_models'));
    }

    public function test_ambiguous_brand_identity_fails_without_partial_writes(): void
    {
        DB::table('brands')->insert([
            ['name' => 'Sinotruk', 'slug' => 'custom-sinotruk'],
            ['name' => 'Custom label', 'slug' => 'sinotruk'],
        ]);
        $before = $this->snapshot('brands');
        try {
            $this->import();
            $this->fail('Ambiguous brand must not be guessed.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Ambiguous Sinotruk', $exception->getMessage());
        }
        $this->assertSame($before, $this->snapshot('brands'));
        $this->assertSame([], $this->snapshot('countries'));
        $this->assertSame([], $this->snapshot('vehicle_models'));
    }

    public function test_ordinary_test_migrations_leave_the_catalog_empty(): void
    {
        config()->set('catalogue.test_sinotruk_migration', false);
        (require database_path('migrations/2026_11_07_000001_add_sinotruk_catalog.php'))->up();
        $this->assertSame([], $this->snapshot('countries'));
        $this->assertSame([], $this->snapshot('brands'));
        $this->assertSame([], $this->snapshot('vehicle_models'));
    }

    public function test_non_test_migration_imports_without_the_test_opt_in(): void
    {
        // Same in-memory database, but exercise the actual non-test entry path.
        $this->app->instance('env', 'production');
        config()->set('catalogue.test_sinotruk_migration', false);
        try {
            (require database_path('migrations/2026_11_07_000001_add_sinotruk_catalog.php'))->up();
            $this->assertSame(1, DB::table('brands')->count());
            $this->assertSame(12, DB::table('vehicle_models')->count());
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_migration_alone_imports_into_empty_catalog(): void
    {
        (require database_path('migrations/2026_11_07_000001_add_sinotruk_catalog.php'))->up();
        $this->assertSame(1, DB::table('brands')->count());
        $this->assertSame(12, DB::table('vehicle_models')->count());
    }
}
