<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\AppNotification;
use App\Models\Brand;
use App\Models\EngineType;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le tableau de bord d'un partenaire, en un appel.
 *
 * Les décors suivent `PartnerCatalogTest` : mêmes comptes approuvés, mêmes
 * annonces. Ce que ce banc mesure surtout, c'est ce qui n'entre PAS dans les
 * chiffres — les annonces archivées, celles d'un autre partenaire, celles de
 * la boutique elle-même — parce qu'un compteur qui les inclurait passerait
 * inaperçu tant que personne ne recompterait à la main.
 */
class PartnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private PartnerAccount $account;
    private PartnerAccount $other;
    private array $vehicleIdentity;
    private PartCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        $this->account = $this->account('Mon entreprise');
        $this->other = $this->account('Autre entreprise');
        $brand = Brand::create(['name' => 'Toyota']);
        $model = VehicleModel::create(['brand_id' => $brand->id, 'name' => 'Corolla']);
        $engine = EngineType::create(['code' => 'petrol', 'label' => 'Essence']);
        $this->vehicleIdentity = [
            'brand_id' => $brand->id, 'vehicle_model_id' => $model->id,
            'engine_type_id' => $engine->id, 'manufacturing_year' => 2020,
        ];
        $this->category = PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres']);
    }

    private function account(string $company, string $status = 'approved'): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner']);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => $company, 'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id), 'status' => $status,
        ]);
    }

    /** Une annonce du type demandé : brouillon par défaut, publiée si on le dit. */
    private function listing(string $type, ?PartnerAccount $owner, bool $published = false): Model
    {
        $class = match ($type) {
            'vehicles' => Vehicle::class,
            'parts' => Part::class,
            'accessories' => Accessory::class,
        };
        $data = match ($type) {
            'vehicles' => $this->vehicleIdentity + [
                'availability' => 'rent', 'rental_monthly_rate' => 500_000,
                'reference' => fake()->unique()->bothify('PARTNER-########'),
            ],
            'parts' => [
                'name' => 'Filtre', 'part_category_id' => $this->category->id,
                'type' => 'aftermarket', 'condition' => 'new', 'selling_price' => 5000,
            ],
            'accessories' => ['name' => 'Support téléphone', 'category' => 'confort', 'selling_price' => 5000],
        };

        return $class::create($data + [
            'owner_partner_account_id' => $owner?->id,
            'partner_published_at' => $published ? now() : null,
        ]);
    }

    private function notify(string $recipientType, int $userId, bool $read = false): AppNotification
    {
        return AppNotification::create([
            'recipient_type' => $recipientType,
            'recipient_id' => (string) $userId,
            'type' => 'need_broadcast',
            'title' => 'Besoin client',
            'body' => 'Un client cherche un filtre à huile.',
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_les_compteurs_ne_comptent_que_les_annonces_vivantes_du_partenaire(): void
    {
        $this->listing('vehicles', $this->account, published: true);
        $this->listing('vehicles', $this->account, published: true);
        $this->listing('vehicles', $this->account);
        // Archivée alors qu'elle était publiée : elle ne doit entrer ni dans le
        // total ni dans les publiées, de quelque côté qu'on la range.
        $this->listing('vehicles', $this->account, published: true)->delete();
        $this->listing('parts', $this->account);

        // Les trois types d'un AUTRE partenaire, et des annonces de la boutique
        // elle-même (sans propriétaire) : aucune ne doit peser sur un chiffre.
        foreach (['vehicles', 'parts', 'accessories'] as $type) {
            $this->listing($type, $this->other, published: true);
            $this->listing($type, $this->other);
            $this->listing($type, null, published: true);
        }

        Sanctum::actingAs($this->account->user);

        $this->getJson('/api/partner/dashboard')->assertOk()->assertExactJson([
            'listings' => [
                'vehicles' => ['total' => 3, 'published' => 2, 'unpublished' => 1],
                'parts' => ['total' => 1, 'published' => 0, 'unpublished' => 1],
                'accessories' => ['total' => 0, 'published' => 0, 'unpublished' => 0],
            ],
            'unread_notifications' => 0,
        ]);
    }

    public function test_les_non_lues_sont_celles_de_ce_partenaire_et_le_meme_compte_que_sa_boite(): void
    {
        $mine = $this->account->user;
        $this->notify('partner', $mine->id);
        $this->notify('partner', $mine->id);
        $this->notify('partner', $mine->id, read: true);
        $this->notify('partner', $this->other->user->id);
        // Le personnel a sa propre boîte : un identifiant d'utilisateur égal n'y
        // change rien, et c'est `recipient_type` qui doit trancher.
        $this->notify('staff', $mine->id);

        Sanctum::actingAs($mine);

        $dashboard = $this->getJson('/api/partner/dashboard')->assertOk()
            ->assertJsonPath('unread_notifications', 2);
        // La pastille de la tuile et le compteur de la boîte doivent dire la
        // même chose : c'est la même requête, et ce test lui interdit de diverger.
        $this->assertSame(
            $this->getJson('/api/partner/notifications/unread-count')->assertOk()->json('count'),
            $dashboard->json('unread_notifications'),
        );
    }

    /** @return array<string, array{0: string}> */
    public static function profilsRefuses(): array
    {
        return [
            'compte partenaire en attente' => ['pending'],
            'personnel' => ['admin'],
            'client' => ['client'],
        ];
    }

    #[DataProvider('profilsRefuses')]
    public function test_seul_un_partenaire_approuve_lit_un_tableau_de_bord(string $profil): void
    {
        // Un compte en attente reçoit le MÊME 403 que sur l'inventaire : il
        // n'a rien à compter, et des zéros lui cacheraient pourquoi.
        $user = $profil === 'pending'
            ? $this->account('En attente', 'pending')->user
            : User::factory()->create(['role' => $profil]);
        Sanctum::actingAs($user);

        $this->getJson('/api/partner/dashboard')->assertForbidden();
    }

    public function test_sans_jeton_la_route_ne_repond_pas(): void
    {
        $this->getJson('/api/partner/dashboard')->assertUnauthorized();
    }

    public function test_l_espace_ferme_ferme_aussi_le_tableau_de_bord(): void
    {
        // On referme ce que phpunit.xml ouvre : le drapeau doit couvrir cette
        // route comme les quarante autres, sans quoi l'application publiée
        // lirait des chiffres d'un espace censé ne pas exister encore.
        config(['partenaires.espace_ouvert' => false]);
        Sanctum::actingAs($this->account->user);

        $this->getJson('/api/partner/dashboard')->assertStatus(503);
    }
}
