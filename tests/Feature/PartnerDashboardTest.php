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
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Le tableau de bord d'un partenaire, en un appel.
 *
 * Les décors suivent `PartnerCatalogTest` : mêmes comptes approuvés, mêmes
 * annonces. Ce que ce banc mesure surtout, c'est ce qui n'entre PAS dans un
 * chiffre — l'archivée, celle d'un autre partenaire, celle de la boutique,
 * le brouillon sans photo, la promotion d'hier — parce qu'un compteur qui
 * les inclurait passerait inaperçu tant que personne ne recompterait à la
 * main.
 */
class PartnerDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const VEHICLE_KEYS = ['total', 'published', 'drafts', 'without_photo', 'on_promotion', 'promotion_ending_soon'];
    private const STOCK_KEYS = [...self::VEHICLE_KEYS, 'out_of_stock', 'low_stock'];

    private PartnerAccount $account;
    private PartnerAccount $other;
    private array $vehicleIdentity;
    private PartCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        // Deux utilisateurs AVANT le premier compte partenaire, pour que
        // `users.id` et `partner_accounts.id` divergent. Sur des tables vides
        // l'utilisateur 1 possède le compte 1, et confondre les deux
        // identifiants — dans les annonces comme dans les notifications — ne
        // se verrait pas.
        User::factory()->count(2)->create(['role' => 'client']);
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

    /** Une annonce du type demandé : brouillon et sans photo par défaut. */
    private function listing(string $type, ?PartnerAccount $owner, bool $published = false, bool $photo = false, array $extra = []): Model
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
        $listing = $class::create(array_replace($data, [
            'owner_partner_account_id' => $owner?->id,
            'partner_published_at' => $published ? now() : null,
        ], $extra));
        if ($photo) {
            $listing->media()->create([
                'collection' => 'gallery', 'mime_type' => 'image/jpeg', 'disk' => 'public',
                'path' => $type.'/'.$listing->id.'/photo.jpg',
            ]);
        }

        return $listing;
    }

    /**
     * Une promotion commencée il y a `$depuis` jours (négatif : pas encore)
     * et qui finit dans `$fin` jours (négatif : déjà finie), avec le prix
     * normal sans lequel la carte ne la montrerait pas.
     */
    private function promotion(string $type, int $fin, int $depuis = 1): array
    {
        return ($type === 'vehicles' ? ['sale_price' => 5_000_000, 'deal_price' => 4_000_000] : ['deal_price' => 4000]) + [
            'deal_starts_at' => now()->subDays($depuis),
            'deal_ends_at' => now()->addDays($fin),
        ];
    }

    private function notify(string $recipientType, string $recipientId, bool $read = false): AppNotification
    {
        return AppNotification::create([
            'recipient_type' => $recipientType,
            'recipient_id' => $recipientId,
            'type' => 'need_broadcast',
            'title' => 'Besoin client',
            'body' => 'Un client cherche un filtre à huile.',
            'read_at' => $read ? now() : null,
        ]);
    }

    /** Les annonces d'un AUTRE partenaire et de la boutique, de quoi salir chaque compteur si la propriété lâchait. */
    private function listingsOfOthers(): void
    {
        foreach (['vehicles', 'parts', 'accessories'] as $type) {
            $this->listing($type, $this->other, published: true, extra: $this->promotion($type, 2) + ['stock_quantity' => 0]);
            $this->listing($type, $this->other);
            $this->listing($type, null, published: true);
        }
    }

    private static function zeros(array $keys): array
    {
        return array_fill_keys($keys, 0);
    }

    public function test_les_compteurs_de_vehicules_ne_voient_que_les_annonces_vivantes_du_compte(): void
    {
        $this->assertNotSame($this->account->id, $this->account->user_id,
            'Le décor doit distinguer le compte de son utilisateur, sinon les confondre ne se verrait pas.');
        $mine = $this->account;

        $this->listing('vehicles', $mine, published: true, photo: true);
        $this->listing('vehicles', $mine, published: true);                                   // sans photo
        $this->listing('vehicles', $mine, published: true)->media()->create([                 // un document n'habille pas une annonce
            'collection' => 'documents', 'mime_type' => 'application/pdf', 'disk' => 'local', 'path' => 'vehicles/carte-grise.pdf',
        ]);
        $this->listing('vehicles', $mine);                                                    // brouillon sans photo : pas « sans photo »
        $this->listing('vehicles', $mine, published: true, photo: true, extra: ['status' => 'sold']);     // vendu : publié quand même
        $this->listing('vehicles', $mine, published: true, photo: true, extra: ['status' => 'archived']); // le STATUT archivé n'archive pas
        $this->listing('vehicles', $mine, published: true, photo: true)->delete();            // archivée : nulle part
        $this->listing('vehicles', $mine, published: true, photo: true, extra: $this->promotion('vehicles', 2));      // finit dans 2 jours
        $this->listing('vehicles', $mine, published: true, photo: true, extra: $this->promotion('vehicles', 1));      // finit demain
        $this->listing('vehicles', $mine, published: true, photo: true, extra: $this->promotion('vehicles', 10));     // en cours, pas pressée
        $this->listing('vehicles', $mine, published: true, photo: true, extra: $this->promotion('vehicles', -1, 10)); // finie hier
        $this->listing('vehicles', $mine, published: true, photo: true, extra: $this->promotion('vehicles', 10, -1)); // commence demain
        $this->listing('vehicles', $mine, published: true, photo: true, extra: ['sale_price' => null] + $this->promotion('vehicles', 2)); // sans prix normal : la carte ne la montre pas
        // Brouillon en promotion, AVEC photo : rien, nulle part. La photo n'est
        // pas décorative — sans elle, autant de brouillons que de publiées
        // seraient « sans photo », et compter les uns pour les autres ne se
        // verrait pas.
        $this->listing('vehicles', $mine, photo: true, extra: $this->promotion('vehicles', 2));
        $this->listingsOfOthers();

        Sanctum::actingAs($mine->user);

        $this->getJson('/api/partner/dashboard')->assertOk()->assertJson(['listings' => [
            'vehicles' => ['total' => 13, 'published' => 11, 'drafts' => 2, 'without_photo' => 2, 'on_promotion' => 3, 'promotion_ending_soon' => 2],
            'parts' => self::zeros(self::STOCK_KEYS),
            'accessories' => self::zeros(self::STOCK_KEYS),
        ]]);
    }

    public function test_les_compteurs_de_stock_ne_regardent_que_les_articles_publies(): void
    {
        $mine = $this->account;

        $this->listing('parts', $mine, published: true, photo: true, extra: ['stock_quantity' => 10, 'stock_alert_threshold' => 2]); // saine
        $this->listing('parts', $mine, published: true, extra: ['stock_quantity' => 0]);                                            // épuisée, et sans photo
        $this->listing('parts', $mine, published: true, photo: true, extra: ['stock_quantity' => 5, 'is_available' => false]);      // retirée de la vente : épuisée aussi
        $this->listing('parts', $mine, published: true, photo: true, extra: ['stock_quantity' => 2, 'stock_alert_threshold' => 2]); // AU seuil : sous le seuil
        $this->listing('parts', $mine, published: true, photo: true, extra: ['stock_quantity' => 1, 'stock_alert_threshold' => 5]);
        $this->listing('parts', $mine, photo: true, extra: ['stock_quantity' => 0]);                                                // brouillon épuisé : rien (photo : voir le banc des véhicules)
        $this->listing('parts', $mine, published: true, photo: true, extra: ['stock_quantity' => 3] + $this->promotion('parts', 2));
        $this->listing('accessories', $mine, published: true, extra: ['stock_quantity' => 0]);
        $this->listing('accessories', $mine, published: true, photo: true, extra: ['stock_quantity' => 3, 'stock_alert_threshold' => 3]);
        $this->listing('accessories', $mine, photo: true);
        $this->listingsOfOthers();

        Sanctum::actingAs($mine->user);

        $this->getJson('/api/partner/dashboard')->assertOk()->assertJson(['listings' => [
            'vehicles' => self::zeros(self::VEHICLE_KEYS),
            'parts' => ['total' => 7, 'published' => 6, 'drafts' => 1, 'without_photo' => 1, 'on_promotion' => 1, 'promotion_ending_soon' => 1, 'out_of_stock' => 2, 'low_stock' => 2],
            'accessories' => ['total' => 3, 'published' => 2, 'drafts' => 1, 'without_photo' => 1, 'on_promotion' => 0, 'promotion_ending_soon' => 0, 'out_of_stock' => 1, 'low_stock' => 1],
        ]]);
    }

    /**
     * La forme est figée clé par clé, sans `assertExactJson` : un champ
     * ajouté ailleurs dans la réponse ne doit pas casser ce banc, un champ
     * retiré ou renommé ICI doit le casser.
     */
    public function test_la_forme_de_la_reponse_est_figee_cle_par_cle(): void
    {
        Sanctum::actingAs($this->account->user);

        $json = $this->getJson('/api/partner/dashboard')->assertOk()->json();

        $this->assertSame(['listings', 'unread_notifications'], array_keys($json));
        $this->assertSame(['vehicles', 'parts', 'accessories'], array_keys($json['listings']));
        $this->assertSame(self::VEHICLE_KEYS, array_keys($json['listings']['vehicles']));
        $this->assertSame(self::STOCK_KEYS, array_keys($json['listings']['parts']));
        $this->assertSame(self::STOCK_KEYS, array_keys($json['listings']['accessories']));
        foreach ($json['listings'] as $type => $counters) {
            foreach ($counters as $key => $value) {
                $this->assertIsInt($value, "$type.$key doit être un entier, pas une chaîne venue du SGBD.");
            }
        }
        $this->assertIsInt($json['unread_notifications']);
    }

    /**
     * Le prix de l'appel est le contrat : une requête d'agrégat par type et
     * une pour la boîte, plus celle du middleware qui relit le compte. Un
     * compteur de plus qui coûterait une requête de plus se verrait ici — et
     * c'est le moment d'en discuter, pas après le déploiement.
     */
    public function test_le_tableau_de_bord_coute_quatre_requetes_plus_celle_du_middleware(): void
    {
        $this->listing('vehicles', $this->account, published: true, photo: true, extra: $this->promotion('vehicles', 2));
        $this->listing('parts', $this->account, published: true, extra: ['stock_quantity' => 0]);
        Sanctum::actingAs($this->account->user);

        DB::enableQueryLog();
        $this->getJson('/api/partner/dashboard')->assertOk();
        $sql = array_column(DB::getQueryLog(), 'query');

        $this->assertCount(5, $sql, "Requêtes émises :\n".implode("\n", $sql));
    }

    public function test_les_non_lues_sont_celles_de_ce_partenaire_et_le_meme_compte_que_sa_boite(): void
    {
        $mine = $this->account->user;
        $this->notify('partner', (string) $mine->id);
        $this->notify('partner', (string) $mine->id);
        $this->notify('partner', (string) $mine->id, read: true);
        $this->notify('partner', (string) $this->other->user->id);
        // Le personnel a sa propre boîte : un identifiant d'utilisateur égal n'y
        // change rien, et c'est `recipient_type` qui doit trancher.
        $this->notify('staff', (string) $mine->id);
        // Une boîte est désignée par l'UTILISATEUR, jamais par le compte : une
        // ligne qui porterait l'identifiant du compte n'est adressée à personne.
        $this->notify('partner', (string) $this->account->id);

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
