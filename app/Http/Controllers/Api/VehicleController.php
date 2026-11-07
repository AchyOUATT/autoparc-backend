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
use App\Services\FaultDeclarations;
use App\Support\PartnerOwnership;
use App\Support\HeavyVehicleConfiguration;
use App\Support\TriPublic;
use App\Support\VehicleCatalogQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VehicleController extends Controller
{
    public function __construct(
        protected ReferenceGenerator $references,
        protected FcmService         $fcm,
    ) {}

    /** Catalogue filtre du parc. */
    public function index(Request $request)
    {
        $vehicles = VehicleCatalogQuery::filtered($request, publicOnly: $request->is('api/catalog/*'))
            ->with([
                'brand', 'vehicleModel', 'trim', 'engineType', 'drivetrain', 'color',
                'location', 'sellerAccount',
                'importDetail.originCountry', 'registrationDetail.registrationCountry', 'media',
            ])
            ->withCount('faults')
            // Tri borne a une liste blanche : `sort_by` et `sort_dir`
            // passaient bruts dans `orderBy`, ce qui rendait 500 sur un sens
            // invalide ET permettait de classer tout le catalogue par
            // `purchase_price`, colonne absente de toute ressource publique.
            // Voir App\Support\TriPublic.
            ->tap(fn ($q) => TriPublic::appliquer(
                $q, $request, TriPublic::vehicules(), 'created_at', 'desc'
            ))
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return VehicleResource::collection($vehicles);
    }

    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $data = $request->validated();
        if (array_key_exists('heavy_configuration', $data)) {
            $data['heavy_configuration'] = HeavyVehicleConfiguration::normalize($data['heavy_configuration']);
        }
        if ($request->user()?->isPartner()) {
            $data['owner_partner_account_id'] = PartnerOwnership::accountId($request->user());
            $data['partner_published_at'] = null;
        }

        $vehicle = DB::transaction(function () use ($data, $request) {
            // ── LE STATUT ENVOYE PRIME SUR LA PLAQUE ─────────────────────
            //
            // Le statut etait DEDUIT de la plaque, et ce qui arrivait dans
            // `registration_status` etait ecrase sans un mot — alors que la
            // validation acceptait le champ. Un vendeur qui declarait un
            // vehicule immatricule SANS avoir sa plaque sous la main le
            // voyait classe « non immatricule », et sa fiche basculait du
            // cote import : `syncTypeSpecificDetails()` ecrit le bloc import
            // ou le bloc immatriculation SELON CE STATUT, donc le carnet
            // d'immatriculation qu'il venait de remplir etait ignore.
            //
            // La plaque n'est pas la preuve de l'immatriculation, c'est une
            // de ses donnees. On a une plaque quand on l'a ; on sait que le
            // vehicule est immatricule bien avant.
            //
            // `UpdateVehicleRequest` acceptait deja le champ a la
            // modification : seule la creation le refusait, ce qui obligeait
            // a creer la fiche puis a la corriger aussitot.
            //
            // Sans statut explicite, la deduction d'avant s'applique telle
            // quelle : une plaque vaut toujours immatriculation.
            $plateNumber = $data['plate_number'] ?? null;
            $data['registration_status'] ??= $plateNumber
                ? RegistrationStatus::Registered->value
                : RegistrationStatus::Unregistered->value;

            // Valeurs par défaut pour les champs commerciaux optionnels.
            $data['condition']    ??= 'used';
            $data['status']       ??= 'in_stock';
            $data['availability'] ??= 'sale';

            $vehicle = Vehicle::create(array_merge(
                collect($data)->except(['import', 'registration', 'faults', 'fault_declaration_state', 'features', 'plate_number'])->all(),
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

            app(FaultDeclarations::class)->sync($vehicle, $data, $request->user());

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
        if (request()->is('api/catalog/*') || ! request()->user()?->isStaff()) {
            abort_unless(Vehicle::visibleToPublic()->whereKey($vehicle->id)->exists(), 404);
        }

        $vehicle = $this->loadRelations($vehicle);

        // Seulement ici : une requete par vehicule, que la liste ne peut pas
        // se permettre.
        $vehicle->cotesOfficielles = \App\Support\CotesOfficielles::pourVehicule($vehicle);

        return new VehicleResource($vehicle);
    }

    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): VehicleResource
    {
        $data = $request->validated();

        DB::transaction(function () use ($vehicle, $data, $request) {
            $vehicle = Vehicle::whereKey($vehicle->id)->lockForUpdate()->firstOrFail();
            if (array_key_exists('heavy_configuration', $data)) {
                $data['heavy_configuration'] = HeavyVehicleConfiguration::merge($vehicle->heavy_configuration, $data['heavy_configuration']);
                // Recheck against the locked row: another PATCH may have
                // changed the other mass after request validation.
                if (! HeavyVehicleConfiguration::massesAreConsistent($data['heavy_configuration'])) {
                    throw ValidationException::withMessages([
                        'heavy_configuration.ptra_kg' => __('Le PTRA doit être supérieur ou égal au PTAC.'),
                    ]);
                }
            }
            $vehicle->update(collect($data)->except(['import', 'registration', 'faults', 'fault_declaration_state', 'features'])->all());
            app(FaultDeclarations::class)->sync($vehicle, $data, $request->user());

            $this->syncTypeSpecificDetails($vehicle, $data);

            if (array_key_exists('features', $data)) {
                $vehicle->features()->sync($data['features']);
            }
        });

        return new VehicleResource($this->loadRelations($vehicle->refresh()));
    }

    public function destroy(Request $request, Vehicle $vehicle): JsonResponse
    {
        // Plus restreint que la modification : la suppression ne se rattrape
        // pas depuis l'application.
        abort_unless(
            $request->user()?->role?->canDeleteCatalog() ?? false,
            403,
            "Suppression reservee a l'administration."
        );

        $vehicle->delete();

        return response()->json(['message' => __('Vehicule archive.')]);
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
            'location', 'partner', 'sellerAccount',
        ]);
    }
}
