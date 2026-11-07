<?php

namespace Tests\Feature;

use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreeDesAnnoncesPartenaires;
use Tests\TestCase;

class PartnerRentalPriceUpdateTest extends TestCase
{
    use CreeDesAnnoncesPartenaires;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('partenaires.espace_ouvert', true);
        config()->set('partenaires.marge_pourcentage', 10);
        $this->compte = $this->compteApprouve();
    }

    public function test_rental_only_price_change_preserves_sale_and_promotion_proposals_at_their_original_rate(): void
    {
        $id = $this->creerVehicule([
            'availability' => 'both', 'sale_price' => 4_500_000,
            'deal_price' => 3_600_000, 'rental_daily_rate' => 25_000,
            'deal_starts_at' => now()->subDay()->toIso8601String(),
            'deal_ends_at' => now()->addDay()->toIso8601String(),
        ])->assertCreated()->json('data.id');
        $vehicle = Vehicle::findOrFail($id);
        // Existing listings predate per-field rates. Their original global
        // rate must remain attached to untouched prices during the upgrade.
        $detail = $vehicle->partner_pricing;
        unset($detail['taux_par_champ']);
        $vehicle->update(['partner_pricing' => $detail]);

        config()->set('partenaires.marge_pourcentage', 20);
        $response = $this->patchJson('/api/partner/vehicles/'.$id, $this->identity($vehicle) + [
            'rental_daily_rate' => 35_000,
        ])->assertOk();

        $vehicle->refresh();
        $this->assertSame('both', $vehicle->availability->value);
        $this->assertSame('4950000.00', $vehicle->sale_price);
        $this->assertSame('3960000.00', $vehicle->deal_price);
        $this->assertSame('42000.00', $vehicle->rental_daily_rate);
        $this->assertSame(4_500_000.0, (float) $vehicle->partner_pricing['prix_proposes']['sale_price']);
        $this->assertSame(3_600_000.0, (float) $vehicle->partner_pricing['prix_proposes']['deal_price']);
        $this->assertSame(35_000.0, (float) $vehicle->partner_pricing['prix_proposes']['rental_daily_rate']);
        $response->assertJsonPath('data.partner_pricing.taux', 10)
            ->assertJsonPath('data.partner_pricing.taux_par_champ.sale_price', 10)
            ->assertJsonPath('data.partner_pricing.taux_par_champ.deal_price', 10)
            ->assertJsonPath('data.partner_pricing.taux_par_champ.rental_daily_rate', 20)
            ->assertJsonPath('data.partner_pricing.total.sale_price', 4_950_000)
            ->assertJsonPath('data.partner_pricing.total.rental_daily_rate', 42_000);
    }

    public function test_hidden_weekly_and_monthly_prices_keep_their_values_and_calculation_metadata(): void
    {
        $id = $this->creerVehicule([
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
            'rental_weekly_rate' => 150_000, 'rental_monthly_rate' => 450_000,
        ])->assertCreated()->json('data.id');
        $vehicle = Vehicle::findOrFail($id);
        config()->set('partenaires.marge_pourcentage', 30);

        $this->patchJson('/api/partner/vehicles/'.$id, $this->identity($vehicle) + [
            'rental_daily_rate' => 30_000,
        ])->assertOk();

        $vehicle->refresh();
        $this->assertSame('39000.00', $vehicle->rental_daily_rate);
        $this->assertSame('165000.00', $vehicle->rental_weekly_rate);
        $this->assertSame('495000.00', $vehicle->rental_monthly_rate);
        $this->assertSame(150_000.0, (float) $vehicle->partner_pricing['prix_proposes']['rental_weekly_rate']);
        $this->assertSame(450_000.0, (float) $vehicle->partner_pricing['prix_proposes']['rental_monthly_rate']);
        $this->assertSame(10.0, (float) $vehicle->partner_pricing['taux_par_champ']['rental_weekly_rate']);
        $this->assertSame(10.0, (float) $vehicle->partner_pricing['taux_par_champ']['rental_monthly_rate']);
        $this->assertSame(30.0, (float) $vehicle->partner_pricing['taux_par_champ']['rental_daily_rate']);
    }

    public function test_explicit_null_removes_only_its_proposed_price_and_rate(): void
    {
        $id = $this->creerVehicule([
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
            'rental_weekly_rate' => 150_000, 'rental_monthly_rate' => 450_000,
        ])->assertCreated()->json('data.id');
        $vehicle = Vehicle::findOrFail($id);

        $this->patchJson('/api/partner/vehicles/'.$id, $this->identity($vehicle) + [
            'rental_monthly_rate' => null,
        ])->assertOk();

        $vehicle->refresh();
        $this->assertNull($vehicle->rental_monthly_rate);
        $this->assertSame('27500.00', $vehicle->rental_daily_rate);
        $this->assertSame('165000.00', $vehicle->rental_weekly_rate);
        $this->assertArrayNotHasKey('rental_monthly_rate', $vehicle->partner_pricing['prix_proposes']);
        $this->assertArrayNotHasKey('rental_monthly_rate', $vehicle->partner_pricing['taux_par_champ']);
        $this->assertSame(25_000.0, (float) $vehicle->partner_pricing['prix_proposes']['rental_daily_rate']);
        $this->assertSame(150_000.0, (float) $vehicle->partner_pricing['prix_proposes']['rental_weekly_rate']);
    }

    public function test_request_without_price_preserves_the_complete_stored_calculation(): void
    {
        $id = $this->creerVehicule([
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
            'rental_weekly_rate' => 150_000,
        ])->assertCreated()->json('data.id');
        $vehicle = Vehicle::findOrFail($id);
        $detail = $vehicle->partner_pricing;
        config()->set('partenaires.marge_pourcentage', 30);

        $this->patchJson('/api/partner/vehicles/'.$id, $this->identity($vehicle) + [
            'description' => 'Annonce de location corrigée.', 'rental_deposit' => 50_000,
        ])->assertOk();

        $vehicle->refresh();
        $this->assertSame($detail, $vehicle->partner_pricing);
        $this->assertSame('27500.00', $vehicle->rental_daily_rate);
        $this->assertSame('165000.00', $vehicle->rental_weekly_rate);
        $this->assertSame('50000.00', $vehicle->rental_deposit);
    }

    public function test_clearing_the_last_price_removes_stale_metadata(): void
    {
        $id = $this->creerVehicule([
            'availability' => 'rent', 'rental_daily_rate' => 25_000,
        ])->assertCreated()->json('data.id');
        $vehicle = Vehicle::findOrFail($id);

        $this->patchJson('/api/partner/vehicles/'.$id, $this->identity($vehicle) + [
            'availability' => 'none', 'rental_daily_rate' => null,
        ])->assertOk()->assertJsonPath('data.partner_pricing', null);

        $this->assertNull($vehicle->fresh()->partner_pricing);
        $this->assertNull($vehicle->fresh()->rental_daily_rate);
    }

    private function identity(Vehicle $vehicle): array
    {
        return [
            'brand_id' => $vehicle->brand_id,
            'vehicle_model_id' => $vehicle->vehicle_model_id,
            'engine_type_id' => $vehicle->engine_type_id,
            'manufacturing_year' => $vehicle->manufacturing_year,
        ];
    }
}
