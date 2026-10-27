<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PrivateListingPhoto extends Model
{
    protected $guarded = ['id'];

    public function listing()
    {
        return $this->belongsTo(PrivateVehicleListing::class, 'private_vehicle_listing_id');
    }
}
