<?php

namespace App\Enums;

enum CustomerType: string
{
    case Individual = 'individual';
    case Company    = 'company';
    case Ngo        = 'ngo';
    case Public     = 'public';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
