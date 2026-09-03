<?php

namespace App\Models;

use App\Enums\AccessoryCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Accessory extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'sku', 'name', 'description', 'category', 'manufacturer_id',
        'weight_kg', 'dimensions', 'warranty_months', 'cost_price', 'selling_price',
        'currency', 'vat_rate', 'stock_quantity', 'stock_alert_threshold',
        'storage_location', 'location_id', 'is_active', 'is_available',
    ];

    protected function casts(): array
    {
        return [
            'category'       => AccessoryCategory::class,
            'cost_price'     => 'decimal:2',
            'selling_price'  => 'decimal:2',
            'vat_rate'       => 'decimal:2',
            'weight_kg'      => 'decimal:3',
            'stock_quantity' => 'integer',
            'is_active'      => 'boolean',
            'is_available'   => 'boolean',
        ];
    }

    public function manufacturer()
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    /** Compatibilites declarees avec des modeles / finitions / motorisations. */
    public function fitments()
    {
        return $this->hasMany(AccessoryFitment::class);
    }

    public function orderItems()
    {
        return $this->hasMany(AccessoryOrderItem::class);
    }

    public function media()
    {
        return $this->morphMany(Media::class, 'mediable')->orderBy('position');
    }

    /** Partenaires (fournisseurs, importateurs…) liés à cet accessoire. */
    public function partners()
    {
        return $this->morphToMany(Partner::class, 'partnerable')
                    ->withPivot('role', 'notes')
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

    public function scopeOfCategory(Builder $q, AccessoryCategory|string $category): Builder
    {
        return $q->where('category', $category instanceof AccessoryCategory ? $category->value : $category);
    }

    /** Recherche libre par SKU ou nom. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (blank($term)) {
            return $q;
        }

        return $q->where(function (Builder $sub) use ($term) {
            $sub->where('sku', 'like', "%{$term}%")
                ->orWhere('name', 'like', "%{$term}%");
        });
    }

    /** Accessoires compatibles avec un modele de vehicule donne. */
    public function scopeCompatibleWithModel(Builder $q, int $vehicleModelId): Builder
    {
        return $q->whereHas('fitments', fn ($f) => $f->where('vehicle_model_id', $vehicleModelId));
    }
}
