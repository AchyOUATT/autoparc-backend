<?php

namespace Tests\Support;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartCategory;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Le decor d'un partenaire approuve et de ses trois inventaires.
 *
 * ── POURQUOI UN TRAIT PLUTOT QU'UNE COPIE ─────────────────────────────────
 *
 * Trois bancs ecrivent desormais des annonces partenaires — la marge, les
 * promotions, et ce qui suivra. Recopier le decor ferait diverger ce que
 * chacun croit etre « une annonce valide » : le jour ou une regle de
 * validation change, un banc tomberait et l'autre continuerait de passer sur
 * une charge que le serveur refuse depuis longtemps.
 *
 * ── CE QUE CE DECOR SAIT, ET QUI N'EST PAS EVIDENT ────────────────────────
 *
 * — Un numero DISTINCT par compte : `partner_accounts.phone_key` est unique
 *   depuis que le numero identifie un partenaire. Les decors qui donnaient le
 *   meme numero a chaque compte creaient un doublon des le deuxieme.
 *
 * — La MODIFICATION exige la charge complete : `UpdateVehicleRequest`
 *   redemande l'identite du vehicule, donc un PUT partiel rend 422.
 *
 * — `Sanctum::actingAs` vaut pour TOUT le reste du banc. Une requete
 *   « publique » qui suit une creation porte encore le jeton du vendeur et
 *   mesure la vue du proprietaire : d'ou `oublierLaSession()`.
 */
trait CreeDesAnnoncesPartenaires
{
    protected PartnerAccount $compte;

    protected function compteApprouve(): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => true]);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Garage '.$user->id,
            'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id),
            'status' => 'approved',
        ]);
    }

    /**
     * Une charge de vehicule valide, ou l'on remplace ce qu'on veut eprouver.
     *
     * @param  array<string, mixed>  $champs
     * @return array<string, mixed>
     */
    protected function vehicule(array $champs = []): array
    {
        $modele = $this->modele();

        return array_merge([
            'brand_id' => $modele->brand_id,
            'vehicle_model_id' => $modele->id,
            'engine_type_id' => EngineType::firstOrCreate(['code' => 'petrol'], ['label' => 'Essence'])->id,
            'manufacturing_year' => 2020,
            'vehicle_type' => 'passenger',
            'registration_status' => 'registered',
            'condition' => 'used',
            'availability' => 'sale',
        ], $champs);
    }

    /** @param  array<string, mixed>  $champs */
    protected function creerVehicule(array $champs = []): TestResponse
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        return $this->postJson('/api/partner/vehicles', $this->vehicule($champs));
    }

    /**
     * La modification exige la charge COMPLETE : `UpdateVehicleRequest`
     * redemande l'identite du vehicule, donc on renvoie le decor entier plus
     * les champs qu'on change.
     *
     * @param  array<string, mixed>  $champs
     */
    protected function modifierVehicule(int $id, array $champs): TestResponse
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        return $this->putJson('/api/partner/vehicles/'.$id, $this->vehicule($champs));
    }

    /** @param  array<string, mixed>  $champs */
    protected function creerPiece(array $champs = []): TestResponse
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        return $this->postJson('/api/partner/parts', array_merge([
            'name' => 'Filtre a huile',
            'part_category_id' => PartCategory::firstOrCreate(['slug' => 'filtres'], ['name' => 'Filtres'])->id,
            'type' => 'aftermarket',
            'condition' => 'new',
            'stock_quantity' => 4,
        ], $champs));
    }

    /** @param  array<string, mixed>  $champs */
    protected function creerAccessoire(array $champs = []): TestResponse
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        return $this->postJson('/api/partner/accessories', array_merge([
            'name' => 'Camera de recul',
            'category' => 'securite',
            'stock_quantity' => 4,
        ], $champs));
    }

    protected function modele(): VehicleModel
    {
        $marque = Brand::firstOrCreate(['slug' => 'toyota'], ['name' => 'Toyota']);

        return VehicleModel::firstOrCreate([
            'brand_id' => $marque->id,
            'slug' => 'corolla-test',
        ], ['name' => 'Corolla', 'production_start' => 2010]);
    }

    /**
     * Oublier la session en cours.
     *
     * `Sanctum::actingAs` vaut pour tout le reste du banc : sans cet oubli, la
     * requete « publique » qui suit porte encore le jeton du vendeur, et on
     * mesure la vue du proprietaire en croyant mesurer celle d'un acheteur.
     */
    protected function oublierLaSession(): void
    {
        \Illuminate\Support\Facades\Auth::forgetGuards();
    }
}
