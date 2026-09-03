<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AccessoryOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'accessory_order_id', 'accessory_id', 'designation',
        'quantity', 'unit_price', 'discount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity'   => 'integer',
            'unit_price' => 'decimal:2',
            'discount'   => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order()
    {
        return $this->belongsTo(AccessoryOrder::class, 'accessory_order_id');
    }

    public function accessory()
    {
        return $this->belongsTo(Accessory::class);
    }
}
