<?php

namespace App\Enums;

enum FaultStatus: string
{
    case Declared   = 'declared';
    case Diagnosed  = 'diagnosed';
    case Quoted     = 'quoted';
    case Repairing  = 'repairing';
    case Repaired   = 'repaired';
    case WontFix    = 'wont_fix';

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Repaired, self::WontFix], true);
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
