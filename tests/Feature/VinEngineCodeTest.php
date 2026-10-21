<?php

namespace Tests\Feature;

use App\Support\EngineCode;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VinEngineCodeTest extends TestCase
{
    private const VIN = 'JM1BM1L78E1161371';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_une_panne_nhtsa_ne_transforme_pas_le_caractere_vds_en_code_moteur(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response([], 503)]);

        $response = $this->getJson('/api/catalog/vin-decode/'.self::VIN)
            ->assertOk()
            ->assertJsonPath('vin_engine_character', '7')
            ->assertJsonPath('engine_code', null)
            ->assertJsonPath('nhtsa', null);

        $this->assertStringContainsString('Code moteur constructeur non identifié', implode(' ', $response->json('warnings')));
        Http::assertSentCount(1);
    }

    public function test_un_code_nhtsa_reste_brut_sans_correspondance_constructeur_verifiee(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response([
            'Results' => [[
                'ModelYear' => '2014',
                'EngineCode' => '  PE-VPS  ',
                'ErrorCode' => '0',
            ]],
        ])]);

        $this->getJson('/api/catalog/vin-decode/'.self::VIN)
            ->assertOk()
            ->assertJsonPath('vin_engine_character', '7')
            ->assertJsonPath('engine_code', null)
            ->assertJsonPath('nhtsa.engine_code_nhtsa', '  PE-VPS  ');
    }

    public function test_le_caractere_zero_est_conserve_comme_information_brute(): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response([], 503)]);
        $vin = substr_replace(self::VIN, '0', 7, 1);

        $this->getJson('/api/catalog/vin-decode/'.$vin)
            ->assertOk()
            ->assertJsonPath('vin_engine_character', '0')
            ->assertJsonPath('engine_code', null);
    }

    public function test_un_vin_invalide_ne_fournit_aucune_identification_moteur(): void
    {
        $this->getJson('/api/catalog/vin-decode/INVALIDE')
            ->assertUnprocessable()
            ->assertJsonPath('valid', false)
            ->assertJsonPath('vin_engine_character', null)
            ->assertJsonPath('engine_code', null)
            ->assertJsonPath('nhtsa', null);

        Http::assertNothingSent();
    }

    public function test_les_anciens_caracteres_vds_ne_filtrent_plus_les_compatibilites(): void
    {
        $this->assertNull(EngineCode::forCompatibility(' 7 '));
        $this->assertNull(EngineCode::forCompatibility('0'));
        $this->assertNull(EngineCode::forCompatibility(null));
        $this->assertNull(EngineCode::forCompatibility('  '));
        $this->assertSame('2AR-FE', EngineCode::forCompatibility(' 2ar-fe '));
        $this->assertSame('7', EngineCode::normalize(' 7 '));
    }
}
