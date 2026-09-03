<?php

namespace App\Http\Controllers\Api;

use App\Enums\RegistrationStatus;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Part;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\Vehicle;
use App\Models\VehicleFault;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'parc' => [
                'total'          => Vehicle::count(),
                'en_stock'       => Vehicle::where('status', VehicleStatus::InStock)->count(),
                'non_immatricules' => Vehicle::where('registration_status', RegistrationStatus::Unregistered)->count(),
                'immatricules'   => Vehicle::where('registration_status', RegistrationStatus::Registered)->count(),
                'en_location'    => Vehicle::where('status', VehicleStatus::Rented)->count(),
                'par_marque'     => Vehicle::selectRaw('brand_id, count(*) as total')
                    ->with('brand:id,name')->groupBy('brand_id')->get()
                    ->map(fn ($r) => ['marque' => $r->brand?->name, 'total' => $r->total]),
            ],
            'pannes' => [
                'ouvertes'  => VehicleFault::open()->count(),
                'critiques' => VehicleFault::open()->critical()->count(),
                'cout_estime_total' => (float) VehicleFault::open()->sum('estimated_repair_cost'),
                'par_categorie' => VehicleFault::open()
                    ->selectRaw('category, count(*) as total')->groupBy('category')->pluck('total', 'category'),
            ],
            'pieces' => [
                'references'     => Part::active()->count(),
                'rupture'        => Part::where('stock_quantity', 0)->count(),
                'sous_seuil'     => Part::lowStock()->count(),
                'valeur_stock'   => (float) Part::selectRaw('sum(stock_quantity * cost_price) as v')->value('v'),
            ],
            'commercial' => [
                'ventes_mois'       => Sale::whereMonth('sold_at', now()->month)->whereYear('sold_at', now()->year)->count(),
                'ca_ventes_mois'    => (float) Sale::whereMonth('sold_at', now()->month)->whereYear('sold_at', now()->year)->sum('total_amount'),
                'locations_actives' => Rental::active()->count(),
                'locations_en_retard' => Rental::active()->whereNull('actual_return_at')
                    ->where('expected_return_at', '<', now())->count(),
            ],
        ]);
    }
}
