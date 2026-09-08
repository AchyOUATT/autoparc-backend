<?php

namespace App\Enums;

/**
 * Mise en avant commerciale d'un vehicule.
 *
 * L'absence de valeur (null sur la colonne) signifie « pas de promotion » :
 * il n'y a volontairement pas de cas « aucune », pour que le code ne puisse
 * pas confondre « pas de promo » et « promo de type neutre ».
 *
 * Ces mises en avant ne modifient pas le prix — elles signalent une offre,
 * elles ne la chiffrent pas — et n'expirent pas d'elles-memes : c'est le
 * staff qui les retire.
 */
enum VehicleDealType: string
{
    case GoodDeal   = 'good_deal';
    case FlashSale  = 'flash_sale';
    case Clearance  = 'clearance';

    public function label(): string
    {
        return match ($this) {
            self::GoodDeal  => 'Bonne affaire',
            self::FlashSale => 'Vente flash',
            self::Clearance => 'Déstockage',
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
