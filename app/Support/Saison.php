<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Les deux saisons du Burkina, et ce qu'elles font aux vehicules.
 *
 * Elles ne sont pas un detail de decor : ce sont les deux evenements annuels qui
 * usent une voiture ici, et ils n'usent pas les memes pieces. L'hivernage tue
 * les balais d'essuie-glace, remplit les ecoulements et rend dangereux un pneu
 * a moitie use. La saison seche bouche les filtres en quelques milliers de
 * kilometres, encrasse le radiateur au moment ou il fait le plus chaud, et
 * evapore l'electrolyte des batteries.
 *
 * D'ou un controle par saison, court, qui ne demande ni distance ni pretexte :
 * c'est la seule occasion de l'annee ou l'application a quelque chose a dire a
 * quelqu'un qui ne part jamais loin.
 *
 * Le decoupage est calendaire et volontairement grossier. Une saison ne commence
 * pas un jour precis, et faire dependre un controle de la date exacte de la
 * premiere pluie demanderait une donnee que personne n'a.
 */
enum Saison: string
{
    /** Mai a octobre : hivernage, pluies. */
    case Pluies = 'pluies';

    /** Novembre a avril : saison seche, harmattan. */
    case Seche = 'seche';

    /** Mois de l'annee couverts, pour le rattachement d'un point. */
    public function mois(): array
    {
        return match ($this) {
            self::Pluies => [5, 6, 7, 8, 9, 10],
            self::Seche  => [11, 12, 1, 2, 3, 4],
        };
    }

    public function libelle(): string
    {
        return match ($this) {
            self::Pluies => 'Hivernage',
            self::Seche  => 'Saison sèche',
        };
    }

    /** Le nom du controle, tel que l'application le propose. */
    public function libelleControle(): string
    {
        return match ($this) {
            self::Pluies => "Contrôle d'hivernage",
            self::Seche  => 'Contrôle de saison sèche',
        };
    }

    /** Ce que la saison fait au vehicule, en une phrase. */
    public function raison(): string
    {
        return match ($this) {
            self::Pluies => "L'eau et la boue : ce qui compte est de voir, d'être vu et de tenir la route.",
            self::Seche  => 'La poussière et la chaleur : ce qui compte est de respirer et de refroidir.',
        };
    }

    public static function pour(?CarbonImmutable $jour = null): self
    {
        $mois = (int) ($jour ?? CarbonImmutable::today())->month;

        return in_array($mois, self::Pluies->mois(), true) ? self::Pluies : self::Seche;
    }
}
