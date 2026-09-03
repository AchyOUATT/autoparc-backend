<?php

namespace Database\Seeders;

use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Models\Color;
use App\Models\Country;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Trim;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleImportDetail;
use App\Models\VehicleModel;
use App\Models\VehicleRegistrationDetail;
use Illuminate\Database\Seeder;

class VehicleSeeder extends Seeder
{
    public function run(): void
    {
        $models = VehicleModel::with('brand')->get();
        $engineTypes = EngineType::all();
        $drivetrains = Drivetrain::all();
        $colors = Color::all();
        $creator = User::where('role', 'manager')->first() ?? User::first();
        $burkina = Country::where('iso2', 'BF')->first();
        $japan = Country::where('iso2', 'JP')->first();

        $unregisteredCase = collect(RegistrationStatus::cases())
            ->first(fn ($c) => str_contains(strtolower($c->name), 'unregist'))
            ?? RegistrationStatus::cases()[0];
        $registeredCase = collect(RegistrationStatus::cases())
            ->first(fn ($c) => $c !== $unregisteredCase)
            ?? RegistrationStatus::cases()[array_key_last(RegistrationStatus::cases())];

        foreach (range(1, 16) as $i) {
            $model = $models->random();
            $trim = Trim::where('vehicle_model_id', $model->id)->inRandomOrder()->first();
            $isImport = $i % 2 === 0;

            $vehicle = Vehicle::create([
                'reference' => 'VH-'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
                'vin' => strtoupper(bin2hex(random_bytes(8))).substr(md5($i), 0, 1),
                'registration_status' => $isImport ? $unregisteredCase->value : $registeredCase->value,
                'brand_id' => $model->brand_id,
                'vehicle_model_id' => $model->id,
                'trim_id' => $trim?->id,
                'engine_type_id' => $engineTypes->random()->id,
                'drivetrain_id' => $drivetrains->random()->id,
                'color_id' => $colors->random()->id,
                'manufacturing_year' => random_int(2015, 2024),
                'transmission' => collect(Transmission::cases())->random()->value,
                'gear_count' => collect([5, 6])->random(),
                'engine_displacement_cc' => collect([1200, 1600, 2000, 2400])->random(),
                'power_hp' => random_int(90, 250),
                'seats' => collect([4, 5, 7])->random(),
                'doors' => collect([3, 5])->random(),
                'condition' => collect(VehicleCondition::cases())->random()->value,
                'status' => VehicleStatus::cases()[0]->value, // ex: in_stock — 1er cas déclaré
                'availability' => collect(VehicleAvailability::cases())->random()->value,
                'purchase_price' => random_int(3000000, 8000000),
                'sale_price' => random_int(4000000, 12000000),
                'rental_daily_rate' => random_int(15000, 45000),
                'rental_weekly_rate' => random_int(90000, 250000),
                'rental_deposit' => random_int(100000, 300000),
                'currency' => 'XOF',
                'price_negotiable' => true,
                'site' => 'Parc Ouaga 2000',
                'description' => "{$model->brand->name} {$model->name} en bon état, entretien suivi.",
                'created_by' => $creator?->id,
                'published_at' => now(),
            ]);

            if ($isImport) {
                VehicleImportDetail::create([
                    'vehicle_id' => $vehicle->id,
                    'origin_country_id' => $japan->id,
                    'purchase_country_id' => $japan->id,
                    'supplier_name' => 'Auction House Japan Co.',
                    'auction_lot_no' => 'LOT-'.random_int(10000, 99999),
                    'port_of_loading' => 'Yokohama',
                    'port_of_entry' => 'Abidjan',
                    'shipping_date' => now()->subMonths(random_int(1, 4)),
                    'arrival_date' => now()->subWeeks(random_int(1, 6)),
                    'customs_cleared' => true,
                    'steering_side' => 'right',
                    'odometer_at_import_km' => random_int(30000, 120000),
                ]);
            } else {
                VehicleRegistrationDetail::create([
                    'vehicle_id' => $vehicle->id,
                    'plate_number' => strtoupper(substr(md5((string) $vehicle->id), 0, 2)).' '
                        .random_int(1000, 9999).' BF',
                    'registration_country_id' => $burkina->id,
                    'first_registration_date' => now()->subYears(random_int(1, 6)),
                    'mileage_km' => random_int(20000, 150000),
                    'previous_owners_count' => random_int(1, 3),
                    'service_book_available' => (bool) random_int(0, 1),
                    'has_accident_history' => false,
                ]);
            }
        }
    }
}
