<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin     = 'admin';
    case Manager   = 'manager';
    case Sales     = 'sales';
    case Mechanic  = 'mechanic';
    case Warehouse = 'warehouse';
    case Viewer    = 'viewer';
    case Client    = 'client'; // compte public : gere son propre garage, pas d'acces back-office

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Tout role sauf Client a acces au back-office. */
    public function isStaff(): bool
    {
        return $this !== self::Client;
    }
}
