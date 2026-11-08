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

    /**
     * Le libelle de la pastille, DANS LA LANGUE DE LA REQUETE.
     *
     * Ce libelle n'est pas un detail d'administration : `VehicleResource` le
     * sert en `deal_label` et l'application l'affiche tel quel sur l'accueil
     * et dans la liste — elle ne le traduit pas, a dessein, pour ne pas
     * dupliquer la traduction des trois types. Le serveur est donc la SEULE
     * source de ce texte, et tant qu'il le rendait en dur, un accueil anglais
     * affichait une section « Good deals » remplie de pastilles
     * « Bonne affaire ».
     *
     * Les cles sont les chaines francaises, comme partout dans lang/*.json :
     * un client qui n'annonce aucune langue — le binaire deja installe — recoit
     * exactement le texte d'avant.
     */
    public function label(): string
    {
        return match ($this) {
            self::GoodDeal  => __('Bonne affaire'),
            self::FlashSale => __('Vente flash'),
            self::Clearance => __('Déstockage'),
        };
    }

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
