<?php

namespace App\Http\Controllers\Api;

use App\Enums\FaultCategory;
use App\Enums\FaultSeverity;
use App\Enums\PartCondition;
use App\Enums\PartType;
use App\Enums\RegistrationStatus;
use App\Enums\Transmission;
use App\Enums\VehicleAvailability;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Color;
use App\Models\Country;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Feature;
use App\Models\Manufacturer;
use App\Models\PartCategory;
use Illuminate\Http\JsonResponse;

/** Toutes les listes de reference necessaires aux formulaires du front. */
class ReferenceController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'countries' => Country::orderByDesc('is_common_origin')->orderBy('name')
                ->get(['id', 'iso2', 'name', 'is_common_origin']),
            'engine_types'    => EngineType::orderBy('label')->get(['id', 'code', 'label', 'uses_fuel', 'uses_battery']),
            'drivetrains'     => Drivetrain::orderBy('code')->get(['id', 'code', 'label']),
            'colors'          => Color::orderBy('name')->get(['id', 'name', 'hex_code', 'finish']),
            'features'        => Feature::orderBy('category')->orderBy('name')->get(['id', 'name', 'category']),
            'part_categories' => PartCategory::with('children:id,parent_id,name,slug')
                ->whereNull('parent_id')->orderBy('name')->get(['id', 'parent_id', 'name', 'slug']),
            'manufacturers'   => Manufacturer::orderBy('name')->get(['id', 'name', 'is_oem_supplier']),
            'enums' => [
                'registration_status' => $this->enumOptions(RegistrationStatus::class),
                'vehicle_status'      => $this->enumOptions(VehicleStatus::class),
                'availability'        => VehicleAvailability::values(),
                'vehicle_condition'   => VehicleCondition::values(),
                'transmission'        => Transmission::values(),
                'fault_category'      => FaultCategory::values(),
                'fault_severity'      => FaultSeverity::values(),
                'part_type'           => PartType::values(),
                'part_condition'      => PartCondition::values(),
            ],
        ]);
    }

    protected function enumOptions(string $enum): array
    {
        return array_map(
            fn ($case) => ['value' => $case->value, 'label' => $case->label()],
            $enum::cases()
        );
    }
}
