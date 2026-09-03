<?php

namespace App\Enums;

enum FaultSeverity: string
{
    case Minor    = 'minor';
    case Moderate = 'moderate';
    case Major    = 'major';
    case Critical = 'critical';

    public function weight(): int
    {
        return match ($this) {
            self::Minor    => 1,
            self::Moderate => 3,
            self::Major    => 7,
            self::Critical => 12,
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
