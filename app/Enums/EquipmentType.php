<?php

namespace App\Enums;

enum EquipmentType: string
{
    case AgriculturalTractor = 'agricultural_tractor';
    case AerialPlatform = 'aerial_platform';
    case Excavator = 'excavator';
    case BackhoeLoader = 'backhoe_loader';
    case Loader = 'loader';
    case Telehandler = 'telehandler';
    case Forklift = 'forklift';
    case Generator = 'generator';
    case Other = 'other';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::AgriculturalTractor => 'Tracteur agricole',
            self::AerialPlatform => 'Nacelle élévatrice',
            self::Excavator => 'Pelle hydraulique',
            self::BackhoeLoader => 'Tractopelle',
            self::Loader => 'Chargeuse',
            self::Telehandler => 'Chariot télescopique',
            self::Forklift => 'Chariot élévateur',
            self::Generator => 'Groupe électrogène',
            self::Other => 'Autre engin ou matériel',
        };
    }
}
