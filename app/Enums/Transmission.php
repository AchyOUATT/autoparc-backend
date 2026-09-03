<?php

namespace App\Enums;

enum Transmission: string
{
    case Manual        = 'manual';
    case Automatic     = 'automatic';
    case Cvt           = 'cvt';
    case DualClutch    = 'dual_clutch';
    case SingleSpeed   = 'single_speed'; // electrique

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
