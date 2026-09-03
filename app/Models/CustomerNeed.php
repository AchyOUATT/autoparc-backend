<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerNeed extends Model
{
    protected $fillable = [
        'type',
        'description',
        'budget_max',
        'currency',
        // Critères structurés pour le matching véhicule (tous optionnels)
        'brand_id',
        'vehicle_model_id',
        'vehicle_type',
        'body_style',
        'year_min',
        'year_max',
        // Contact
        'contact_name',
        'contact_phone',
        'contact_email',
        'firebase_uid',
        'status',
        'staff_notes',
    ];

    protected function casts(): array
    {
        return [
            'budget_max' => 'decimal:2',
            'year_min'   => 'integer',
            'year_max'   => 'integer',
        ];
    }

    /** Relation vers la marque souhaitée (optionnelle). */
    public function brand(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Brand::class);
    }

    /** Relation vers le modèle souhaité (optionnel). */
    public function vehicleModel(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\VehicleModel::class);
    }

    /** Labels lisibles pour les statuts. */
    public static array $statusLabels = [
        'pending'   => 'En attente',
        'contacted' => 'Contacté',
        'fulfilled' => 'Satisfait',
        'cancelled' => 'Annulé',
    ];

    public function statusLabel(): string
    {
        return static::$statusLabels[$this->status] ?? $this->status;
    }
}
