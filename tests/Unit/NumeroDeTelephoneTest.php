<?php

namespace Tests\Unit;

use App\Support\NumeroDeTelephone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NumeroDeTelephoneTest extends TestCase
{
    /**
     * @return array<string, array{?string, ?string}>
     */
    public static function formesComposables(): array
    {
        return [
            'deja e.164' => ['+22670002244', '+22670002244'],
            'espaces et separateurs' => ['+226 70 00 22 44', '+22670002244'],
            'huit chiffres locaux' => ['70002244', '+22670002244'],
            'indicatif sans plus' => ['22670002244', '+22670002244'],
            'prefixe 00' => ['0022670002244', '+22670002244'],
            'etranger deja international' => ['+2348012345678', '+2348012345678'],
        ];
    }

    #[DataProvider('formesComposables')]
    public function test_e164_rend_un_numero_composable(?string $brut, ?string $attendu): void
    {
        $this->assertSame($attendu, NumeroDeTelephone::e164($brut));
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function formesIllisibles(): array
    {
        return [
            'vide' => [''],
            'null' => [null],
            'lettres' => ['inconnu'],
            'trop court' => ['1234567'],
        ];
    }

    #[DataProvider('formesIllisibles')]
    public function test_e164_refuse_un_numero_incomposable(?string $brut): void
    {
        $this->assertNull(NumeroDeTelephone::e164($brut));
    }
}
