<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'payable_type', 'payable_id', 'reference', 'amount', 'currency',
        'method', 'transaction_id', 'paid_at', 'received_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'method'  => PaymentMethod::class,
            'amount'  => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function payable()
    {
        return $this->morphTo();
    }

    public function receiver()
    {
        return $this->belongsTo(User::class, 'received_by');
    }
}
