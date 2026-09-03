<?php

namespace App\Enums;

/** Origine commerciale de la piece detachee. */
enum PartType: string
{
    case Oem        = 'oem';        // piece constructeur
    case Oes        = 'oes';        // equipementier d'origine
    case Aftermarket = 'aftermarket';
    case Salvage    = 'salvage';    // piece de casse / recuperation

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
