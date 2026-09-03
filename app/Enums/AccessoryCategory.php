<?php

namespace App\Enums;

/** Categorie d'un accessoire (optionnel, ne remplace ni ne repare rien). */
enum AccessoryCategory: string
{
    case Esthetique = 'esthetique';
    case Confort    = 'confort';
    case Securite   = 'securite';
    case Multimedia = 'multimedia';
    case Utilitaire = 'utilitaire';

    public function label(): string
    {
        return match ($this) {
            self::Esthetique => 'Esthétique',
            self::Confort    => 'Confort',
            self::Securite   => 'Sécurité',
            self::Multimedia => 'Multimédia',
            self::Utilitaire => 'Utilitaire',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
