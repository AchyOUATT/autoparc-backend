<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\VinDecodeService;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/catalog/vin-decode/{vin}
 *
 * Décode un VIN et retourne les correspondances en base prêtes à pré-remplir
 * le formulaire Flutter "Ajouter au garage" (OwnedVehicle) et à alimenter
 * la recherche de pièces compatibles (CompatibilityService::partsForCriteria).
 *
 * Accès public (pas d'authentification requise) : un visiteur peut scanner
 * son VIN avant même de créer un compte.
 *
 * Exemple de réponse (confidence=high) :
 * {
 *   "vin": "JTDBE30K453012345",
 *   "valid": true,
 *   "year_candidates": [2005],
 *   "engine_code": "5",
 *   "nhtsa": {
 *     "make": "TOYOTA", "model": "Corolla", "year": 2005,
 *     "body_class": "Sedan", "fuel_type": "Gasoline",
 *     "drive_type": "FWD/Front-Wheel Drive",
 *     "displacement_cc": 1600, "cylinders": 4,
 *     "transmission_style": "Automatic", ...
 *   },
 *   "brand":        { "id": 1,  "name": "Toyota",  "slug": "toyota" },
 *   "vehicle_model":{ "id": 12, "name": "Corolla", "slug": "corolla", ... },
 *   "engine_type":  { "id": 2,  "code": "gasoline", "label": "Essence", ... },
 *   "drivetrain":   { "id": 1,  "code": "FWD",      "label": "Traction avant" },
 *   "confidence": "high",
 *   "warnings": []
 * }
 */
class VinDecodeController extends Controller
{
    public function __construct(private readonly VinDecodeService $vinDecoder) {}

    public function decode(string $vin): JsonResponse
    {
        $result = $this->vinDecoder->decode($vin);

        // 422 si le VIN est structurellement invalide, 200 sinon
        // (un résultat partiel — NHTSA KO, marque inconnue — reste un 200 avec warnings)
        $status = $result['valid'] ? 200 : 422;

        return response()->json($result, $status);
    }
}
