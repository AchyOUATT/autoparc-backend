<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A documented market and model-year range, not a claim of an exhaustive range. */
class TrimApplication extends Model
{
    protected $fillable = [
        'trim_id', 'market', 'model_year_from', 'model_year_to', 'sources', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'model_year_from' => 'integer',
            'model_year_to' => 'integer',
            'sources' => 'array',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function trim(): BelongsTo
    {
        return $this->belongsTo(Trim::class);
    }
}
