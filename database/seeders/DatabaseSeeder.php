<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Accessory;
use App\Models\Country;
use App\Models\Customer;
use App\Models\Part;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImportDetail;
use App\Models\VehicleRegistrationDetail;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Ordre strict : chaque seeder dépend de ceux qui le précèdent.
     *
     * Garde idempotente : si des marques existent déjà (re-déploiement),
     * on ne re-seed que les tables de référence (upsert sûr) et on skip
     * les factories qui créeraient des doublons.
     */
    public function run(): void
    {
        // On utilise Vehicle (données métier) comme indicateur — pas Brand (référentiel),
        // car les marques peuvent être seédées sans que les véhicules/pièces/clients le soient.
        $alreadySeeded = \App\Models\Vehicle::count() > 0;

        // ─── 1. Tables de référence ────────────────────────────────────
        $this->call([
            DrivetrainsSeeder::class,
            EngineTypesSeeder::class,
            ColorsSeeder::class,
            CountriesSeeder::class,
            ManufacturersSeeder::class,
            BrandsSeeder::class,
            PartCategoriesSeeder::class,
            FeaturesSeeder::class,
            VehicleModelsSeeder::class,
            TrimsSeeder::class,
        ]);

        // ─── 2. Staff ──────────────────────────────────────────────────
        // firstOrCreate → toujours sûr à re-exécuter
        $this->seedStaff();

        if ($alreadySeeded) {
            $this->command->info('Base déjà peuplée — tables de référence mises à jour, données métier inchangées.');
            return;
        }

        // ─── 3. Parc (80 véhicules) ────────────────────────────────────
        $this->seedVehicles();

        // Équipements des véhicules : dépend de FeaturesSeeder (étape 1) et du
        // parc ci-dessus. C'est cette dépendance qui interdisait de le faire
        // depuis une migration, celles-ci s'exécutant avant tout seeder.
        $this->call(VehicleFeaturesSeeder::class);

        // ─── 4. Clients (50) ───────────────────────────────────────────
        Customer::factory()->count(45)->create();
        Customer::factory()->individual()->count(3)->create();
        Customer::factory()->company()->count(2)->create();

        // ─── 5. Pièces détachées (200) ─────────────────────────────────
        Part::factory()->inStock()->count(160)->create();
        Part::factory()->outOfStock()->count(20)->create();
        Part::factory()->oem()->count(20)->create();

        // ─── 6. Accessoires (80) ───────────────────────────────────────
        Accessory::factory()->inStock()->count(70)->create();
        Accessory::factory()->count(10)->create();

        // ─── 7. Transactions ───────────────────────────────────────────
        Sale::factory()->paid()->count(30)->create();
        Sale::factory()->count(10)->create();

        Rental::factory()->returned()->count(25)->create();
        Rental::factory()->ongoing()->count(8)->create();
        Rental::factory()->overdue()->count(3)->create();
        Rental::factory()->count(4)->create();

        $this->command->info('Base peuplée avec succès (référentiels + données métier).');
    }

    // ── Utilisateurs staff ──────────────────────────────────────────────────

    private function seedStaff(): void
    {
        $staff = [
            ['name' => 'Admin Système',    'email' => 'admin@autoparc.bf',      'role' => UserRole::Admin->value],
            ['name' => 'Marie Konaté',     'email' => 'manager@autoparc.bf',    'role' => UserRole::Manager->value],
            ['name' => 'Seydou Traoré',    'email' => 'commercial1@autoparc.bf','role' => UserRole::Sales->value],
            ['name' => 'Issa Compaoré',    'email' => 'commercial2@autoparc.bf','role' => UserRole::Sales->value],
            ['name' => 'Boubacar Diallo',  'email' => 'mecano@autoparc.bf',     'role' => UserRole::Mechanic->value],
            ['name' => 'Adama Ouédraogo',  'email' => 'magasin@autoparc.bf',    'role' => UserRole::Warehouse->value],
            ['name' => 'Test Viewer',      'email' => 'viewer@autoparc.bf',     'role' => UserRole::Viewer->value],
        ];

        foreach ($staff as $data) {
            User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name'      => $data['name'],
                    'password'  => Hash::make('password'),
                    'role'      => $data['role'],
                    'is_active' => true,
                ]
            );
        }
    }

    // ── Véhicules + détails ─────────────────────────────────────────────────

    private function seedVehicles(): void
    {
        // 50 non immatriculés (stock import)
        $imported = Vehicle::factory()->imported()->count(50)->create();

        $originIds = Country::whereIn('iso2', ['JP', 'KR', 'DE', 'FR', 'NL', 'BE'])
            ->pluck('id')->toArray();
        // JP pondéré plus fort
        $jpId = Country::where('iso2', 'JP')->value('id');
        if ($jpId) {
            array_push($originIds, $jpId, $jpId);
        }

        foreach ($imported as $vehicle) {
            VehicleImportDetail::create([
                'vehicle_id'             => $vehicle->id,
                'origin_country_id'      => fake()->randomElement($originIds),
                'purchase_country_id'    => null,
                'supplier_name'          => fake()->randomElement([
                    'USS Tokyo', 'JBA Osaka', 'AUCNET', 'TAA Chubu',
                    'Auto Africa BV', 'CarTrade Germany', null,
                ]),
                'auction_lot_no'         => fake()->optional(0.7)->regexify('[A-Z]{2}[0-9]{6}'),
                'port_of_loading'        => fake()->randomElement(['Yokohama', 'Nagoya', 'Hamburg', 'Anvers', 'Marseille', null]),
                'port_of_entry'          => fake()->randomElement(['Lomé', 'Cotonou', 'Abidjan', 'Tema']),
                'bill_of_lading_no'      => fake()->optional(0.8)->regexify('[A-Z]{4}[0-9]{10}'),
                'container_no'           => fake()->optional(0.8)->regexify('[A-Z]{4}[0-9]{7}'),
                'shipping_date'          => fake()->optional(0.8)->dateTimeBetween('-8 months', '-1 month')?->format('Y-m-d'),
                'arrival_date'           => fake()->optional(0.7)->dateTimeBetween('-6 months', 'now')?->format('Y-m-d'),
                'customs_cleared'        => fake()->boolean(70),
                'customs_declaration_no' => fake()->optional(0.6)->regexify('[A-Z]{2}[0-9]{8}'),
                'customs_duty_amount'    => fake()->optional(0.6)->numberBetween(200_000, 2_000_000),
                'freight_cost'           => fake()->optional(0.7)->numberBetween(500_000, 2_500_000),
                'steering_side'          => fake()->randomElement(['left', 'left', 'right']),
                'odometer_at_import_km'  => fake()->numberBetween(20_000, 180_000),
                'foreign_plate'          => null,
                'notes'                  => null,
            ]);
        }

        // 30 immatriculés (occasion locaux)
        $registered = Vehicle::factory()->registered()->count(30)->create();

        $regIds = Country::whereIn('iso2', ['BF', 'BF', 'BF', 'CI', 'SN'])
            ->pluck('id')->toArray();

        foreach ($registered as $vehicle) {
            VehicleRegistrationDetail::create([
                'vehicle_id'                  => $vehicle->id,
                'plate_number'                => fake()->regexify('[0-9]{2}-[A-Z]{2}-[0-9]{4}'),
                'registration_country_id'     => fake()->randomElement($regIds),
                'registration_certificate_no' => fake()->optional(0.8)->regexify('[A-Z]{2}[0-9]{8}'),
                'first_registration_date'     => fake()->dateTimeBetween('-15 years', '-1 year')?->format('Y-m-d'),
                'last_transfer_date'          => fake()->optional(0.5)->dateTimeBetween('-3 years', 'now')?->format('Y-m-d'),
                'mileage_km'                  => fake()->numberBetween(30_000, 250_000),
                'previous_owners_count'       => fake()->numberBetween(1, 4),
                'technical_inspection_expiry' => fake()->optional(0.7)->dateTimeBetween('now', '+2 years')?->format('Y-m-d'),
                'insurance_expiry'            => fake()->optional(0.8)->dateTimeBetween('now', '+1 year')?->format('Y-m-d'),
                'insurance_company'           => fake()->randomElement(['SONAR Burkina', 'NSIA', 'UAB', 'Allianz Burkina', null]),
                'service_book_available'      => fake()->boolean(30),
                'last_service_date'           => fake()->optional(0.5)->dateTimeBetween('-2 years', 'now')?->format('Y-m-d'),
                'last_service_mileage_km'     => fake()->optional(0.4)->numberBetween(10_000, 220_000),
                'has_accident_history'        => fake()->boolean(20),
                'notes'                       => null,
            ]);
        }
    }
}
