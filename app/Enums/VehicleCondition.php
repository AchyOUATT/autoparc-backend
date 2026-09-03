<?php

namespace App\Enums;

enum VehicleCondition: string
{
    case New       = 'new';
    case Used      = 'used';
    case Damaged   = 'damaged';
    case ForParts  = 'for_parts';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
