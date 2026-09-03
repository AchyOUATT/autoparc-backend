<?php

namespace App\Models;

use App\Enums\PaymentStatus;
use App\Enums\RentalStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rental extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reference', 'customer_id', 'vehicle_id', 'handled_by', 'status',
        'with_driver', 'driver_name', 'start_at', 'expected_return_at', 'actual_return_at',
        'daily_rate', 'billed_days', 'deposit_amount', 'deposit_returned', 'extra_charges',
        'total_amount', 'currency', 'payment_status', 'mileage_start_km', 'mileage_end_km',
        'mileage_limit_km', 'extra_km_rate', 'fuel_level_start', 'fuel_level_end',
        'pickup_location', 'return_location', 'checkout_notes', 'checkin_notes',
    ];

    protected function casts(): array
    {
        return [
            'status'             => RentalStatus::class,
            'payment_status'     => PaymentStatus::class,
            'with_driver'        => 'boolean',
            'deposit_returned'   => 'boolean',
            'start_at'           => 'datetime',
            'expected_return_at' => 'datetime',
            'actual_return_at'   => 'datetime',
            'daily_rate'         => 'decimal:2',
            'deposit_amount'     => 'decimal:2',
            'extra_charges'      => 'decimal:2',
            'total_amount'       => 'decimal:2',
            'extra_km_rate'      => 'decimal:2',
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

    public function agent()
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function payments()
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function isOverdue(): bool
    {
        return $this->actual_return_at === null
            && $this->expected_return_at->isPast()
            && $this->status->blocksVehicle();
    }

    public function getMileageDoneKmAttribute(): ?int
    {
        if ($this->mileage_start_km === null || $this->mileage_end_km === null) {
            return null;
        }

        return $this->mileage_end_km - $this->mileage_start_km;
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->whereIn('status', [
            RentalStatus::Reserved->value,
            RentalStatus::Ongoing->value,
            RentalStatus::Overdue->value,
        ]);
    }

    /** Reservations chevauchant une periode donnee. */
    public function scopeOverlapping(Builder $q, string $start, string $end): Builder
    {
        return $q->where('start_at', '<', $end)->where('expected_return_at', '>', $start);
    }
}
