<?php

namespace Tests\Feature;

use App\Enums\CheckReason;
use App\Models\OwnedVehicle;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * La contrainte de motif des controles, apres l'ajout du controle saisonnier.
 *
 * La migration 2026_09_29 a laisse sous PostgreSQL un CHECK borne a trip et
 * periodic. Ces bancs reproduisent cet etat, rejouent le correctif, et
 * verifient que chaque motif de CheckReason s'insere, y compris seasonal.
 */
class MotifSeasonalDesControlesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function migration(): object
    {
        return require database_path('migrations/2026_11_26_180000_autoriser_le_motif_seasonal_des_controles.php');
    }

    public function test_les_motifs_autorises_sont_ceux_de_l_enum(): void
    {
        $this->assertSame(
            ['trip', 'seasonal'],
            array_map(fn (CheckReason $motif) => $motif->value, CheckReason::cases()),
        );
    }

    public function test_chaque_motif_de_l_enum_s_insere_et_un_motif_inconnu_est_refuse(): void
    {
        $user = User::factory()->create();
        $vehicule = OwnedVehicle::factory()->create(['user_id' => $user->id]);

        foreach (CheckReason::cases() as $index => $motif) {
            DB::table('vehicle_checks')->insert([
                'owned_vehicle_id' => $vehicule->id,
                'user_id' => $user->id,
                'reason' => $motif->value,
                'performed_at' => now()->subMinutes($index),
                'verdict' => 'clear',
                'blocking_count' => 0,
                'watch_count' => 0,
                'checked_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertSame(
            array_map(fn (CheckReason $motif) => $motif->value, CheckReason::cases()),
            DB::table('vehicle_checks')->orderBy('id')->pluck('reason')->all(),
        );

        $this->expectException(QueryException::class);
        DB::table('vehicle_checks')->insert([
            'owned_vehicle_id' => $vehicule->id,
            'user_id' => $user->id,
            'reason' => 'inventaire',
            'performed_at' => now(),
            'verdict' => 'clear',
            'blocking_count' => 0,
            'watch_count' => 0,
            'checked_count' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_une_base_bloquee_comme_en_production_accepte_seasonal_apres_la_migration(): void
    {
        $this->ancienneBase(function (): void {
            try {
                DB::table('vehicle_checks')->insert($this->ligne('avant', 'seasonal'));
                $this->fail('Avant le correctif, seasonal doit etre refuse comme en production.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('vehicle_checks', ['note' => 'avant']);
            }

            $this->migration()->up();
            $this->migration()->up();

            DB::table('vehicle_checks')->insert($this->ligne('ok-trip', 'trip'));
            DB::table('vehicle_checks')->insert($this->ligne('ok-seasonal', 'seasonal'));
            $this->assertDatabaseHas('vehicle_checks', ['note' => 'ok-trip', 'reason' => 'trip']);
            $this->assertDatabaseHas('vehicle_checks', ['note' => 'ok-seasonal', 'reason' => 'seasonal']);
            $this->assertDatabaseHas('vehicle_check_answers', ['item_code' => 'pneus-usure', 'title' => 'Reponse conservee']);

            try {
                DB::table('vehicle_checks')->insert($this->ligne('periodic', 'periodic'));
                $this->fail('periodic n est plus un motif valide : il ne doit pas reapparaitre.');
            } catch (QueryException) {
                $this->assertDatabaseMissing('vehicle_checks', ['note' => 'periodic']);
            }

            $this->expectException(QueryException::class);
            DB::table('vehicle_checks')->insert($this->ligne('inventaire', 'inventaire'));
        });
    }

    public function test_une_base_dont_le_check_a_deja_disparu_recoit_les_motifs_de_l_enum(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();
            DB::table('vehicle_checks')->insert($this->ligne('ok-seasonal', 'seasonal'));
            $this->assertDatabaseHas('vehicle_checks', ['note' => 'ok-seasonal', 'reason' => 'seasonal']);
            $this->assertDatabaseHas('vehicle_check_answers', ['item_code' => 'pneus-usure']);

            $this->expectException(QueryException::class);
            DB::table('vehicle_checks')->insert($this->ligne('inventaire', 'inventaire'));
        }, avecAncienCheck: false);
    }

    public function test_le_retour_arriere_refuse_de_perdre_un_controle_saisonnier(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();
            DB::table('vehicle_checks')->insert($this->ligne('saison', 'seasonal'));

            try {
                $this->migration()->down();
                $this->fail('Un retour arriere ne doit pas retrecir la contrainte sur une ligne seasonal.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('Retour arriere refuse', $exception->getMessage());
                $this->assertDatabaseHas('vehicle_checks', ['note' => 'saison', 'reason' => 'seasonal']);
                $this->assertDatabaseHas('vehicle_check_answers', ['item_code' => 'pneus-usure']);
            }
        });
    }

    public function test_une_base_sans_motif_nouveau_peut_revenir_en_arriere(): void
    {
        $this->ancienneBase(function (): void {
            $this->migration()->up();
            $this->migration()->down();

            $this->assertDatabaseHas('vehicle_checks', ['note' => 'origine', 'reason' => 'trip']);
            $this->assertDatabaseHas('vehicle_check_answers', ['item_code' => 'pneus-usure']);

            DB::table('vehicle_checks')->insert($this->ligne('periodic-apres', 'periodic'));
            $this->assertDatabaseHas('vehicle_checks', ['note' => 'periodic-apres', 'reason' => 'periodic']);

            $this->expectException(QueryException::class);
            DB::table('vehicle_checks')->insert($this->ligne('seasonal-apres', 'seasonal'));
        });
    }

    /**
     * @param  callable(): void  $assertions
     */
    private function ancienneBase(callable $assertions, bool $avecAncienCheck = true): void
    {
        $previous = DB::getDefaultConnection();
        config(['database.connections.reason_migration_test' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        DB::setDefaultConnection('reason_migration_test');

        try {
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('owned_vehicles', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('vehicle_checks', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('owned_vehicle_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('reason', 20)->default('trip');
                $table->timestamp('performed_at');
                $table->enum('verdict', ['blocked', 'attention', 'clear']);
                $table->unsignedTinyInteger('blocking_count')->default(0);
                $table->unsignedTinyInteger('watch_count')->default(0);
                $table->unsignedTinyInteger('checked_count')->default(0);
                $table->text('note')->nullable();
                $table->timestamps();
            });
            if ($avecAncienCheck) {
                $sql = DB::table('sqlite_master')->where('type', 'table')->where('name', 'vehicle_checks')->value('sql');
                $sql = preg_replace('/\)\s*$/', ", CHECK (\"reason\" IN ('trip', 'periodic')))", $sql, 1, $ajouts);
                if ($ajouts !== 1) {
                    throw new RuntimeException('Impossible d ajouter le CHECK de production a la table de test.');
                }
                Schema::disableForeignKeyConstraints();
                try {
                    $colonnes = collect(Schema::getColumns('vehicle_checks'))->whereNull('generation')
                        ->map(fn (array $colonne) => '"'.$colonne['name'].'"')->implode(', ');
                    $objets = DB::table('sqlite_master')->where('tbl_name', 'vehicle_checks')
                        ->whereIn('type', ['index', 'trigger'])->whereNotNull('sql')->pluck('sql');
                    DB::statement('ALTER TABLE vehicle_checks RENAME TO vehicle_checks_old');
                    DB::statement($sql);
                    DB::statement("INSERT INTO vehicle_checks ($colonnes) SELECT $colonnes FROM vehicle_checks_old");
                    DB::statement('DROP TABLE vehicle_checks_old');
                    foreach ($objets as $objet) {
                        DB::statement($objet);
                    }
                } finally {
                    Schema::enableForeignKeyConstraints();
                }
            }
            Schema::create('vehicle_check_answers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('vehicle_check_id')->constrained()->cascadeOnDelete();
                $table->string('item_code', 60);
                $table->string('title');
            });

            DB::table('users')->insert(['id' => 1]);
            DB::table('owned_vehicles')->insert(['id' => 1]);
            DB::table('vehicle_checks')->insert($this->ligne('origine', 'trip'));
            DB::table('vehicle_check_answers')->insert([
                'vehicle_check_id' => 1,
                'item_code' => 'pneus-usure',
                'title' => 'Reponse conservee',
            ]);

            $assertions();
        } finally {
            DB::purge('reason_migration_test');
            DB::setDefaultConnection($previous);
        }
    }

    /** @return array<string, mixed> */
    private function ligne(string $note, string $motif): array
    {
        return [
            'owned_vehicle_id' => 1,
            'user_id' => 1,
            'reason' => $motif,
            'performed_at' => '2026-11-26 12:00:00',
            'verdict' => 'clear',
            'blocking_count' => 0,
            'watch_count' => 0,
            'checked_count' => 0,
            'note' => $note,
            'created_at' => '2026-11-26 12:00:00',
            'updated_at' => '2026-11-26 12:00:00',
        ];
    }
}
