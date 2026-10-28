<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Accessory;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ContactSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'boutique.telephone' => '+22675570484',
            'boutique.whatsapp' => '22675570484',
            'app.url' => 'https://partage-contact.example',
        ]);
    }

    private function accessory(?PartnerAccount $seller = null): Accessory
    {
        return Accessory::create([
            'sku' => 'ACC-CONTACT-'.($seller?->id ?? 'AUTOPARC'),
            'name' => 'Camera de recul',
            'category' => 'securite',
            'selling_price' => 12_500,
            'currency' => 'XOF',
            'stock_quantity' => 2,
            'is_active' => true,
            'is_available' => true,
            'owner_partner_account_id' => $seller?->id,
            'partner_published_at' => $seller ? now() : null,
        ]);
    }

    public function test_public_contact_is_readable_without_authentication_and_has_the_initial_number(): void
    {
        $this->getJson('/api/app/contact')
            ->assertOk()
            ->assertExactJson(['phone' => '+22675570484'])
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_existing_environment_contacts_are_the_fallback_before_the_first_save(): void
    {
        config(['boutique.telephone' => '+33111111111', 'boutique.whatsapp' => '33222222222']);
        $article = $this->accessory();

        $this->getJson('/api/app/contact')
            ->assertOk()->assertExactJson(['phone' => '+33111111111']);
        $this->get('/a/'.$article->id)->assertOk()
            ->assertSee('href="tel:+33111111111"', false)
            ->assertSee('https://wa.me/33222222222?text=', false);
    }

    /**
     * L'API ne peut PAS emettre un numero que l'application refuse de lire.
     *
     * LE PIEGE QUE CE BANC FERME. `BOUTIQUE_WHATSAPP` porte par defaut
     * « 22675570484 », SANS le « + ». Poser sa voisine `BOUTIQUE_TELEPHONE`
     * dans la meme forme est donc l'erreur la plus naturelle du tableau de
     * bord — et avant ce correctif elle etait sans retour : l'API servait
     * « 22675570484 », `AutoParcContact.fromJson` levait cote mobile, et
     * l'ecran du personnel qui existe PRECISEMENT pour corriger le numero
     * affichait « Une erreur est survenue » avec un « Reessayer » incapable
     * d'aboutir. Aucun shell sur le plan gratuit pour en sortir.
     *
     * Le contrat tenu ici est donc minimal et absolu : quoi qu'on pose dans la
     * configuration, `GET api/app/contact` rend un numero composable.
     */
    #[DataProvider('illisibles')]
    public function test_the_public_contact_is_always_dialable_whatever_the_configuration(mixed $configure): void
    {
        config(['boutique.telephone' => $configure]);

        $this->getJson('/api/app/contact')
            ->assertOk()
            ->assertJsonPath('phone', '+22675570484');
    }

    public static function illisibles(): array
    {
        return [
            'sans le plus, comme BOUTIQUE_WHATSAPP' => ['22675570484'],
            'forme locale internationale' => ['0022675570484'],
            'huit chiffres nus' => ['75570484'],
            'vide' => [''],
            'absente' => [null],
            'du texte' => ['appelez-nous'],
        ];
    }

    /**
     * Les separateurs d'un numero enregistre ne changent pas ce qui est servi.
     *
     * `UpdateContactRequest` les retire deja a l'ecriture ; ce banc tient la
     * LECTURE, pour qu'une ligne ecrite autrement — une reprise de base, une
     * commande future — ne puisse pas fabriquer un `tel:` casse.
     */
    public function test_a_stored_number_with_separators_is_served_dialable(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'contact_phone'],
            ['value' => '+226 75 57 04 84'],
        );

        $this->getJson('/api/app/contact')
            ->assertOk()
            ->assertJsonPath('phone', '+22675570484');
    }

    /**
     * Une valeur enregistree vide retombe sur la configuration, pas sur rien.
     *
     * La colonne est NOT NULL, donc la base ne produit pas ce cas ; une
     * reprise ou une commande future, si. Et ce depot a deja paye la chaine
     * vide ailleurs : `PhotoPartage::cheminExploitable` la rejette parce
     * qu'elle avait fabrique un lien casse. Un `tel:` sans numero est un
     * bouton d'appel qui ne fait rien.
     */
    public function test_an_empty_stored_number_falls_back_instead_of_serving_nothing(): void
    {
        config(['boutique.telephone' => '+22676000000']);
        AppSetting::query()->updateOrCreate(['key' => 'contact_phone'], ['value' => '']);

        $this->getJson('/api/app/contact')
            ->assertOk()
            ->assertJsonPath('phone', '+22676000000');
    }

    public function test_back_office_contact_update_reaches_the_shared_web_page(): void
    {
        $article = $this->accessory();
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        $this->putJson('/api/settings/contact', ['phone' => '+226 (70) 12-34.56'])->assertOk();
        $this->get('/a/'.$article->id)->assertOk()
            ->assertSee('href="tel:+22670123456"', false)
            ->assertSee('https://wa.me/22670123456?text=', false)
            ->assertDontSee('75570484');
    }

    public function test_back_office_contact_update_preserves_the_partner_seller_contacts(): void
    {
        $partner = PartnerAccount::create([
            'user_id' => User::factory()->create(['role' => 'partner'])->id,
            'company_name' => 'Garage partenaire',
            'contact_name' => 'Contact partenaire',
            'phone' => '+22670002244',
            'whatsapp' => '+22671002244',
            'status' => 'approved',
        ]);
        $article = $this->accessory($partner);
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        $this->putJson('/api/settings/contact', ['phone' => '+33123456789'])->assertOk();
        $this->get('/a/'.$article->id)->assertOk()
            ->assertSee('href="tel:+22670002244"', false)
            ->assertSee('https://wa.me/22671002244?text=', false)
            ->assertDontSee('33123456789');
    }

    public static function allowedRoles(): array
    {
        return [['admin'], ['manager']];
    }

    #[DataProvider('allowedRoles')]
    public function test_management_can_change_the_contact_used_by_public_reads(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');
        AppSetting::create(['key' => 'another_setting', 'value' => 'not public']);

        $this->getJson('/api/settings/contact')
            ->assertOk()->assertExactJson(['phone' => '+22675570484']);

        $this->putJson('/api/settings/contact', ['phone' => '+226 (70) 12-34.56'])
            ->assertOk()->assertExactJson(['phone' => '+22670123456']);

        $this->assertDatabaseHas('app_settings', ['key' => 'contact_phone', 'value' => '+22670123456']);
        $this->getJson('/api/app/contact')
            ->assertOk()->assertExactJson(['phone' => '+22670123456']);

        // A second update replaces the setting, without leaving duplicate rows
        // or a cached old number for the next visitor.
        $this->putJson('/api/settings/contact', ['phone' => '+33123456789'])
            ->assertOk()->assertExactJson(['phone' => '+33123456789']);
        $this->getJson('/api/settings/contact')
            ->assertOk()->assertExactJson(['phone' => '+33123456789']);
        $this->getJson('/api/app/contact')
            ->assertOk()->assertExactJson(['phone' => '+33123456789']);
        $this->assertDatabaseCount('app_settings', 2);
    }

    public static function deniedRoles(): array
    {
        return [['sales'], ['mechanic'], ['warehouse'], ['viewer'], ['client'], ['partner']];
    }

    #[DataProvider('deniedRoles')]
    public function test_other_roles_cannot_access_contact_settings(string $role): void
    {
        $this->actingAs(User::factory()->create(['role' => $role]), 'sanctum');

        $this->getJson('/api/settings/contact')->assertForbidden();
        $this->putJson('/api/settings/contact', ['phone' => '+33123456789'])->assertForbidden();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_a_guest_cannot_read_or_update_back_office_contact_settings(): void
    {
        $this->getJson('/api/settings/contact')->assertUnauthorized();
        $this->putJson('/api/settings/contact', ['phone' => '+33123456789'])->assertUnauthorized();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public function test_an_inactive_administrator_cannot_change_the_contact(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin', 'is_active' => false]), 'sanctum');

        $this->getJson('/api/settings/contact')->assertForbidden();
        $this->putJson('/api/settings/contact', ['phone' => '+33123456789'])->assertForbidden();
        $this->assertDatabaseCount('app_settings', 0);
    }

    public static function invalidNumbers(): array
    {
        return [
            'empty' => [''],
            'null' => [null],
            'local number' => ['75570484'],
            'no plus' => ['22675570484'],
            '00 prefix' => ['0022675570484'],
            'short' => ['+1234567'],
            'long' => ['+1234567890123456'],
            'zero country' => ['+012345678'],
            'extension' => ['+22675570484 ext 1'],
            'letters' => ['+226ABC12345'],
            'url' => ['https://wa.me/22675570484'],
            'integer' => [22675570484],
            'array' => [['+22675570484']],
        ];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_numbers_are_rejected_without_replacing_the_contact(mixed $phone): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');
        AppSetting::create(['key' => 'contact_phone', 'value' => '+22675570484']);

        $this->putJson('/api/settings/contact', ['phone' => $phone])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseHas('app_settings', ['key' => 'contact_phone', 'value' => '+22675570484']);
    }

    public function test_phone_is_required_and_cannot_be_replaced_by_an_arbitrary_setting(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']), 'sanctum');

        $this->putJson('/api/settings/contact', ['key' => 'other_setting', 'value' => 'value'])
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->assertDatabaseCount('app_settings', 0);
    }
}
