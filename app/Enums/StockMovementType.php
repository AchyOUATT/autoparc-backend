<?php

namespace App\Enums;

enum StockMovementType: string
{
    case In         = 'in';
    case Out        = 'out';
    case Adjustment = 'adjustment';
    case Return     = 'return';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
