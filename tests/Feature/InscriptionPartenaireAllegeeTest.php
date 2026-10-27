<?php

namespace Tests\Feature;

use App\Models\Accessory;
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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Un partenaire n'est pas forcement une entreprise.
 *
 * ── CE QU'EXIGEAIT L'INSCRIPTION ──────────────────────────────────────────
 *
 * `company_name` etait `required` et NOT NULL. Le vendeur type ici est un
 * particulier ou un petit revendeur sans raison sociale : il inventait un nom,
 * ou il abandonnait l'inscription. Les deux sont des pertes, et la seconde est
 * muette.
 *
 * ── CE QUE CE BANC TIENT, ET POURQUOI C'EST LE SERVEUR QUI LE TIENT ───────
 *
 * Que l'inscription passe sans nom d'entreprise, ET que la vitrine publique
 * n'en rende jamais ni null ni vide. Ce second point n'est pas une politesse
 * d'affichage. L'application PUBLIEE sur le Play Store lit ce champ par un
 * transtypage dur — `json['company_name'] as String`, dans `ProductSeller`
 * comme dans `PartnerSeller` — et compose l'initiale du vendeur par
 * `companyName[0]`. Un null y leve un `TypeError`, une chaine vide un
 * `RangeError`, et les deux font tomber l'ecran de detail de l'annonce :
 * celui-la meme qui porte le numero a appeler.
 *
 * Les telephones deja installes ne se mettent pas a jour parce qu'on l'aurait
 * souhaite. Le repli vit donc dans `PartnerListingPresentation::seller()`.
 */
class InscriptionPartenaireAllegeeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // L'espace partenaire est ferme par defaut (voir `config/partenaires.php`) :
        // sans cette ligne, `/api/partner/register` repond 503 et ce banc ne
        // mesurerait que la fermeture.
        config()->set('partenaires.espace_ouvert', true);
    }

    /** @param  array<string, mixed>  $champs */
    private function inscrire(array $champs = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/partner/register', array_merge([
            'contact_name' => 'Issa Ouedraogo',
            'phone' => '+22670000001',
            'email' => 'issa'.Str::random(6).'@example.test',
            'password' => 'motdepasse2026',
            'password_confirmation' => 'motdepasse2026',
        ], $champs));
    }

    // ── L'INSCRIPTION ─────────────────────────────────────────────────────

    public function test_l_inscription_passe_sans_nom_d_entreprise(): void
    {
        $this->inscrire()->assertCreated();

        $this->assertNull(PartnerAccount::firstOrFail()->company_name);
    }

    public function test_un_nom_d_entreprise_donne_est_conserve(): void
    {
        $this->inscrire(['company_name' => 'Garage Ouaga 2000'])->assertCreated();

        $this->assertSame('Garage Ouaga 2000', PartnerAccount::firstOrFail()->company_name);
    }

    /**
     * Une chaine vide n'est PAS « pas de nom ».
     *
     * C'est le cadre qui s'en charge — `TrimStrings` puis
     * `ConvertEmptyStringsToNull`, tous deux actifs par defaut — et non une
     * ligne du controleur. Ce banc tient donc cette CHAINE : la retirer de
     * `bootstrap/app.php` rendrait « ␣␣␣ » stockable tel quel, et c'est la
     * chaine vide qui leve le `RangeError` de `companyName[0]`.
     */
    public function test_un_nom_vide_est_enregistre_comme_absent(): void
    {
        $this->inscrire(['company_name' => '   '])->assertCreated();

        $this->assertNull(PartnerAccount::firstOrFail()->company_name);
    }

    /**
     * `contact_name` reste obligatoire : c'est LUI qui rend le repli possible.
     *
     * Le rendre facultatif a son tour laisserait un vendeur sans aucun nom, et
     * le repli public n'aurait plus sur quoi retomber.
     */
    public function test_le_nom_du_responsable_reste_obligatoire(): void
    {
        $this->inscrire(['contact_name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_name');

        $this->assertSame(0, PartnerAccount::count());
    }

    public function test_le_profil_prive_dit_la_verite_au_titulaire(): void
    {
        $reponse = $this->inscrire()->assertCreated();

        $this->assertNull(
            $reponse->json('partner_account.company_name'),
            'Le titulaire doit voir que son nom d entreprise est vide, et non '
            .'le repli public : sinon il croit l avoir renseigne.',
        );
    }

    // ── LA VITRINE PUBLIQUE ───────────────────────────────────────────────

    public function test_le_nom_public_se_replie_sur_le_nom_du_responsable(): void
    {
        $compte = $this->compte(nom: null);

        foreach (['vehicles', 'parts', 'accessories'] as $type) {
            $annonce = $this->annonce($type, $compte);

            $vendeur = $this->getJson('/api/catalog/'.$type.'/'.$annonce->id)
                ->assertOk()
                ->json('data.seller');

            $this->assertSame('Salif Kabore', $vendeur['company_name'],
                "Sur $type, l application publiee transtype ce champ en String "
                .'et compose son initiale : null ou vide fait tomber la fiche.');
        }
    }

    /**
     * POURQUOI `?:` ET NON `??` DANS LE REPLI.
     *
     * `??` ne retomberait que sur un null. Une chaine VIDE le traverserait, et
     * c'est elle qui leve le `RangeError` de `companyName[0]`. Une telle
     * valeur n'arrive pas par l'inscription — le cadre la nullifie — mais rien
     * n'empeche une ecriture directe en base, une reprise de donnees ou une
     * future route d'edition de profil de la poser.
     */
    public function test_le_repli_couvre_aussi_un_nom_vide_en_base(): void
    {
        $compte = $this->compte(nom: null);
        $compte->forceFill(['company_name' => ''])->save();

        $annonce = $this->annonce('vehicles', $compte);

        $this->assertSame(
            'Salif Kabore',
            $this->getJson('/api/catalog/vehicles/'.$annonce->id)
                ->assertOk()->json('data.seller.company_name'),
        );
    }

    public function test_un_partenaire_qui_a_un_nom_garde_le_sien(): void
    {
        $compte = $this->compte(nom: 'Garage Ouaga 2000');
        $annonce = $this->annonce('vehicles', $compte);

        $this->assertSame(
            'Garage Ouaga 2000',
            $this->getJson('/api/catalog/vehicles/'.$annonce->id)
                ->assertOk()->json('data.seller.company_name'),
        );
    }

    // ── Le decor ──────────────────────────────────────────────────────────

    private function compte(?string $nom): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => $nom,
            'contact_name' => 'Salif Kabore',
            'phone' => '+22670000001',
            'status' => 'approved',
        ]);
    }

    private function annonce(string $type, PartnerAccount $compte): Model
    {
        $possession = [
            'owner_partner_account_id' => $compte->id,
            'partner_published_at' => now(),
        ];

        return match ($type) {
            'vehicles' => Vehicle::create(array_merge([
                'reference' => 'VEH-'.Str::random(10),
                'vin' => 'JTDBR32E63'.str_pad((string) (Vehicle::withTrashed()->count() + 1), 7, '0', STR_PAD_LEFT),
                'brand_id' => $this->modele()->brand_id,
                'vehicle_model_id' => $this->modele()->id,
                'engine_type_id' => EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence'])->id,
                'manufacturing_year' => 2020,
                'registration_status' => 'registered',
                'availability' => 'sale',
                'condition' => 'used',
                'status' => 'in_stock',
                'sale_price' => 4_500_000,
            ], $possession)),
            'parts' => Part::create(array_merge([
                'sku' => 'PRT-'.Str::random(10),
                'name' => 'Filtre du vendeur',
                'part_category_id' => PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres'])->id,
                'type' => 'aftermarket',
                'condition' => 'new',
                'selling_price' => 3_000,
                'stock_quantity' => 4,
                'is_active' => true,
                'is_available' => true,
            ], $possession)),
            'accessories' => Accessory::create(array_merge([
                'sku' => 'ACC-'.Str::random(10),
                'name' => 'Camera du vendeur',
                'category' => 'securite',
                'selling_price' => 12_500,
                'stock_quantity' => 4,
                'is_active' => true,
                'is_available' => true,
            ], $possession)),
        };
    }

    private function modele(): VehicleModel
    {
        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);

        return VehicleModel::firstOrCreate([
            'brand_id' => $marque->id,
            'slug' => 'corolla-test',
        ], ['name' => 'Corolla', 'production_start' => 2010]);
    }
}
