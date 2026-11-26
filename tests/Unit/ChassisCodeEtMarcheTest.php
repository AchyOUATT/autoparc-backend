<?php

namespace Tests\Unit;

use App\Support\ChassisCode;
use App\Support\Market;
use PHPUnit\Framework\TestCase;

class ChassisCodeEtMarcheTest extends TestCase
{
    public function test_le_code_de_base_retire_suffixe_et_lettres_finales(): void
    {
        $this->assertSame('ACV30', ChassisCode::base('ACV30L-AEPNKA'));
        $this->assertSame('ACV30L', ChassisCode::beforeDash('ACV30L-AEPNKA'));
        $this->assertSame('SXV20', ChassisCode::base('SXV20W'));
        $this->assertSame('NZE121', ChassisCode::base('NZE121'));
    }

    public function test_une_liste_saisie_est_decoupee_et_normalisee(): void
    {
        $this->assertSame(['TRN280', 'TRN285'], ChassisCode::parseList(' trn280 / TRN285 '));
        $this->assertSame(['MCV30', 'MCV36'], ChassisCode::parseList('MCV30, MCV36'));
        $this->assertSame([], ChassisCode::parseList(null));
    }

    public function test_variantes_distinctes_et_familles_declarees(): void
    {
        // TRN280 et TRN285 sont deux codes : l'un ne vaut pas l'autre.
        $this->assertSame(ChassisCode::MATCH, ChassisCode::compare(['TRN280', 'TRN285'], 'TRN285'));
        $this->assertSame(ChassisCode::MISMATCH, ChassisCode::compare(['TRN280'], 'TRN285'));
        // Code complet de la plaque constructeur ramené à sa base.
        $this->assertSame(ChassisCode::MATCH, ChassisCode::compare(['ACV30'], 'ACV30L-AEPNKA'));
        // Famille seulement si la règle la déclare.
        $this->assertSame(ChassisCode::MATCH, ChassisCode::compare(['MCV3*'], 'MCV36'));
        $this->assertSame(ChassisCode::MISMATCH, ChassisCode::compare(['MCV3*'], 'VCV10'));
        $this->assertSame(ChassisCode::MISMATCH, ChassisCode::compare(['MCV30'], 'MCV36'));
        // Véhicule connu moins précisément que la règle.
        $this->assertSame(ChassisCode::AMBIGUOUS, ChassisCode::compare(['ACV30L'], 'ACV30'));
        $this->assertSame(ChassisCode::MATCH, ChassisCode::compare(['ACV30L'], 'ACV30L-AEPNKA'));
        $this->assertSame(ChassisCode::MISMATCH, ChassisCode::compare(['ACV30L'], 'ACV30R'));
    }

    public function test_le_chassis_se_lit_sur_un_numero_japonais_jamais_sur_un_vin(): void
    {
        $this->assertSame('NZE121', ChassisCode::fromFrameNumber('nze121-3123456'));
        $this->assertSame('AE101', ChassisCode::fromFrameNumber('AE101-1234567'));
        $this->assertNull(ChassisCode::fromFrameNumber('JTDBR32E720123456'));
        $this->assertNull(ChassisCode::fromFrameNumber(null));
    }

    public function test_marches_alias_et_hierarchie_epc(): void
    {
        $this->assertSame('USA', Market::normalize('us'));
        $this->assertSame('JPN', Market::normalize('JP'));
        $this->assertSame('EUR', Market::normalize('EU'));
        $this->assertSame('MDE', Market::normalize('GCC'));
        $this->assertNull(Market::normalize('XYZ'));
        $this->assertSame(['AFR', 'GEN'], Market::lineage('AFR'));

        $this->assertSame(Market::MATCH, Market::compare(['USA', 'CAN'], 'CAN'));
        $this->assertSame(Market::MATCH, Market::compare(['GEN'], 'AFR'));
        $this->assertSame(Market::AMBIGUOUS, Market::compare(['AFR'], 'GEN'));
        $this->assertSame(Market::MISMATCH, Market::compare(['USA'], 'EUR'));
        $this->assertSame(Market::MISMATCH, Market::compare(['AFR'], 'MDE'));
    }
}
