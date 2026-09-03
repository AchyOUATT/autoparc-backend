<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Draft     = 'draft';
    case Confirmed = 'confirmed';
    case Prepared  = 'prepared';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
