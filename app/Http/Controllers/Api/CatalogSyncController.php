<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Brand;
use App\Models\Color;
use App\Models\Drivetrain;
use App\Models\EngineType;
use App\Models\Feature;
use App\Models\Location;
use App\Models\PartCategory;
use App\Models\Trim;
use App\Models\VehicleModel;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Synchronisation incrementale des tables de reference (marques, modeles,
 * finitions, motorisations...) vers le cache SQLite local de l'app Flutter.
 *
 * Ne renvoie JAMAIS de donnees transactionnelles (prix, stock, statut vehicule) :
 * uniquement ce qui decrit "qu'est-ce qui existe", stable dans le temps.
 */
class CatalogSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $since = $request->filled('since') ? Carbon::parse($request->string('since')) : null;

        // Capture avant l'execution des requetes : sert de curseur pour le prochain appel.
        $serverTime = now();

        return response()->json([
            'server_time' => $serverTime->toIso8601String(),

            'brands' => $this->delta(Brand::query(), $since)
                ->get(['id', 'name', 'slug', 'country_id', 'logo_path', 'is_active', 'updated_at']),

            'vehicle_models' => $this->delta(VehicleModel::query(), $since)
                ->get([
                    'id', 'brand_id', 'name', 'slug', 'generation', 'body_type', 'segment', 'is_active',
                    'default_vehicle_type', 'default_seats', 'default_doors',
                    'default_transmission', 'default_power_hp',
                    'updated_at',
                ]),

            'trims' => $this->delta(Trim::query(), $since)
                ->get(['id', 'vehicle_model_id', 'name', 'code', 'rank', 'default_engine_type_id', 'default_drivetrain_id', 'updated_at']),

            'engine_types' => $this->delta(EngineType::query(), $since)
                ->get(['id', 'code', 'label', 'uses_fuel', 'uses_battery', 'updated_at']),

            'drivetrains' => $this->delta(Drivetrain::query(), $since)
                ->get(['id', 'code', 'label', 'updated_at']),

            'colors' => $this->delta(Color::query(), $since)
                ->get(['id', 'name', 'hex_code', 'finish', 'updated_at']),

            'features' => $this->delta(Feature::query(), $since)
                ->get(['id', 'name', 'category', 'updated_at']),

            'part_categories' => $this->delta(PartCategory::query(), $since)
                ->get(['id', 'parent_id', 'name', 'slug', 'updated_at']),

            'locations' => $this->delta(Location::query()->active(), $since)
                ->get(['id', 'name', 'city', 'address', 'phone', 'updated_at']),
        ]);
    }

    /** Ne renvoie que les lignes modifiees depuis le dernier passage du client. */
    protected function delta(Builder $query, ?Carbon $since): Builder
    {
        if ($since !== null) {
            $query->where('updated_at', '>', $since);
        }

        return $query;
    }
}
