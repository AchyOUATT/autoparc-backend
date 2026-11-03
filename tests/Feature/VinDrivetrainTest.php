<?php

namespace Tests\Feature;

use App\Models\Drivetrain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VinDrivetrainTest extends TestCase
{
    use RefreshDatabase;

    private const VIN = 'JM1BM1L78E1161371';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        // AWD deliberately precedes 4WD: the former fuzzy lookup selected
        // this first ID even when NHTSA explicitly described a 4WD vehicle.
        foreach ([
            'AWD' => 'Intégrale permanente',
            '4WD' => '4x4 débrayable',
            'FWD' => 'Traction avant',
            'RWD' => 'Propulsion arrière',
        ] as $code => $label) {
            Drivetrain::create(compact('code', 'label'));
        }
    }

    private function source(?string $driveType): void
    {
        Http::fake(['vpic.nhtsa.dot.gov/*' => Http::response([
            'Results' => [['ModelYear' => '2014', 'DriveType' => $driveType, 'ErrorCode' => '0']],
        ])]);
    }

    public static function explicitDrivetrains(): array
    {
        return [
            ['FWD/Front-Wheel Drive', 'FWD'],
            ['RWD/Rear-Wheel Drive', 'RWD'],
            ['AWD/All-Wheel Drive', 'AWD'],
            ['4WD/4-Wheel Drive/4x4', '4WD'],
            ['4WD/4-Wheel Drive', '4WD'],
            ['  4wd / four-wheel drive / 4×4  ', '4WD'],
            ['4x4', '4WD'],
            ['All Wheel Drive', 'AWD'],
            ['Front Wheel Drive', 'FWD'],
            ['Rear Wheel Drive', 'RWD'],
        ];
    }

    #[DataProvider('explicitDrivetrains')]
    public function test_le_vin_resout_le_code_precis_sans_confondre_awd_et_4wd(string $source, string $code): void
    {
        $this->source($source);
        $expected = Drivetrain::where('code', $code)->firstOrFail();

        $this->getJson('/api/catalog/vin-decode/'.self::VIN)->assertOk()
            ->assertJsonPath('nhtsa.drive_type', $source)
            ->assertJsonPath('drivetrain.id', $expected->id)
            ->assertJsonPath('drivetrain.code', $code)
            ->assertJsonPath('drivetrain.label', $expected->label);
        Http::assertSentCount(1);
    }

    public static function ambiguousDrivetrains(): array
    {
        return [['4x2'], ['2WD'], ['6x4'], ['AWD/4WD'], ['4x4/AWD'], ['AWD/Unknown'], ['awd-custom'], [''], [null]];
    }

    #[DataProvider('ambiguousDrivetrains')]
    public function test_un_type_ambigu_ou_inconnu_ne_choisit_aucune_transmission(?string $source): void
    {
        $this->source($source);
        $this->getJson('/api/catalog/vin-decode/'.self::VIN)->assertOk()
            ->assertJsonPath('nhtsa.drive_type', $source)
            ->assertJsonPath('drivetrain', null);
    }

    public function test_un_libelle_ou_code_personnalise_ne_remplace_pas_un_code_canonique_absent(): void
    {
        Drivetrain::query()->delete();
        Drivetrain::create(['code' => 'custom-4wd', 'label' => '4x4 intégrale']);
        $this->source('4WD/4-Wheel Drive/4x4');

        $this->getJson('/api/catalog/vin-decode/'.self::VIN)->assertOk()
            ->assertJsonPath('drivetrain', null);
    }
}
