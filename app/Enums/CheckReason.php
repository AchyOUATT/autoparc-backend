<?php

namespace App\Enums;

/**
 * Le motif d'un controle, et le vocabulaire qui va avec.
 *
 * Il ne suffit pas de composer une autre liste : chaque motif parle une autre
 * langue. « À régler avant de partir » ne veut rien dire pour un controle
 * d'hivernage, ou personne ne part nulle part, et « rien qui empêche de
 * partir » encore moins. Laisser ce vocabulaire dans le verdict l'aurait fige
 * sur le premier motif ecrit.
 *
 * Le libelle du motif lui-meme n'est pas ici : celui du saisonnier depend de la
 * saison en cours, donc de la date, que le service connait et pas l'enum.
 */
enum CheckReason: string
{
    /** Avant un trajet : la distance commande la liste. */
    case Trip = 'trip';

    /** A l'entree d'une saison : c'est la saison qui la commande. */
    case Seasonal = 'seasonal';

    public function verdictBloquant(): string
    {
        return match ($this) {
            self::Trip     => 'À régler avant de partir',
            self::Seasonal => 'À régler avant la saison',
        };
    }

    /**
     * Ce qu'on ajoute quand rien ne bloque.
     *
     * Complete une phrase du type « 2 points à surveiller, ... ».
     */
    public function complementSansBlocage(): string
    {
        return match ($this) {
            self::Trip     => 'rien qui empêche de partir',
            self::Seasonal => "rien d'urgent",
        };
    }

    /** Le titre de l'ecran, cote application. */
    public function titreParDefaut(): string
    {
        return match ($this) {
            self::Trip     => 'Avant de partir',
            self::Seasonal => 'Contrôle de saison',
        };
    }
}
