<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Motricite : FWD (traction), RWD (propulsion), AWD, 4WD. */
class Drivetrain extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'label'];

    public function vehicles()
    {
        return $this->hasMany(Vehicle::class);
    }
}
