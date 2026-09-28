<?php

namespace App\Enums;

/**
 * Le verdict d'un controle, et surtout la maniere de le dire.
 *
 * Cette formulation vit ici, en un seul endroit, pour une raison qui n'est pas
 * cosmetique : une application qui annonce « votre vehicule est en bon etat »
 * certifie ce qu'elle n'a pas constate. Elle n'a rien vu, rien mesure ; elle a
 * pose des questions et recopie des reponses. La nuance est juridique autant
 * qu'honnete, et elle se perd des que la phrase est reecrite a trois endroits.
 *
 * D'ou le vocabulaire retenu : on parle de ce qui a ete verifie et de ce qui
 * reste a faire, jamais de l'etat du vehicule. « Rien a signaler sur les treize
 * points verifies » dit exactement ce qui s'est passe. « Pret a partir » dit
 * autre chose, et l'application n'en sait rien.
 */
enum CheckVerdict: string
{
    /** Au moins un defaut sur un point juge bloquant. */
    case Blocked = 'blocked';

    /** Aucun defaut bloquant, mais des points a reprendre. */
    case Attention = 'attention';

    /** Rien de signale sur ce qui a ete verifie. */
    case Clear = 'clear';

    public function libelle(): string
    {
        return match ($this) {
            self::Blocked   => 'À régler avant de partir',
            self::Attention => 'Points à surveiller',
            self::Clear     => 'Rien à signaler',
        };
    }

    /**
     * La phrase affichee sous le verdict.
     *
     * Elle compte, elle ne juge pas : le nombre de points verifies figure dans
     * le cas favorable precisement pour rappeler que le constat porte sur cette
     * liste-la, et sur rien d'autre.
     */
    public function detail(int $bloquants, int $aSurveiller, int $verifies): string
    {
        return match ($this) {
            self::Blocked => $this->compte($bloquants, 'point à régler avant de partir', 'points à régler avant de partir'),

            self::Attention => $this->compte($aSurveiller, 'point à surveiller', 'points à surveiller')
                . ", rien qui empêche de partir",

            self::Clear => $verifies === 1
                ? 'Rien à signaler sur le point vérifié'
                : "Rien à signaler sur les {$verifies} points vérifiés",
        };
    }

    private function compte(int $n, string $singulier, string $pluriel): string
    {
        return $n . ' ' . ($n === 1 ? $singulier : $pluriel);
    }

    /**
     * Le verdict deduit des reponses.
     *
     * Ce calcul reste cote serveur, comme celui des echeances : l'application
     * afficherait sinon un verdict que la fiche du vehicule contredirait des
     * qu'un seuil bougerait d'un cote sans l'autre.
     *
     * @param  iterable<array{severity:string,status:string}>  $reponses
     */
    public static function depuis(iterable $reponses): self
    {
        $bloquant = false;
        $attention = false;

        foreach ($reponses as $reponse) {
            if ($reponse['status'] === 'bad' && $reponse['severity'] === 'blocking') {
                $bloquant = true;
            } elseif ($reponse['status'] === 'bad' || $reponse['status'] === 'watch') {
                $attention = true;
            }
        }

        return match (true) {
            $bloquant  => self::Blocked,
            $attention => self::Attention,
            default    => self::Clear,
        };
    }
}
