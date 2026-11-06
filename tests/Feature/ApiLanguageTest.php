<?php

namespace Tests\Feature;

use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleCheckAnswer;
use Database\Seeders\VehicleCheckItemsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLanguageTest extends TestCase
{
    use RefreshDatabase;

    private function garage(): OwnedVehicle
    {
        $user = User::factory()->create(['role' => 'client']);
        $this->actingAsClient($user);

        return OwnedVehicle::factory()->create([
            'user_id' => $user->id,
            'nickname' => 'Ma voiture à moi',
            'insurance_expiry' => now()->addDays(5),
            'mileage_km' => 65000,
            'last_service_mileage_km' => 60000,
            'service_interval_km' => 10000,
        ]);
    }

    public function test_garage_deadlines_negotiate_english_without_changing_vehicle_data_or_codes(): void
    {
        $vehicle = $this->garage();
        $path = '/api/my/vehicles/'.$vehicle->id;
        $french = $this->getJson($path)->assertOk()->assertHeader('Content-Language', 'fr')->json('data');
        $english = $this->withHeader('Accept-Language', 'en-GB,en;q=0.9,fr;q=0.7')->getJson($path)
            ->assertOk()->assertHeader('Content-Language', 'en')->json('data');

        $this->assertSame(['Assurance', 'Vidange'], array_column($french['deadlines'], 'label'));
        $this->assertSame(['Insurance', 'Oil change'], array_column($english['deadlines'], 'label'));
        $this->assertSame('In 5000 km', $english['deadlines'][1]['detail']);
        $this->assertSame(array_column($french['deadlines'], 'kind'), array_column($english['deadlines'], 'kind'));
        $this->assertSame($french['fuel_tracking'], $english['fuel_tracking']);
        $this->assertSame('Ma voiture à moi', $english['nickname']);
        $this->assertSame($french['mileage_km'], $english['mileage_km']);
        $this->assertSame($french['insurance_expiry'], $english['insurance_expiry']);
    }

    public function test_checklist_titles_help_reasons_and_categories_follow_the_request_language(): void
    {
        $this->seed(VehicleCheckItemsSeeder::class);
        $vehicle = $this->garage();
        $path = '/api/my/vehicles/'.$vehicle->id.'/check-template?trip_distance_km=400';
        $fr = $this->withHeader('Accept-Language', 'fr')->getJson($path)->assertOk()->json('data');
        $en = $this->withHeader('Accept-Language', 'en')->getJson($path)->assertOk()->json('data');

        $this->assertSame('Avant de partir', $fr['title']);
        $this->assertSame('Before departure', $en['title']);
        $this->assertSame('Before a trip', $en['available_reasons'][0]['label']);
        $french = collect($fr['items'])->firstWhere('code', 'papiers-assurance');
        $english = collect($en['items'])->firstWhere('code', 'papiers-assurance');
        $this->assertSame("Attestation d'assurance valide", $french['title']);
        $this->assertSame('Valid insurance certificate', $english['title']);
        $this->assertSame('Documents', $english['category_label']);
        $this->assertStringStartsWith('Check the windscreen', $english['help']);
        $this->assertSame(array_column($fr['items'], 'code'), array_column($en['items'], 'code'));
        $this->assertSame($french['severity'], $english['severity']);
    }

    public function test_saved_check_snapshot_and_free_notes_are_preserved_when_read_in_english(): void
    {
        $this->seed(VehicleCheckItemsSeeder::class);
        $vehicle = $this->garage();
        $data = $this->withHeader('Accept-Language', 'en')->postJson('/api/my/vehicles/'.$vehicle->id.'/checks', [
            'note' => 'Mon texte libre reste français.',
            'answers' => [['item_code' => 'papiers-assurance', 'status' => 'bad', 'note' => 'À remplacer demain.']],
        ])->assertCreated()->json('data');

        $this->assertSame('blocked', $data['verdict']['value']);
        $this->assertSame('Address before departure', $data['verdict']['label']);
        $this->assertSame('1 blocking issue', $data['verdict']['detail']);
        $this->assertSame('Valid insurance certificate', $data['answers'][0]['title']);
        $this->assertSame('Valid insurance certificate', $data['to_fix'][0]['title']);
        $this->assertSame('Documents', $data['to_fix'][0]['category_label']);
        $this->assertSame('À remplacer demain.', $data['answers'][0]['note']);
        $this->assertSame('Mon texte libre reste français.', $data['note']);
        $this->assertSame("Attestation d'assurance valide", VehicleCheckAnswer::firstOrFail()->title);

        $this->withHeader('Accept-Language', 'fr')->getJson('/api/my/checks/'.$data['id'])
            ->assertOk()->assertJsonPath('data.verdict.label', 'À régler avant de partir')
            ->assertJsonPath('data.answers.0.title', "Attestation d'assurance valide");
    }

    /**
     * La pastille de mise en avant suit la langue de la requete.
     *
     * L'application affiche `deal_label` tel que le serveur le rend, sans le
     * traduire. Tant que l'enum le rendait en dur, la section « Good deals »
     * d'un accueil anglais etait remplie de pastilles « Bonne affaire ».
     *
     * Les trois cas sont eprouves, et pas seulement le premier : un `match`
     * dont une seule branche passerait par `__()` laisserait les deux autres
     * en francais sans qu'un banc le voie. Et l'absence d'en-tete — le binaire
     * deja installe — doit rendre exactement le texte d'avant.
     */
    public function test_the_deal_badge_follows_the_request_language(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        $attendus = [
            'good_deal' => ['Bonne affaire', 'Good deal'],
            'flash_sale' => ['Vente flash', 'Flash sale'],
            'clearance' => ['Déstockage', 'Clearance'],
        ];

        foreach ($attendus as $type => [$francais, $anglais]) {
            $id = \App\Models\Vehicle::factory()->create(['deal_type' => $type])->id;
            $chemin = '/api/catalog/vehicles/'.$id;

            $this->withHeader('Accept-Language', 'en')->getJson($chemin)
                ->assertOk()->assertJsonPath('data.commercial.deal_label', $anglais);
            $this->withHeader('Accept-Language', 'fr')->getJson($chemin)
                ->assertOk()->assertJsonPath('data.commercial.deal_label', $francais);
            $this->withHeader('Accept-Language', '')->getJson($chemin)
                ->assertOk()->assertJsonPath('data.commercial.deal_label', $francais);
        }
    }

    public function test_an_unsupported_or_absent_language_defaults_to_french_after_an_english_request(): void
    {
        $vehicle = $this->garage();
        $path = '/api/my/vehicles/'.$vehicle->id;
        $this->withHeader('Accept-Language', 'en')->getJson($path)->assertHeader('Content-Language', 'en');
        $this->withHeader('Accept-Language', 'de-DE')->getJson($path)
            ->assertHeader('Content-Language', 'fr')->assertJsonPath('data.deadlines.0.label', 'Assurance');
        $this->withHeader('Accept-Language', '')->getJson($path)
            ->assertHeader('Content-Language', 'fr')->assertJsonPath('data.deadlines.0.label', 'Assurance');
    }
}
