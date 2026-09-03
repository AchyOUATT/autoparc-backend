<?php

namespace App\Models;

use App\Enums\PartCondition;
use App\Enums\PartType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Part extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'description', 'part_category_id', 'manufacturer_id',
        'manufacturer_reference', 'type', 'condition', 'donor_vehicle_id',
        'weight_kg', 'dimensions', 'warranty_months', 'cost_price', 'selling_price',
        'currency', 'vat_rate', 'stock_quantity', 'stock_alert_threshold',
        'storage_location', 'location_id', 'is_active', 'is_available',
    ];

    protected function casts(): array
    {
        return [
            'type'           => PartType::class,
            'condition'      => PartCondition::class,
            'cost_price'     => 'decimal:2',
            'selling_price'  => 'decimal:2',
            'vat_rate'       => 'decimal:2',
            'weight_kg'      => 'decimal:3',
            'stock_quantity' => 'integer',
            'is_active'      => 'boolean',
            'is_available'   => 'boolean',
        ];
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function category()
    {
        return $this->belongsTo(PartCategory::class, 'part_category_id');
    }

    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function donorVehicle()
    {
        return $this->belongsTo(Vehicle::class, 'donor_vehicle_id');
    }

    /** Numeros OEM couverts par cette piece (cross-reference). */
    public function oemNumbers()
    {
        return $this->belongsToMany(OemNumber::class, 'oem_number_part')
                    ->withPivot('is_primary')
                    ->withTimestamps();
    }

    public function primaryOemNumber()
    {
        return $this->oemNumbers()->wherePivot('is_primary', true);
    }

    /** Compatibilites declarees directement (sans OEM). */
    public function fitments()
    {
        return $this->hasMany(PartFitment::class);
    }

    public function stockMovements()
    {
        return $this->hasMany(StockMovement::class);
    }

    public function orderItems()
    {
        return $this->hasMany(PartOrderItem::class);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    /** Partenaires (fournisseurs, importateurs…) liés à cette pièce. */
    public function partners()
    {
        return $this->morphToMany(Partner::class, 'partnerable')
                    ->withPivot('role', 'notes')
                    ->withTimestamps();
    }

    public function faults()
    {
        return $this->belongsToMany(VehicleFault::class, 'fault_part')
                    ->withPivot('quantity')
                    ->withTimestamps();
    }

    /* --------------------------------------------------------------------*/

    public function isLowStock(): bool
    {
        return $this->stock_quantity <= $this->stock_alert_threshold;
    }

    public function getPriceIncludingVatAttribute(): float
    {
        return round((float) $this->selling_price * (1 + (float) $this->vat_rate / 100), 2);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeInStock(Builder $q): Builder
    {
        return $q->where('stock_quantity', '>', 0);
    }

    public function scopeLowStock(Builder $q): Builder
    {
        return $q->whereColumn('stock_quantity', '<=', 'stock_alert_threshold');
    }

    /** Recherche libre incluant les numeros OEM. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (blank($term)) {
            return $q;
        }

        $normalized = OemNumber::normalize($term);

        return $q->where(function (Builder $sub) use ($term, $normalized) {
            $sub->where('sku', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%")
                ->orWhere('manufacturer_reference', 'like', "%{$term}%")
                ->orWhereHas('oemNumbers', fn ($o) => $o->where('normalized_number', 'like', "%{$normalized}%"));
        });
    }

    /** Pieces couvrant un numero OEM donne. */
    public function scopeMatchingOem(Builder $q, string $oem): Builder
    {
        $normalized = OemNumber::normalize($oem);

        return $q->whereHas('oemNumbers', fn ($o) => $o->where('normalized_number', $normalized));
    }
}
