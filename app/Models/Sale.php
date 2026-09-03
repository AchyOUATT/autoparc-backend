<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Sale extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'vehicle_id', 'sold_by', 'agreed_price',
        'discount', 'tax_amount', 'registration_fees', 'total_amount', 'currency',
        'payment_status', 'faults_disclosed', 'sold_at', 'delivery_date', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'payment_status'    => PaymentStatus::class,
            'agreed_price'      => 'decimal:2',
            'discount'          => 'decimal:2',
            'tax_amount'        => 'decimal:2',
            'registration_fees' => 'decimal:2',
            'total_amount'      => 'decimal:2',
            'faults_disclosed'  => 'boolean',
            'sold_at'           => 'date',
            'delivery_date'     => 'date',
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

    public function seller()
    {
        return $this->belongsTo(User::class, 'sold_by');
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getBalanceAttribute(): float
    {
        return round((float) $this->total_amount - $this->paid_amount, 2);
    }
}
