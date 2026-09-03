<?php

namespace App\Enums;

enum VehicleStatus: string
{
    case InStock     = 'in_stock';
    case Reserved    = 'reserved';
    case Sold        = 'sold';
    case Rented      = 'rented';
    case Maintenance = 'maintenance';
    case InTransit   = 'in_transit';
    case Archived    = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::InStock     => 'En stock',
            self::Reserved    => 'Reserve',
            self::Sold        => 'Vendu',
            self::Rented      => 'En location',
            self::Maintenance => 'En atelier',
            self::InTransit   => 'En transit',
            self::Archived    => 'Archive',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
