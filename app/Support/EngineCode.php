<?php

namespace App\Support;

final class EngineCode
{
    public static function normalize(?string $code): ?string
    {
        $code = trim((string) $code);

        return $code === '' ? null : mb_strtoupper($code);
    }

    /**
     * Les anciens decodages enregistraient parfois le seul caractere 8 du VIN.
     * Ce caractere depend du constructeur ; il ne peut pas etre compare a un
     * code moteur complet sans table de correspondance verifiee.
     */
    public static function forCompatibility(?string $code): ?string
    {
        $code = self::normalize($code);

        return $code !== null && mb_strlen($code) > 1 ? $code : null;
    }
}
