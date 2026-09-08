<?php

namespace App\Http\Controllers\Api;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleRequest;
use App\Http\Requests\UpdateVehicleRequest;
use App\Http\Resources\VehicleResource;
use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\CustomerNeed;
use App\Models\Vehicle;
use App\Services\FcmService;
use App\Services\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VehicleController extends Controller
{
    public function __construct(
        protected ReferenceGenerator $references,
        protected FcmService         $fcm,
    ) {}

    /** Catalogue filtre du parc. */
    public function index(Request $request)
    {
        $vehicles = Vehicle::query()
            ->with([
                'brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color',
                'location',
                'importDetail.originCountry', 'registrationDetail.registrationCountry', 'media',
            ])
            ->withCount('faults')
            ->search($request->string('q')->toString() ?: null)
            ->when($request->filled('registration_status'), fn ($q) => $q->where('registration_status', $request->registration_status))
            ->when($request->filled('brand_id'), fn ($q) => $q->where('brand_id', $request->brand_id))
            ->when($request->filled('vehicle_model_id'), fn ($q) => $q->where('vehicle_model_id', $request->vehicle_model_id))
            ->when($request->filled('trim_id'), fn ($q) => $q->where('trim_id', $request->trim_id))
            ->when($request->filled('engine_type_id'), fn ($q) => $q->where('engine_type_id', $request->engine_type_id))
            ->when($request->filled('drivetrain_id'), fn ($q) => $q->where('drivetrain_id', $request->drivetrain_id))
            ->when($request->filled('color_id'), fn ($q) => $q->where('color_id', $request->color_id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('availability'), fn ($q) => $q->where('availability', $request->availability))
            ->when($request->filled('condition'), fn ($q) => $q->where('condition', $request->condition))
            ->when($request->filled('year_min'), fn ($q) => $q->where('manufacturing_year', '>=', $request->year_min))
            ->when($request->filled('year_max'), fn ($q) => $q->where('manufacturing_year', '<=', $request->year_max))
            ->when($request->filled('price_min'), fn ($q) => $q->where('sale_price', '>=', $request->price_min))
            ->when($request->filled('price_max'), fn ($q) => $q->where('sale_price', '<=', $request->price_max))
            ->when($request->filled('consumption_max'), fn ($q) => $q->where('consumption_combined', '<=', $request->consumption_max))
            // Filtres specifiques aux vehicules importes
            ->when($request->filled('origin_country_id'), fn ($q) => $q->fromCountry((int) $request->origin_country_id))
            ->when($request->boolean('customs_cleared'), fn ($q) => $q->whereHas('importDetail', fn ($d) => $d->where('customs_cleared', true)))
            // Filtres specifiques aux vehicules immatricules
            ->when($request->filled('mileage_max'), fn ($q) => $q->whereHas('registrationDetail', fn ($d) => $d->where('mileage_km', '<=', $request->mileage_max)))
            ->when($request->boolean('without_faults'), fn ($q) => $q->withoutOpenFaults())
            ->when($request->filled('fault_category'), fn ($q) => $q->withFaultCategory($request->fault_category))
            ->when($request->filled('vehicle_type'), fn ($q) => $q->where('vehicle_type', $request->vehicle_type))
            ->when($request->filled('body_style'),   fn ($q) => $q->where('body_style',   $request->body_style))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->location_id))
            ->when($request->filled('city'), fn ($q) => $q->whereHas('location', fn ($l) => $l->where('city', $request->city)))
            ->when($request->boolean('available_for_rent'), fn ($q) => $q->forRent()->inStock())
            // Mises en avant : `deal=1` renvoie toutes les promotions,
            // `deal_type=flash_sale` un type precis.
            ->when($request->boolean('deal'), fn ($q) => $q->whereNotNull('deal_type'))
            ->when($request->filled('deal_type'), fn ($q) => $q->where('deal_type', $request->deal_type))
            ->orderBy(
                $request->input('sort_by', 'created_at'),
                $request->input('sort_dir', 'desc')
            )
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return VehicleResource::collection($vehicles);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();

        $vehicle = DB::transaction(function () use ($data) {
            // Calcul automatique du statut : 'registered' si une plaque est fournie.
            $plateNumber = $data['plate_number'] ?? null;
            $data['registration_status'] = $plateNumber
                ? RegistrationStatus::Registered->value
                : RegistrationStatus::Unregistered->value;

            // Valeurs par défaut pour les champs commerciaux optionnels.
            $data['condition']    ??= 'used';
            $data['status']       ??= 'in_stock';
            $data['availability'] ??= 'sale';

            $vehicle = Vehicle::create(array_merge(
                collect($data)->except(['import', 'registration', 'faults', 'features', 'plate_number'])->all(),
                [
                    'reference'  => $data['reference'] ?? $this->references->next('VEH'),
                    'created_by' => auth()->id(),
                ]
            ));

            // Si une plaque est fournie, créer un enregistrement minimal.
            if ($plateNumber) {
                $vehicle->registrationDetail()->create([
                    'vehicle_id'   => $vehicle->id,
                    'plate_number' => $plateNumber,
                ]);
            }

            $this->syncTypeSpecificDetails($vehicle, $data);

            if (! empty($data['features'])) {
                $vehicle->features()->sync($data['features']);
            }

            foreach ($data['faults'] ?? [] as $fault) {
                $vehicle->faults()->create($fault);
            }

            return $vehicle;
        });

        return (new VehicleResource($this->loadRelations($vehicle)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Trouve les besoins clients de type "vehicle" en attente et notifie
     * chaque client (qui a un firebase_uid) qu'un nouveau véhicule est disponible.
     */
    /**
     * Notifie uniquement les clients dont le besoin correspond au véhicule publié.
     *
     * Règle de matching (tous les critères renseignés doivent correspondre ;
     * un critère null = pas de contrainte sur ce point) :
     *   A. Budget   : budget_max IS NULL      OU  sale_price <= budget_max
     *   B. Marque   : brand_id IS NULL        OU  brand_id = vehicle.brand_id
     *   C. Modèle   : vehicle_model_id IS NULL OU vehicle_model_id = vehicle.vehicle_model_id
     *   D. Type     : vehicle_type IS NULL    OU  vehicle_type = vehicle.vehicle_type
     *   E. Carross. : body_style IS NULL      OU  body_style = vehicle.body_style
     *   F. Année    : year_min IS NULL        OU  year_min <= vehicle.manufacturing_year
     *              ET year_max IS NULL        OU  year_max >= vehicle.manufacturing_year
     */
    private function notifyVehicleMatch(Vehicle $vehicle): void
    {
        $pendingNeeds = CustomerNeed::where('type', 'vehicle')
            ->where('status', 'pending')
            ->whereNotNull('firebase_uid')
            // A. Budget
            ->where(fn ($q) => $q
                ->whereNull('budget_max')
                ->orWhere('budget_max', '>=', $vehicle->sale_price ?? 0)
            )
            // B. Marque
            ->where(fn ($q) => $q
                ->whereNull('brand_id')
                ->orWhere('brand_id', $vehicle->brand_id)
            )
            // C. Modèle exact
            ->where(fn ($q) => $q
                ->whereNull('vehicle_model_id')
                ->orWhere('vehicle_model_id', $vehicle->vehicle_model_id)
            )
            // D. Type de véhicule
            ->where(fn ($q) => $q
                ->whereNull('vehicle_type')
                ->orWhere('vehicle_type', $vehicle->vehicle_type)
            )
            // E. Carrosserie
            ->where(fn ($q) => $q
                ->whereNull('body_style')
                ->orWhere('body_style', $vehicle->body_style)
            )
            // F. Année minimale
            ->where(fn ($q) => $q
                ->whereNull('year_min')
                ->orWhere('year_min', '<=', $vehicle->manufacturing_year)
            )
            // F. Année maximale
            ->where(fn ($q) => $q
                ->whereNull('year_max')
                ->orWhere('year_max', '>=', $vehicle->manufacturing_year)
            )
            ->get();

        foreach ($pendingNeeds as $need) {
            $title   = 'Un véhicule correspond à votre recherche';
            $body    = "{$vehicle->brand->name} {$vehicle->vehicleModel->name} {$vehicle->manufacturing_year} vient d'être ajouté au catalogue.";
            $payload = [
                'type'       => 'vehicle_match',
                'vehicle_id' => (string) $vehicle->id,
                'need_id'    => (string) $need->id,
            ];

            AppNotification::notifyClient($need->firebase_uid, 'vehicle_match', $title, $body, $payload);

            $tokens = ClientFcmToken::tokensForUid($need->firebase_uid);
            $this->fcm->sendToTokens($tokens, $title, $body, $payload);
        }
    }

    public function show(Vehicle $vehicle): VehicleResource
    {
        return new VehicleResource($this->loadRelations($vehicle));
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($vehicle, $data) {
            $vehicle->update(collect($data)->except(['import', 'registration', 'faults', 'features'])->all());

            $this->syncTypeSpecificDetails($vehicle, $data);

            if (array_key_exists('features', $data)) {
                $vehicle->features()->sync($data['features']);
            }
        });

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $vehicle->delete();

        return response()->json(['message' => 'Vehicule archive.']);
    }

    /**
     * Remplace la liste des features (équipements) d'un véhicule.
     * Endpoint partiel — ne touche pas aux autres champs.
     * PUT /vehicles/{vehicle}/features
     */
    public function syncFeatures(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'features'   => ['present', 'array'],
            'features.*' => ['integer', 'exists:features,id'],
        ]);

        $vehicle->features()->sync($data['features']);

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    /**
     * Publie un véhicule (le rend visible dans le catalogue public)
     * et notifie les clients ayant un besoin en attente.
     * POST /staff/vehicles/{vehicle}/publish
     */
    public function publish(Vehicle $vehicle): VehicleResource
    {
        if (! $vehicle->published_at) {
            $vehicle->update(['published_at' => now()]);
            $vehicle->loadMissing(['brand', 'vehicleModel']);
            $this->notifyVehicleMatch($vehicle);
        }

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    /**
     * Dépublie un véhicule (le retire du catalogue public).
     * POST /staff/vehicles/{vehicle}/unpublish
     */
    public function unpublish(Vehicle $vehicle): VehicleResource
    {
        $vehicle->update(['published_at' => null]);

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    /**
     * Passage d'un vehicule importe au statut immatricule
     * (apres dedouanement et immatriculation locale).
     */
    public function register(Request $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validate([
            'plate_number'            => ['required', 'string', 'max:30'],
            'registration_country_id' => ['required', 'exists:countries,id'],
            'registration_certificate_no' => ['nullable', 'string', 'max:60'],
            'first_registration_date' => ['nullable', 'date'],
            'mileage_km'              => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($vehicle, $data) {
            $vehicle->registrationDetail()->updateOrCreate(['vehicle_id' => $vehicle->id], $data);
            $vehicle->update(['registration_status' => RegistrationStatus::Registered]);
        });

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    /** Ecrit le bloc import ou le bloc immatriculation selon le type de vehicule. */
    protected function syncTypeSpecificDetails(Vehicle $vehicle, array $data): void
    {
        if ($vehicle->registration_status === RegistrationStatus::Unregistered && ! empty($data['import'])) {
            $vehicle->importDetail()->updateOrCreate(['vehicle_id' => $vehicle->id], $data['import']);
            $vehicle->registrationDetail()?->delete();
        }

        if ($vehicle->registration_status === RegistrationStatus::Registered && ! empty($data['registration'])) {
            $vehicle->registrationDetail()->updateOrCreate(['vehicle_id' => $vehicle->id], $data['registration']);
        }
    }

    protected function loadRelations(Vehicle $vehicle): Vehicle
    {
        return $vehicle->load([
            'brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color',
            'features', 'media', 'faults.parts',
            'importDetail.originCountry', 'importDetail.purchaseCountry',
            'registrationDetail.registrationCountry',
            'location', 'partner',
        ]);
    }
}
