<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\User;
use App\Services\FcmService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use ReflectionProperty;
use Tests\TestCase;

class FcmLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_tokens_default_to_french_and_preserve_locale_when_absent_null_or_transferred(): void
    {
        $path = '/api/fcm-token/client';
        $payload = ['firebase_uid' => 'customer-1', 'fcm_token' => 'customer-device'];
        $this->postJson($path, $payload)->assertOk();
        $this->assertDatabaseHas('client_fcm_tokens', $payload + ['locale' => 'fr']);
        $this->postJson($path, $payload + ['locale' => 'en'])->assertOk();
        $this->postJson($path, $payload)->assertOk();
        $this->postJson($path, $payload + ['locale' => null])->assertOk();
        $this->assertDatabaseHas('client_fcm_tokens', $payload + ['locale' => 'en']);

        $this->postJson($path, ['firebase_uid' => 'customer-2', 'fcm_token' => 'customer-device'])->assertOk();
        $this->assertDatabaseCount('client_fcm_tokens', 1);
        $this->assertDatabaseHas('client_fcm_tokens', ['firebase_uid' => 'customer-2', 'locale' => 'en']);
        $this->postJson($path, $payload + ['locale' => 'de'])->assertUnprocessable()->assertJsonValidationErrors('locale');
        $this->assertDatabaseHas('client_fcm_tokens', ['firebase_uid' => 'customer-2', 'locale' => 'en']);
    }

    public function test_staff_tokens_default_to_french_and_preserve_locale_when_absent_or_null(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $this->actingAs($staff, 'sanctum');
        $this->postJson('/api/fcm-token', ['fcm_token' => 'staff-device'])->assertOk();
        $this->assertSame('fr', $staff->fresh()->fcm_locale);
        $this->postJson('/api/fcm-token', ['fcm_token' => 'staff-device', 'locale' => 'en'])->assertOk();
        $this->postJson('/api/fcm-token', ['fcm_token' => 'staff-device'])->assertOk();
        $this->postJson('/api/fcm-token', ['fcm_token' => 'staff-device', 'locale' => null])->assertOk();
        $this->assertSame('en', $staff->fresh()->fcm_locale);
        $this->postJson('/api/fcm-token', ['fcm_token' => 'staff-device', 'locale' => 'de'])->assertUnprocessable();
        $this->assertSame('en', $staff->fresh()->fcm_locale);
    }

    public function test_an_explicit_device_language_is_shared_across_customer_and_staff_registrations(): void
    {
        ClientFcmToken::upsertToken('customer-1', 'shared-device', 'en');
        $staff = User::factory()->create(['role' => 'admin', 'fcm_token' => 'shared-device', 'fcm_locale' => 'en']);
        $this->actingAs($staff, 'sanctum')->postJson('/api/fcm-token', ['fcm_token' => 'shared-device', 'locale' => 'fr'])->assertOk();
        $this->assertSame('fr', ClientFcmToken::firstOrFail()->locale);
        $this->postJson('/api/fcm-token/client', ['firebase_uid' => 'customer-1', 'fcm_token' => 'shared-device', 'locale' => 'en'])->assertOk();
        $this->assertSame('en', $staff->fresh()->fcm_locale);
    }

    public function test_rest_notifications_follow_language_without_modifying_stored_content_or_payload(): void
    {
        $payload = ['type' => 'new_need', 'need_id' => '42', 'description' => 'Texte libre inchangé.'];
        $notification = AppNotification::create([
            'recipient_type' => 'client', 'recipient_id' => 'firebase-uid-test', 'type' => 'new_need',
            'title' => 'Nouveau besoin client', 'body' => 'Type : Véhicule — Texte libre inchangé.', 'data' => $payload,
        ]);
        $this->actingAsClient()->withHeader('Accept-Language', 'en')->getJson('/api/my/notifications')
            ->assertOk()->assertJsonPath('data.0.title', 'New customer request')
            ->assertJsonPath('data.0.body', 'Type: Vehicle — Texte libre inchangé.')
            ->assertJsonPath('data.0.type', 'new_need')->assertJsonPath('data.0.data', $payload);
        $this->withHeader('Accept-Language', 'fr')->getJson('/api/my/notifications')
            ->assertOk()->assertJsonPath('data.0.title', 'Nouveau besoin client')
            ->assertJsonPath('data.0.body', 'Type : Véhicule — Texte libre inchangé.');
        $this->assertSame('Type : Véhicule — Texte libre inchangé.', $notification->fresh()->getRawOriginal('body'));
    }

    public function test_push_is_grouped_by_device_language_and_chunked_without_real_network_calls(): void
    {
        $rows = [];
        for ($i = 0; $i < 501; $i++) {
            $rows[] = ['firebase_uid' => 'customer', 'fcm_token' => 'en-'.$i, 'locale' => 'en', 'created_at' => now(), 'updated_at' => now()];
        }
        ClientFcmToken::insert($rows);
        ClientFcmToken::upsertToken('customer-fr', 'fr-device', 'fr');
        User::factory()->create(['role' => 'manager', 'fcm_token' => 'staff-en', 'fcm_locale' => 'en']);
        $deliveries = [];
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('sendMulticast')->times(3)->andReturnUsing(function ($message, $tokens) use (&$deliveries) {
            $deliveries[] = ['message' => $message->jsonSerialize(), 'tokens' => $tokens];

            return MulticastSendReport::withItems([]);
        });
        $service = new FcmService();
        (new ReflectionProperty(FcmService::class, 'messaging'))->setValue($service, $messaging);
        $tokens = [...array_column($rows, 'fcm_token'), 'staff-en', 'fr-device', 'unknown-device', 'en-0'];
        $service->sendToTokens($tokens, 'Un véhicule correspond à votre recherche', "Citroën C4 vient d'être ajouté au catalogue.", ['type' => 'vehicle_match', 'vehicle_id' => '42']);

        $seen = [];
        foreach ($deliveries as $delivery) {
            $this->assertLessThanOrEqual(500, count($delivery['tokens']));
            $english = str_starts_with($delivery['tokens'][0], 'en-') || $delivery['tokens'][0] === 'staff-en';
            $this->assertSame($english ? 'A vehicle matches your search' : 'Un véhicule correspond à votre recherche', $delivery['message']['notification']['title']);
            $this->assertSame($english ? 'Citroën C4 has been added to the catalogue.' : "Citroën C4 vient d'être ajouté au catalogue.", $delivery['message']['notification']['body']);
            $this->assertSame(['type' => 'vehicle_match', 'vehicle_id' => '42'], $delivery['message']['data']);
            array_push($seen, ...$delivery['tokens']);
        }
        $this->assertCount(504, $seen);
        $this->assertCount(504, array_unique($seen));
        $this->assertContains('staff-en', $seen);
        $this->assertContains('unknown-device', $seen);
    }

    public function test_single_token_push_keeps_manual_tips_literal_even_for_an_english_device(): void
    {
        ClientFcmToken::upsertToken('customer', 'en-device', 'en');
        $sent = null;
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')->once()->andReturnUsing(function ($message) use (&$sent) {
            $sent = $message->jsonSerialize();

            return ['name' => 'fake-message-id'];
        });
        $service = new FcmService();
        (new ReflectionProperty(FcmService::class, 'messaging'))->setValue($service, $messaging);
        $service->sendToToken('en-device', 'Nouveau besoin client', 'Type : Véhicule — Texte libre du personnel.', ['type' => 'tip']);
        $this->assertSame(['title' => 'Nouveau besoin client', 'body' => 'Type : Véhicule — Texte libre du personnel.'], $sent['notification']);
        $this->assertSame('en-device', $sent['token']);
    }
}
