<?php

namespace App\Policies;

use App\Models\OwnedVehicle;
use App\Models\User;

class OwnedVehiclePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, OwnedVehicle $ownedVehicle): bool
    {
        return $user->id === $ownedVehicle->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, OwnedVehicle $ownedVehicle): bool
    {
        return $user->id === $ownedVehicle->user_id;
    }

    public function delete(User $user, OwnedVehicle $ownedVehicle): bool
    {
        return $user->id === $ownedVehicle->user_id;
    }
}
