<?php

namespace App\Enums;

enum PartCondition: string
{
    case New         = 'new';
    case Refurbished = 'refurbished';
    case Used        = 'used';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
