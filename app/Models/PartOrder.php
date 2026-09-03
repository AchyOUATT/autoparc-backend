<?php

namespace App\Models;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartOrder extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'vehicle_id', 'created_by', 'status', 'payment_status',
        'subtotal', 'discount', 'tax_amount', 'total_amount', 'currency',
        'ordered_at', 'delivered_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'status'         => OrderStatus::class,
            'payment_status' => PaymentStatus::class,
            'subtotal'       => 'decimal:2',
            'discount'       => 'decimal:2',
            'tax_amount'     => 'decimal:2',
            'total_amount'   => 'decimal:2',
            'ordered_at'     => 'date',
            'delivered_at'   => 'date',
        ];
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function items()
    {
        return $this->hasMany(PartOrderItem::class);
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function recalculateTotals(): self
    {
        $this->subtotal = $this->items->sum('line_total');
        $this->total_amount = $this->subtotal - $this->discount + $this->tax_amount;

        return $this;
    }
}
