<?php

namespace App\Enums;

/** Statut d'immatriculation d'un vehicule. */
enum RegistrationStatus: string
{
    case Unregistered = 'unregistered'; // non immatricule (import, pays de provenance)
    case Registered   = 'registered';   // deja immatricule (plaque, kilometrage, pannes)

    public function label(): string
    {
        return match ($this) {
            self::Unregistered => 'Non immatricule',
            self::Registered   => 'Deja immatricule',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
