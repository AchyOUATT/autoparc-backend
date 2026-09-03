<?php

namespace App\Enums;

enum VehicleAvailability: string
{
    case Sale = 'sale';
    case Rent = 'rent';
    case Both = 'both';
    case None = 'none';

    public function allowsSale(): bool
    {
        return in_array($this, [self::Sale, self::Both], true);
    }

    public function allowsRent(): bool
    {
        return in_array($this, [self::Rent, self::Both], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
