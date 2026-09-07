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
        // Critères structurés — véhicule (tous optionnels)
        'brand_id',
        'vehicle_model_id',
        'vehicle_type',
        'body_style',
        'year_min',
        'year_max',
        // Critères structurés — pièce détachée
        'part_category_id',
        'oem_number',
        // Critères structurés — accessoire
        'accessory_category',
        // Critères communs pièce + accessoire
        'need_manufacturer_id',
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
            'budget_max'       => 'decimal:2',
            'year_min'         => 'integer',
            'year_max'         => 'integer',
            'part_category_id' => 'integer',
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

    /** Catégorie de pièce souhaitée (optionnel — type=part). */
    public function partCategory(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\PartCategory::class, 'part_category_id');
    }

    /** Fabricant souhaité (optionnel — type=part|accessory). */
    public function needManufacturer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Manufacturer::class, 'need_manufacturer_id');
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
