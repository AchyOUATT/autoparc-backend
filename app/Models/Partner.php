<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_name', 'contact_name', 'phone', 'whatsapp', 'email', 'notes', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    // ── Relations polymorphiques ──────────────────────────────────────

    public function parts()
    {
        return $this->morphedByMany(Part::class, 'partnerable')
                    ->withPivot('role', 'notes')
                    ->withTimestamps();
    }

    public function accessories()
    {
        return $this->morphedByMany(Accessory::class, 'partnerable')
                    ->withPivot('role', 'notes')
                    ->withTimestamps();
    }

    // ── Scopes ──────────────────────────────────────────────────────

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (blank($term)) {
            return $q;
        }

        return $q->where(function (Builder $sub) use ($term) {
            $sub->where('company_name', 'like', "%{$term}%")
                ->orWhere('contact_name', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
