<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TransmissionModesMigrationTest extends TestCase
{
    private const AVANT = ['manual', 'automatic', 'cvt', 'dual_clutch', 'single_speed'];

    private function migration(): object
    {
        return require database_path('migrations/2026_11_02_000001_completer_les_modes_de_transmission.php');
    }

    public function test_une_ancienne_base_conserve_les_transmissions_et_les_relations_puis_accepte_les_nouveaux_modes(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();

            $this->assertSame([...self::AVANT, null], DB::table('vehicles')->orderBy('id')->pluck('transmission')->all());
            $this->assertSame('Document conserve', DB::table('vehicle_documents')->where('vehicle_id', 1)->value('name'));
            $this->assertSame(1, (int) DB::scalar('PRAGMA foreign_keys'));
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

            foreach (['automated_manual', 'e_cvt'] as $mode) {
                DB::table('vehicles')->insert(['reference' => $mode, 'transmission' => $mode]);
                $this->assertSame($mode, DB::table('vehicles')->where('reference', $mode)->value('transmission'));
                $this->assertGreaterThan(99, DB::table('vehicles')->where('reference', $mode)->value('id'));
            }
            $this->assertSame(2, DB::table('vehicle_audit')->count(), 'Le trigger doit survivre a la reconstruction.');

            try {
                DB::table('vehicles')->insert(['reference' => 'invalid-status', 'status' => 'invented']);
                $this->fail('Le CHECK du statut doit survivre a la migration de transmission.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('vehicles', ['reference' => 'invalid-status']);
            }
            try {
                DB::table('vehicles')->insert(['reference' => 'old-0']);
                $this->fail('L index unique des references doit survivre a la migration.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('vehicles')->where('reference', 'old-0')->count());
            }

            $this->expectException(QueryException::class);
            DB::table('vehicles')->insert(['reference' => 'invalid', 'transmission' => 'tiptronic']);
        });
    }

    public function test_une_base_dont_une_ancienne_migration_a_retire_le_check_recoit_les_sept_modes(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();
            DB::table('vehicles')->insert(['reference' => 'amt', 'transmission' => 'automated_manual']);
            $this->assertDatabaseHas('vehicles', ['reference' => 'amt', 'transmission' => 'automated_manual']);
            $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => 1, 'name' => 'Document conserve']);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

            $this->expectException(QueryException::class);
            DB::table('vehicles')->insert(['reference' => 'invalid', 'transmission' => 'tiptronic']);
        }, avecCheck: false);
    }

    public static function nouveauxModes(): array
    {
        return [
            ['vehicles', 'automated_manual'], ['vehicles', 'e_cvt'],
            ['private_vehicle_listings', 'automated_manual'], ['private_vehicle_listings', 'e_cvt'],
        ];
    }

    #[DataProvider('nouveauxModes')]
    public function test_le_retour_arriere_refuse_de_perdre_un_nouveau_mode(string $table, string $mode): void
    {
        $this->ancienneBase(function () use ($table, $mode): void {
            $this->migration()->up();
            $id = DB::table($table)->insertGetId(['reference' => 'new-mode', 'transmission' => $mode]);

            try {
                $this->migration()->down();
                $this->fail('Un retour arriere ne doit pas convertir silencieusement le mode enregistre.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Retour arriere refuse', $exception->getMessage());
                $this->assertDatabaseHas($table, ['id' => $id, 'transmission' => $mode]);
                $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => 1, 'name' => 'Document conserve']);
            }
        });
    }

    public function test_une_base_sans_nouveau_mode_peut_revenir_en_arriere_sans_perdre_les_lignes(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();
            $this->migration()->down();

            $this->assertSame([...self::AVANT, null], DB::table('vehicles')->orderBy('id')->pluck('transmission')->all());
            $this->assertDatabaseHas('vehicle_documents', ['vehicle_id' => 1, 'name' => 'Document conserve']);
            $this->assertSame([], DB::select('PRAGMA foreign_key_check'));

            $this->expectException(QueryException::class);
            DB::table('vehicles')->insert(['reference' => 'new-after-down', 'transmission' => 'e_cvt']);
        });
    }

    private function ancienneBase(callable $assertions, bool $avecCheck = true): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.transmission_migration_test' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('transmission_migration_test');

        try {
            Schema::create('vehicles', function (Blueprint $table) use ($avecCheck): void {
                $table->id();
                $table->string('reference')->unique();
                if ($avecCheck) {
                    $table->enum('transmission', self::AVANT)->nullable();
                } else {
                    $table->string('transmission')->nullable();
                }
                $table->enum('status', ['active', 'inactive'])->default('active');
            });
            Schema::create('vehicle_documents', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
                $table->string('name');
            });
            Schema::create('private_vehicle_listings', function (Blueprint $table): void {
                $table->id();
                $table->string('reference');
                $table->string('transmission', 20)->nullable();
            });
            Schema::create('vehicle_audit', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('vehicle_id');
            });

            foreach ([...self::AVANT, null] as $index => $mode) {
                DB::table('vehicles')->insert(['id' => $index + 1, 'reference' => 'old-'.$index, 'transmission' => $mode]);
            }
            DB::table('vehicle_documents')->insert(['vehicle_id' => 1, 'name' => 'Document conserve']);
            DB::table('vehicles')->insert(['id' => 99, 'reference' => 'removed']);
            DB::table('vehicles')->where('id', 99)->delete();
            DB::statement('CREATE TRIGGER vehicle_created AFTER INSERT ON vehicles '
                .'BEGIN INSERT INTO vehicle_audit (vehicle_id) VALUES (NEW.id); END');

            $assertions();
        } finally {
            DB::purge('transmission_migration_test');
            DB::setDefaultConnection($previous);
        }
    }
}
