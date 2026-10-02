<?php

namespace App\Enums;

/**
 * D'ou vient une ligne de compatibilite.
 *
 * La distinction n'est pas decorative : `catalog:backfill-fitments --fresh`
 * effacait toutes les lignes d'une piece avant de les reecrire, sans pouvoir
 * epargner celles qu'un humain avait saisies. Cette colonne est ce qui lui
 * permet de ne detruire que son propre travail.
 *
 * Elle porte aussi une information que le catalogue n'avait pas : les
 * compatibilites fabriquees par FitmentPlanner sont des ordres de grandeur
 * credibles, pas des donnees de constructeur. Rien ne les distinguait d'une
 * compatibilite verifiee, et un client pouvait acheter la mauvaise piece sur
 * une affirmation que personne n'avait relue.
 */
enum FitmentSource: string
{
    /** Saisie par quelqu'un, qui en repond. Jamais effacee automatiquement. */
    case Declared = 'declared';

    /** Fabriquee par FitmentPlanner pour peupler la demonstration. */
    case Generated = 'generated';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Declared  => 'Declaree',
            self::Generated => 'Proposee',
        };
    }

    /**
     * Une ligne protegee survit au recalcul du catalogue.
     *
     * C'est la seule question que la commande de backfill pose a cet enum, et
     * la raison pour laquelle `declared` est la valeur par defaut en base :
     * un chemin d'ecriture qui oublierait de renseigner la colonne protege la
     * donnee au lieu de l'exposer.
     */
    public function estProtegee(): bool
    {
        return $this === self::Declared;
    }
}
