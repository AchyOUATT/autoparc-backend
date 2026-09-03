<?php

namespace App\Enums;

enum RentalStatus: string
{
    case Reserved  = 'reserved';
    case Ongoing   = 'ongoing';
    case Returned  = 'returned';
    case Overdue   = 'overdue';
    case Cancelled = 'cancelled';

    public function blocksVehicle(): bool
    {
        return in_array($this, [self::Reserved, self::Ongoing, self::Overdue], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
