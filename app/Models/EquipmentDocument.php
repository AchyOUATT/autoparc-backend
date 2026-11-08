<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Private evidence attached to one equipment asset, never a public photo. */
class EquipmentDocument extends Model
{
    public const TYPES = ['ownership', 'registration', 'inspection', 'insurance', 'other'];

    protected $fillable = [
        'vehicle_id', 'document_type', 'original_name', 'mime_type', 'size',
        'disk', 'path', 'expires_on', 'status', 'uploaded_by',
    ];

    protected $hidden = ['disk', 'path', 'vehicle_id', 'uploaded_by'];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'size' => 'integer'];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }
}
