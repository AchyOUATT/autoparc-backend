<?php

namespace App\Http\Controllers\Api;

use App\Enums\CheckReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVehicleCheckRequest;
use App\Http\Resources\VehicleCheckResource;
use App\Models\OwnedVehicle;
use App\Models\VehicleCheck;
use App\Services\ControleAvantVoyage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Le controle avant voyage d'un vehicule du garage.
 *
 * Un passage ne se modifie pas : il n'y a donc ni update ni destroy. Refaire le
 * controle est la seule correction possible d'un constat errone, et c'est aussi
 * la seule qui laisse une trace honnete.
 */
class VehicleCheckController extends Controller
{
    use AuthorizesRequests;

    /**
     * Les relations dont la composition depend.
     *
     * vehicleModel porte la carrosserie, trim.defaultEngineType et motorisation
     * servent a reconnaitre un diesel quand le type de moteur n'a pas ete saisi
     * directement. Sans ce chargement, chaque gabarit conditionnel declencherait
     * sa propre requete.
     */
    private array $relations = ['vehicleModel', 'engineType', 'trim.defaultEngineType', 'motorisation'];

    public function __construct(private readonly ControleAvantVoyage $controle) {}

    /** La liste des points a verifier, composee pour ce vehicule et ce trajet. */
    public function template(Request $request, OwnedVehicle $ownedVehicle): JsonResponse
    {
        $this->authorize('view', $ownedVehicle);

        $valide = $request->validate([
            'trip_distance_km' => ['nullable', 'integer', 'min:1', 'max:5000'],
            'reason'           => ['nullable', Rule::enum(CheckReason::class)],
        ]);

        $distance = isset($valide['trip_distance_km']) ? (int) $valide['trip_distance_km'] : null;
        $motif = CheckReason::tryFrom($valide['reason'] ?? '') ?? CheckReason::Trip;

        $ownedVehicle->load($this->relations);

        return response()->json([
            'data' => [
                'reason'           => $motif->value,
                'title'            => $this->controle->titre($motif),
                'trip_distance_km' => $distance,
                'mileage_km'       => $ownedVehicle->mileage_km,

                // Quels controles ont un sens aujourd'hui, et sous quel nom.
                // Le calendrier des saisons reste cote serveur : decoupe des
                // deux cotes, il finirait par ne plus tomber au meme mois.
                'available_reasons' => $this->controle->typesDisponibles(),

                'items' => $this->controle->liste($ownedVehicle, $distance, null, $motif),
            ],
        ]);
    }

    /**
     * Enregistre un passage.
     *
     * 'update' et non 'view' : le controle reporte le kilometrage releve sur le
     * vehicule, donc il ecrit.
     */
    public function store(StoreVehicleCheckRequest $request, OwnedVehicle $ownedVehicle): JsonResponse
    {
        $this->authorize('update', $ownedVehicle);

        $donnees = $request->validated();
        $reference = $donnees['client_reference'] ?? null;

        // Un envoi rejoue apres une coupure retrouve son passage au lieu d'en
        // creer un second : la reponse est alors 200, pas 201, et l'application
        // sait qu'elle n'a rien cree.
        $dejaEnregistre = $reference !== null && VehicleCheck::query()
            ->where('owned_vehicle_id', $ownedVehicle->id)
            ->where('client_reference', $reference)
            ->exists();

        $controle = $this->controle->enregistrer(
            vehicule: $ownedVehicle->load($this->relations),
            reponses: $donnees['answers'],
            distanceKm: isset($donnees['trip_distance_km']) ? (int) $donnees['trip_distance_km'] : null,
            kilometrage: isset($donnees['mileage_km']) ? (int) $donnees['mileage_km'] : null,
            note: $donnees['note'] ?? null,
            referenceClient: $reference,
            effectueLe: isset($donnees['performed_at']) ? CarbonImmutable::parse($donnees['performed_at']) : null,
            motif: CheckReason::tryFrom($donnees['reason'] ?? '') ?? CheckReason::Trip,
        );

        return (new VehicleCheckResource($controle))
            ->avecPointsAReprendre($this->controle->pointsAReprendre($controle))
            ->response()
            ->setStatusCode($dejaEnregistre ? 200 : 201);
    }

    /** L'historique des controles d'un vehicule, du plus recent au plus ancien. */
    public function index(Request $request, OwnedVehicle $ownedVehicle)
    {
        $this->authorize('view', $ownedVehicle);

        $controles = $ownedVehicle->checks()
            ->orderByDesc('performed_at')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return VehicleCheckResource::collection($controles);
    }

    /** Une fiche de controle, avec ses reponses et ce qui reste a reprendre. */
    public function show(VehicleCheck $vehicleCheck): JsonResponse
    {
        $this->authorize('view', $vehicleCheck);

        return (new VehicleCheckResource($vehicleCheck->load('answers')))
            ->avecPointsAReprendre($this->controle->pointsAReprendre($vehicleCheck))
            ->response();
    }
}
