<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Donnees propres a un vehicule NON IMMATRICULE (importe). */
class VehicleImportDetail extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_id', 'origin_country_id', 'purchase_country_id', 'supplier_name',
        'auction_lot_no', 'port_of_loading', 'port_of_entry', 'bill_of_lading_no',
        'container_no', 'shipping_date', 'arrival_date', 'customs_cleared',
        'customs_declaration_no', 'customs_duty_amount', 'freight_cost',
        'steering_side', 'odometer_at_import_km', 'foreign_plate', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'shipping_date'       => 'date',
            'arrival_date'        => 'date',
            'customs_cleared'     => 'boolean',
            'customs_duty_amount' => 'decimal:2',
            'freight_cost'        => 'decimal:2',
        ];
    }

    public function vehicle()
    {
        return $this->belongsTo(Vehicle::class);
    }

    /** Pays de provenance. */
    public function originCountry()
    {
        return $this->belongsTo(Country::class, 'origin_country_id');
    }

    public function purchaseCountry()
    {
        return $this->belongsTo(Country::class, 'purchase_country_id');
    }
}
