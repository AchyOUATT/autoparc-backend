<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleCheck;

/**
 * Un controle appartient a celui qui l'a fait.
 *
 * Aucune methode update ni delete, volontairement : un passage est un constat
 * date, et la fonction n'offre que de refaire le controle. Une policy qui
 * autoriserait la modification laisserait croire qu'une route existe.
 *
 * La comparaison porte sur user_id du controle plutot que sur celui du vehicule :
 * c'est la colonne que la table porte, et un vehicule supprime (SoftDeletes)
 * laisserait sinon ses controles sans proprietaire identifiable.
 */
class VehicleCheckPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, VehicleCheck $check): bool
    {
        return $user->id === $check->user_id;
    }
}
