<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PartnerAccountsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_10_22_000001_create_partner_accounts.php');
    }

    private function account(int $userId): int
    {
        return DB::table('partner_accounts')->insertGetId([
            'user_id' => $userId,
            'company_name' => 'Garage partenaire',
            'contact_name' => 'Responsable',
            'phone' => '+22670000000',
        ]);
    }

    public static function inventories(): array
    {
        return [['vehicles'], ['parts'], ['accessories']];
    }

    #[DataProvider('inventories')]
    public function test_un_proprietaire_ne_peut_pas_disparaitre_et_transformer_son_stock_en_stock_interne(string $table): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $accountId = $this->account($user->id);

        $attributes = match ($table) {
            'vehicles' => $this->vehicleAttributes(),
            'parts' => [
                'name' => 'Filtre partenaire',
                'part_category_id' => PartCategory::create(['name' => 'Filtration'])->id,
            ],
            'accessories' => ['name' => 'Tapis partenaire', 'category' => 'confort'],
        };

        $id = DB::table($table)->insertGetId(array_merge($attributes, [
            'owner_partner_account_id' => $accountId,
        ]));

        try {
            DB::transaction(fn () => DB::table('partner_accounts')->where('id', $accountId)->delete());
            $this->fail('La suppression du proprietaire aurait rendu son annonce interne.');
        } catch (QueryException) {
            $this->assertDatabaseHas('partner_accounts', ['id' => $accountId]);
            $this->assertDatabaseHas($table, ['id' => $id, 'owner_partner_account_id' => $accountId]);
        }
    }

    private function vehicleAttributes(): array
    {
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);

        return [
            'reference' => 'PARTNER-SCHEMA-VEHICLE',
            'brand_id' => $brand->id,
            'vehicle_model_id' => $model->id,
            'engine_type_id' => EngineType::create(['code' => 'petrol', 'label' => 'Essence'])->id,
            'manufacturing_year' => 2018,
        ];
    }

    public function test_un_compte_partenaire_empeche_la_suppression_de_son_utilisateur(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $accountId = $this->account($user->id);

        try {
            DB::transaction(fn () => DB::table('users')->where('id', $user->id)->delete());
            $this->fail('Un compte partenaire ne doit pas perdre son utilisateur.');
        } catch (QueryException) {
            $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'partner']);
            $this->assertDatabaseHas('partner_accounts', ['id' => $accountId, 'user_id' => $user->id]);
        }
    }

    public function test_un_utilisateur_ne_peut_pas_posseder_deux_comptes_entreprise(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $this->account($user->id);

        $this->expectException(QueryException::class);
        $this->account($user->id);
    }

    public function test_le_rollback_refuse_un_partenaire_sans_le_promouvoir_en_personnel(): void
    {
        $user = User::factory()->create(['role' => 'partner']);
        $accountId = $this->account($user->id);

        try {
            $this->migration()->down();
            $this->fail('Le rollback doit demander une migration explicite des partenaires.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Retour arriere refuse', $exception->getMessage());
            $this->assertDatabaseHas('users', ['id' => $user->id, 'role' => 'partner']);
            $this->assertDatabaseHas('partner_accounts', ['id' => $accountId]);

            foreach (['vehicles', 'parts', 'accessories'] as $table) {
                $this->assertTrue(Schema::hasColumns($table, ['owner_partner_account_id', 'partner_published_at']));
            }
        }
    }

    public function test_une_ancienne_base_sqlite_accepte_partner_sans_perdre_les_relations_existantes(): void
    {
        $this->withLegacySqlite(function (): void {
            $this->migration()->up();

            $this->assertSame('client', DB::table('users')->where('id', 1)->value('role'));
            $this->assertSame(1, DB::table('linked_records')->where('id', 1)->value('user_id'));
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));

            foreach (['vehicles', 'parts', 'accessories'] as $table) {
                $this->assertDatabaseHas($table, [
                    'id' => 1, 'owner_partner_account_id' => null, 'partner_published_at' => null,
                ]);
            }

            DB::table('users')->insert(['email' => 'partner@example.test', 'role' => 'partner']);
            $this->assertTrue(DB::table('users')->where('role', 'partner')->exists());
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        });
    }

    public function test_une_base_sans_partenaire_peut_revenir_en_arriere_sans_perdre_ses_clients(): void
    {
        $this->withLegacySqlite(function (): void {
            $this->migration()->up();
            $this->migration()->down();

            $this->assertFalse(Schema::hasTable('partner_accounts'));
            $this->assertSame('client', DB::table('users')->where('id', 1)->value('role'));
            $this->assertSame(1, DB::table('linked_records')->where('id', 1)->value('user_id'));

            foreach (['vehicles', 'parts', 'accessories'] as $table) {
                $this->assertFalse(Schema::hasColumn($table, 'owner_partner_account_id'));
                $this->assertDatabaseHas($table, ['id' => 1]);
            }

            $sql = DB::table('sqlite_master')->where('name', 'users')->value('sql');
            $this->assertStringNotContainsString("'partner'", $sql);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        });
    }

    private function withLegacySqlite(callable $assertions): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.partner_migration_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('partner_migration_test');

        try {
            Schema::create('users', function (Blueprint $table) {
                $table->id();
                $table->string('email')->unique();
                $table->enum('role', ['admin', 'manager', 'sales', 'mechanic', 'warehouse', 'viewer', 'client'])
                    ->default('viewer');
            });
            Schema::create('linked_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            });

            foreach (['vehicles', 'parts', 'accessories'] as $name) {
                Schema::create($name, fn (Blueprint $table) => $table->id());
                DB::table($name)->insert(['id' => 1]);
            }

            DB::table('users')->insert(['id' => 1, 'email' => 'client@example.test', 'role' => 'client']);
            DB::table('linked_records')->insert(['id' => 1, 'user_id' => 1]);
            $assertions();
        } finally {
            DB::purge('partner_migration_test');
            DB::setDefaultConnection($previous);
        }
    }
}
