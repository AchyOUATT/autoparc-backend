<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

/** Only the authenticated owner's and moderation controllers use this resource. */
class PrivateVehicleListingManagementResource extends PrivateVehicleListingResource
{
    protected function canSeePrivateData(Request $request): bool
    {
        $user = $request->user();

        return $user !== null && $user->is_active && (
            (int) $user->id === (int) $this->user_id
            || ($user->isStaff() && $user->role->canManageCatalog())
        );
    }
}
