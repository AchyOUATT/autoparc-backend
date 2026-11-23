<?php

namespace Tests\Unit;

use App\Support\VarianteNomNrcan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VarianteNomNrcanTest extends TestCase
{
    /** Noms réels de Ressources naturelles Canada, relevés en production. */
    public static function noms(): array
    {
        return [
            // nom, carburant, motricité, suralimentation, désactivation
            'CX-5 muet' => ['CX-5', 'X', null, null, null],
            'CX-5 4WD' => ['CX-5 4WD', 'X', '4WD', null, null],
            'CX-5 CD' => ['CX-5 (Cylinder Deactivation)', 'X', null, null, true],
            'CX-5 4WD CD' => ['CX-5 4WD (Cylinder Deactivation)', 'X', '4WD', null, true],
            'CX-5 Turbo 4WD' => ['CX-5 Turbo 4WD', 'X', '4WD', 'turbo', null],
            'CX-5 Diesel 4WD' => ['CX-5 Diesel 4WD', 'D', '4WD', null, null],
            'Mazda3 CD' => ['Mazda3 4-Door (Cylinder Deactivation)', 'X', null, null, true],
            'Ram MDS' => ['Ram 1500 4X4 (MDS)', 'X', '4WD', null, true],
            'Ram FuelSaver MDS' => ['300 AWD (FuelSaver MDS)', 'X', 'AWD', null, true],
            'Silverado sans DFM' => ['Silverado 4WD (Without DFM)', 'X', '4WD', null, false],
            'Sierra No DFM' => ['Sierra 4WD Mud Terrain Tire (No DFM)', 'X', '4WD', null, false],
            'Silverado sans AFM' => ['Silverado (Without AFM)', 'X', null, null, false],
            'BMW xDrive' => ['X7 xDrive50i', 'Z', 'AWD', null, null],
            'BMW sDrive' => ['Z4 sDrive28i', 'Z', null, null, null],
            'Audi quattro' => ['TTS Roadster quattro', 'Z', 'AWD', null, null],
            'Mercedes 4MATIC+' => ['AMG CLA 45 S 4MATIC+ Coupe', 'Z', 'AWD', null, null],
            'VW 4MOTION' => ['Tiguan R-Line 4MOTION', 'X', 'AWD', null, null],
            'MINI ALL4' => ['Cooper Clubman ALL4', 'Z', 'AWD', null, null],
            'Acura SH-AWD' => ['MDX SH-AWD', 'Z', 'AWD', null, null],
            'Yukon 4X4 FFV' => ['Yukon XL 4X4 FFV', 'X', '4WD', null, null],
            'Ford EcoBoost' => ['Explorer AWD (EcoBoost)', 'X', 'AWD', 'turbo', null],
            'Volvo AWD Turbo' => ['XC90 T6 AWD Turbo', 'Z', 'AWD', 'turbo', null],
            'Mercedes Turbodiesel' => ['E 300 Turbodiesel', 'D', null, 'turbo', null],
            'VW TDI' => ['Touareg TDI (modified)', 'D', null, 'turbo', null],
            'Range Rover compresseur' => ['Range Rover 5.0 Supercharged', 'Z', null, 'supercharged', null],
            'Mercedes Kompressor' => ['SLK 230 Kompressor', 'Z', null, 'supercharged', null],
            'Taycan électrique' => ['Taycan Turbo', 'B', null, null, null],
            'Golf muet' => ['Golf', 'X', null, null, null],
            'GT n est pas un turbo' => ['Vibe GT #', 'X', null, null, null],
            'Tacoma 2WD' => ['Tacoma 2WD', 'X', '2WD', null, null],
            'Mentions contradictoires' => ['Pickup 4WD 2WD', 'X', null, null, null],
        ];
    }

    #[DataProvider('noms')]
    public function test_le_nom_est_lu_sans_rien_deduire(string $nom, ?string $carburant, ?string $motricite, ?string $suralimentation, ?bool $desactivation): void
    {
        $this->assertSame([
            'drivetrain_code' => $motricite,
            'aspiration' => $suralimentation,
            'cylinder_deactivation' => $desactivation,
        ], VarianteNomNrcan::lire($nom, $carburant));
    }

    public function test_4wd_et_awd_sont_une_meme_famille_et_traction_et_propulsion_se_contredisent(): void
    {
        $this->assertTrue(VarianteNomNrcan::motricitesCompatibles('AWD', '4WD'));
        $this->assertTrue(VarianteNomNrcan::motricitesCompatibles('awd', 'AWD'));
        $this->assertTrue(VarianteNomNrcan::motricitesCompatibles('FWD', '2WD'));
        $this->assertTrue(VarianteNomNrcan::motricitesCompatibles('2WD', 'RWD'));
        $this->assertFalse(VarianteNomNrcan::motricitesCompatibles('FWD', 'RWD'));
        $this->assertFalse(VarianteNomNrcan::motricitesCompatibles('FWD', '4WD'));
        $this->assertFalse(VarianteNomNrcan::motricitesCompatibles('2WD', 'AWD'));
    }
}
