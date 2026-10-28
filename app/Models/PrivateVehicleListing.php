<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** A public snapshot, never a public view of the owner's garage or the shop's stock. */
class PrivateVehicleListing extends Model
{
    protected $guarded = ['id'];
    protected $attributes = ['revision' => 1, 'status' => 'draft', 'currency' => 'XOF'];

    protected function casts(): array
    {
        return [
            'year' => 'integer', 'mileage_km' => 'integer', 'price' => 'integer',
            'revision' => 'integer',
            'vehicle_identity' => 'array',
            'brand_id' => 'integer', 'vehicle_model_id' => 'integer', 'trim_id' => 'integer',
            'engine_type_id' => 'integer', 'drivetrain_id' => 'integer', 'color_id' => 'integer',
            'whatsapp_enabled' => 'boolean', 'phone_verified_at' => 'datetime',
            'submitted_at' => 'datetime', 'published_at' => 'datetime',
        ];
    }

    public function seller()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function photos()
    {
        return $this->hasMany(PrivateListingPhoto::class)->orderBy('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')->whereNotNull('published_at')
            ->whereHas('seller', fn ($seller) => $seller->where('is_active', true)->where('role', 'client'));
    }

    public function editable(): bool
    {
        return in_array($this->status, ['draft', 'pending', 'published', 'rejected'], true);
    }

    public function resetReview(): void
    {
        $this->forceFill([
            'status' => 'draft', 'published_at' => null, 'submitted_at' => null,
            'reviewed_by' => null, 'review_reason' => null,
            'revision' => $this->revision + 1,
        ])->save();
    }
}
