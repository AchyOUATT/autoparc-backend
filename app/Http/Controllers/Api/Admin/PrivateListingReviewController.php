<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PrivateVehicleListingResource;
use App\Models\PrivateVehicleListing;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrivateListingReviewController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['sometimes', 'in:pending,published,rejected']]);

        return PrivateVehicleListingResource::collection(PrivateVehicleListing::with('photos')
            ->where('status', $data['status'] ?? 'pending')->oldest('submitted_at')->paginate(20));
    }

    public function approve(Request $request, PrivateVehicleListing $listing)
    {
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $listing, $data) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->revision === (int) $data['revision'], 409, 'L’annonce a changé. Actualisez-la avant de décider.');
            abort_unless($locked->status === 'pending', 422, 'Cette annonce n’est plus en attente. Actualisez la liste.');
            abort_unless($locked->phone_verified_at && $locked->verified_phone && $locked->photos()->exists()
                && $locked->seller?->is_active && $locked->seller->isClient(), 422, 'Cette annonce ne remplit plus les conditions de publication.');
            $locked->update(['status' => 'published', 'published_at' => now(), 'reviewed_by' => $request->user()->id, 'review_reason' => null, 'revision' => $locked->revision + 1]);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }

    public function reject(Request $request, PrivateVehicleListing $listing)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000'], 'revision' => ['required', 'integer', 'min:1']]);
        DB::transaction(function () use ($request, $listing, $data) {
            $locked = PrivateVehicleListing::whereKey($listing->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->revision === (int) $data['revision'], 409, 'L’annonce a changé. Actualisez-la avant de décider.');
            abort_unless(in_array($locked->status, ['pending', 'published'], true), 422, 'Cette annonce n’est plus à examiner.');
            $locked->update([
                'status' => 'rejected', 'published_at' => null,
                'reviewed_by' => $request->user()->id, 'review_reason' => $data['reason'],
                'revision' => $locked->revision + 1,
            ]);
        });

        return new PrivateVehicleListingResource($listing->refresh()->load('photos'));
    }
}
