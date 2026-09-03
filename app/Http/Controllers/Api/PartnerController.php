<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StorePartnerRequest;
use App\Http\Resources\PartnerResource;
use App\Models\Accessory;
use App\Models\Partner;
use App\Models\Part;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerController extends Controller
{
    /* ------------------------------------------------------------------ */
    /* Annuaire des partenaires (staff)                                     */
    /* ------------------------------------------------------------------ */

    /** GET /api/partners */
    public function index(Request $request)
    {
        $partners = Partner::query()
            ->search($request->string('q')->toString() ?: null)
            ->when(! $request->boolean('all'), fn ($q) => $q->active())
            ->withCount(['parts', 'accessories'])
            ->orderBy('company_name')
            ->paginate($request->integer('per_page', 50))
            ->withQueryString();

        return PartnerResource::collection($partners);
    }

    /** POST /api/partners */
    public function store(StorePartnerRequest $request): JsonResponse
    {
        $partner = Partner::create($request->validated());

        return (new PartnerResource($partner))
            ->response()
            ->setStatusCode(201);
    }

    /** GET /api/partners/{partner} */
    public function show(Partner $partner): PartnerResource
    {
        return new PartnerResource($partner->loadCount(['parts', 'accessories']));
    }

    /** PUT /api/partners/{partner} */
    public function update(StorePartnerRequest $request, Partner $partner): PartnerResource
    {
        $partner->update($request->validated());

        return new PartnerResource($partner->fresh());
    }

    /** DELETE /api/partners/{partner} */
    public function destroy(Partner $partner): JsonResponse
    {
        $partner->delete();

        return response()->json(['message' => 'Partenaire supprimé.']);
    }

    /* ------------------------------------------------------------------ */
    /* Associations partenaire ↔ pièce                                     */
    /* ------------------------------------------------------------------ */

    /** GET /api/parts/{part}/partners */
    public function indexForPart(Part $part)
    {
        return PartnerResource::collection($part->partners);
    }

    /** POST /api/parts/{part}/partners  body: {partner_id, role?, notes?} */
    public function attachToPart(Request $request, Part $part): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:partners,id'],
            'role'       => ['nullable', 'string', 'max:60'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $part->partners()->syncWithoutDetaching([
            $data['partner_id'] => [
                'role'  => $data['role']  ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        ]);

        return response()->json(['message' => 'Partenaire associé.']);
    }

    /** DELETE /api/parts/{part}/partners/{partner} */
    public function detachFromPart(Part $part, Partner $partner): JsonResponse
    {
        $part->partners()->detach($partner->id);

        return response()->json(['message' => 'Association supprimée.']);
    }

    /* ------------------------------------------------------------------ */
    /* Associations partenaire ↔ accessoire                                */
    /* ------------------------------------------------------------------ */

    /** GET /api/accessories/{accessory}/partners */
    public function indexForAccessory(Accessory $accessory)
    {
        return PartnerResource::collection($accessory->partners);
    }

    /** POST /api/accessories/{accessory}/partners */
    public function attachToAccessory(Request $request, Accessory $accessory): JsonResponse
    {
        $data = $request->validate([
            'partner_id' => ['required', 'integer', 'exists:partners,id'],
            'role'       => ['nullable', 'string', 'max:60'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        $accessory->partners()->syncWithoutDetaching([
            $data['partner_id'] => [
                'role'  => $data['role']  ?? null,
                'notes' => $data['notes'] ?? null,
            ],
        ]);

        return response()->json(['message' => 'Partenaire associé.']);
    }

    /** DELETE /api/accessories/{accessory}/partners/{partner} */
    public function detachFromAccessory(Accessory $accessory, Partner $partner): JsonResponse
    {
        $accessory->partners()->detach($partner->id);

        return response()->json(['message' => 'Association supprimée.']);
    }
}
