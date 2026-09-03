<?php

namespace App\Models;

use App\Enums\StockMovementType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_id', 'type', 'quantity', 'stock_after',
        'source_type', 'source_id', 'reason', 'user_id',
    ];

    protected function casts(): array
    {
        return ['type' => StockMovementType::class];
    }

    public function part()
    {
        return $this->belongsTo(Part::class);
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
