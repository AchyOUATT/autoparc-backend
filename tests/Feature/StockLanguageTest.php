<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AccessoryOrderController;
use App\Models\Accessory;
use App\Models\AccessoryOrder;
use App\Models\AccessoryOrderItem;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class StockLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_stock_refusal_localizes_the_template_and_preserves_the_product_name_and_stock(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $product = Accessory::factory()->create(['sku' => null, 'name' => "Tapis d'être à moi", 'stock_quantity' => 1]);
        foreach (['fr', 'en'] as $locale) {
            $expected = $locale === 'fr'
                ? "Stock insuffisant pour Tapis d'être à moi : 1 disponible(s), 3 demande(s)."
                : "Insufficient stock for Tapis d'être à moi: 1 available, 3 requested.";
            $this->withHeader('Accept-Language', $locale)->postJson("/api/accessories/{$product->id}/stock", [
                'operation' => 'decrease', 'quantity' => 3,
            ])->assertStatus(422)->assertJsonPath('message', $expected);
            $this->assertSame(1, $product->fresh()->stock_quantity);
        }
    }

    public function test_order_confirmation_uses_the_same_localized_stock_template(): void
    {
        $product = Accessory::factory()->create(['sku' => null, 'name' => 'Tapis personnel', 'stock_quantity' => 1]);
        $item = new AccessoryOrderItem(['quantity' => 3]);
        $item->setRelation('accessory', $product);
        $order = new AccessoryOrder(['status' => 'draft']);
        $order->setRelation('items', new Collection([$item]));
        foreach (['fr', 'en'] as $locale) {
            app()->setLocale($locale);
            try {
                app(AccessoryOrderController::class)->confirm($order);
                $this->fail('An insufficient stock order must be rejected.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
                $this->assertSame($locale === 'fr'
                    ? 'Stock insuffisant pour Tapis personnel : 1 disponible(s), 3 demande(s).'
                    : 'Insufficient stock for Tapis personnel: 1 available, 3 requested.', $exception->getMessage());
            }
        }
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }

    public function test_partner_stock_boundaries_are_localized_without_updating_inventory(): void
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);
        $account = PartnerAccount::create([
            'user_id' => $user->id, 'company_name' => 'Entreprise personnelle',
            'contact_name' => 'Contact', 'phone' => $this->numeroDePartenaire($user->id), 'status' => 'approved',
        ]);
        Sanctum::actingAs($user);
        $product = Accessory::factory()->create(['stock_quantity' => 1]);
        $product->forceFill(['owner_partner_account_id' => $account->id])->save();
        foreach (['fr', 'en'] as $locale) {
            foreach ([['decrease', 3], ['increase', 2147483647]] as [$operation, $quantity]) {
                $expected = match ([$locale, $operation]) {
                    ['fr', 'decrease'] => 'Stock insuffisant pour cette sortie.',
                    ['fr', 'increase'] => 'La quantité de stock est trop élevée.',
                    ['en', 'decrease'] => 'Insufficient stock for this withdrawal.',
                    default => 'Stock quantity is too high.',
                };
                $this->withHeader('Accept-Language', $locale)
                    ->postJson("/api/partner/accessories/{$product->id}/stock", compact('operation', 'quantity'))
                    ->assertStatus(422)->assertJsonPath('errors.quantity.0', $expected);
            }
        }
        $this->assertSame(1, $product->fresh()->stock_quantity);
    }
}
