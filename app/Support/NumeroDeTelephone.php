<?php

namespace App\Support;

/**
 * La forme d'un numero qui sert a le COMPARER, et non a l'afficher.
 *
 * ── POURQUOI UNE CLE PLUTOT QUE LA CHAINE TAPEE ───────────────────────────
 *
 * « +226 70 00 00 01 », « 00226 70000001 » et « 70 00 00 01 » designent la
 * meme ligne et sont trois chaines differentes. Comparer les chaines brutes
 * laisserait donc creer trois comptes sur un seul numero — c'est-a-dire tout
 * ce que l'unicite est censee empecher.
 *
 * ── LES TROIS REGLES, ET CE QU'ELLES COUTENT ──────────────────────────────
 *
 * 1. On ne garde que les chiffres. Espaces, points, tirets, parentheses et le
 *    « + » disparaissent : ils sont de la mise en forme, jamais de
 *    l'information.
 *
 * 2. Le prefixe international du Burkina Faso est retire quand il est la :
 *    « 226 » en tete d'un numero de 11 chiffres, ou « 00226 ». Sans cette
 *    regle, le meme vendeur qui tape tantot son numero local, tantot sa forme
 *    internationale, obtiendrait deux comptes.
 *
 *    On ne le retire PAS d'un numero plus court ni plus long : un numero
 *    etranger commencant par 226 existe, et le tronquer inventerait une
 *    collision.
 *
 * 3. Rien d'autre n'est devine. Pas de pays par defaut ajoute, pas de
 *    longueur imposee : ce service decide d'une EGALITE, pas de la validite
 *    d'un numero. Trancher au-dela refuserait des numeros legitimes que
 *    personne n'a mesures.
 */
final class NumeroDeTelephone
{
    /** Indicatif du Burkina Faso, le seul pays servi aujourd'hui. */
    private const INDICATIF = '226';

    /** Longueur d'un numero burkinabe sans indicatif. */
    private const LONGUEUR_LOCALE = 8;

    public static function cle(?string $numero): ?string
    {
        $chiffres = preg_replace('/\D/', '', (string) $numero);

        if ($chiffres === '') {
            return null;
        }

        // « 00226 70000001 » -> « 226 70000001 », avant la regle suivante.
        if (str_starts_with($chiffres, '00'.self::INDICATIF)) {
            $chiffres = substr($chiffres, 2);
        }

        $avecIndicatif = strlen(self::INDICATIF) + self::LONGUEUR_LOCALE;

        if (strlen($chiffres) === $avecIndicatif && str_starts_with($chiffres, self::INDICATIF)) {
            $chiffres = substr($chiffres, strlen(self::INDICATIF));
        }

        return $chiffres;
    }

    /** Vrai quand la chaine ressemble a une adresse et non a un numero. */
    public static function estUnCourriel(?string $identifiant): bool
    {
        return str_contains((string) $identifiant, '@');
    }
}
