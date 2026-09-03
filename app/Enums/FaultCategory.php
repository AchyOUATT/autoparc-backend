<?php

namespace App\Enums;

/** Famille de la panne declaree. */
enum FaultCategory: string
{
    case Engine       = 'engine';
    case Transmission = 'transmission';
    case Electrical   = 'electrical';
    case Braking      = 'braking';
    case Suspension   = 'suspension';
    case Steering     = 'steering';
    case Cooling      = 'cooling';
    case AirCon       = 'air_conditioning';
    case Bodywork     = 'bodywork';
    case Interior     = 'interior';
    case Electronics  = 'electronics';
    case Tyres        = 'tyres';
    case Other        = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
