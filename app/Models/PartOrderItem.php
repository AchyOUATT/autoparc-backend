<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PartOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_order_id', 'part_id', 'designation', 'oem_reference',
        'quantity', 'unit_price', 'discount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount'   => 'decimal:2',
            'line_total' => 'decimal:2',
            'quantity'   => 'integer',
        ];
    }

    public function order()
    {
        return $this->belongsTo(PartOrder::class, 'part_order_id');
    }

    public function part()
    {
        return $this->belongsTo(Part::class);
    }
}
