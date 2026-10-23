<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Desactiver un modele doit le retirer des listes de choix — et de la seule
 * liste de choix.
 *
 * ── LE DEFAUT, ET QUAND IL EST DEVENU VISIBLE ──────────────────────────────
 *
 * La colonne `is_active` existait sur `vehicle_models`, le referentiel la
 * posait, et AUCUNE route de selection ne la lisait. Tant que rien n'etait
 * desactive, le defaut etait indetectable : la liste rendait tout, et tout
 * etait actif.
 *
 * Le 23/10/2026, les migrations du lot Toyota ont remplace les lignes nues
 * `tacoma` et `venza` par leurs generations, en desactivant les deux lignes
 * nues. Le selecteur s'est alors mis a proposer « Tacoma » A COTE de Tacoma I,
 * II, III et IV — six entrees pour deux vehicules, dont deux qui ne menent a
 * aucune finition ni aucune cote de consommation.
 *
 * ── L'ASYMETRIE QUE CE BANC PROTEGE ────────────────────────────────────────
 *
 * Le reflexe serait de filtrer PARTOUT. Ce serait un second defaut : si la
 * synchronisation incrementale cessait de renvoyer les lignes desactivees,
 * une application deja installee garderait indefiniment en cache un modele
 * qu'elle ne saurait jamais devoir oublier — une disparition silencieuse vaut
 * exactement le doublon qu'on vient de supprimer. Une liste de choix se filtre
 * au serveur ; un cache s'informe.
 *
 * `test_la_synchronisation_continue_de_servir_les_desactives` est donc la
 * moitie la plus importante de ce fichier : il echouera si quelqu'un
 * « harmonise » le filtrage.
 */
class SelecteurModelesActifsTest extends TestCase
{
    use RefreshDatabase;

    /** Une marque et deux modeles, dont un desactive. */
    private function marqueAvecUnModeleEteint(): array
    {
        $marque = Brand::create(['name' => 'Sonde', 'slug' => 'sonde', 'is_active' => true]);

        $vivant = VehicleModel::create([
            'brand_id' => $marque->id, 'name' => 'Vivant', 'slug' => 'vivant', 'is_active' => true,
        ]);

        $eteint = VehicleModel::create([
            'brand_id' => $marque->id, 'name' => 'Eteint', 'slug' => 'eteint', 'is_active' => false,
        ]);

        return [$marque, $vivant, $eteint];
    }

    public function test_un_modele_desactive_disparait_du_selecteur(): void
    {
        [$marque, $vivant, $eteint] = $this->marqueAvecUnModeleEteint();

        $slugs = collect($this->getJson("/api/catalog/brands/{$marque->id}/models")
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        $this->assertContains('vivant', $slugs->all());
        $this->assertNotContains(
            'eteint',
            $slugs->all(),
            'Un modele desactive reste propose au choix : le referentiel peut le retirer, '.
            'mais la liste continue de le montrer.'
        );
    }

    public function test_active_only_zero_rend_l_inventaire_complet(): void
    {
        [$marque] = $this->marqueAvecUnModeleEteint();

        $slugs = collect($this->getJson("/api/catalog/brands/{$marque->id}/models?active_only=0")
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        $this->assertContains('eteint', $slugs->all(), "active_only=0 doit rendre l'inventaire entier.");
    }

    public function test_le_drapeau_est_rendu_pour_que_le_client_puisse_trancher(): void
    {
        [$marque] = $this->marqueAvecUnModeleEteint();

        $lignes = collect($this->getJson("/api/catalog/brands/{$marque->id}/models?active_only=0")
            ->assertOk()
            ->json('data'))
            ->keyBy('slug');

        $this->assertFalse($lignes['eteint']['is_active'], 'Sans ce champ, un client qui demande l\'inventaire complet ne peut pas distinguer les deux.');
        $this->assertTrue($lignes['vivant']['is_active']);
    }

    public function test_le_compte_de_modeles_ignore_les_desactives(): void
    {
        $this->marqueAvecUnModeleEteint();

        $marques = collect($this->getJson('/api/catalog/brands')->assertOk()->json('data'))
            ->keyBy('name');

        $this->assertSame(
            1,
            $marques['Sonde']['models_count'],
            'Le compte promet un choix que la liste ne tient pas.'
        );
    }

    public function test_la_fiche_de_marque_ignore_les_desactives(): void
    {
        [$marque] = $this->marqueAvecUnModeleEteint();

        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));

        $slugs = collect($this->getJson("/api/brands/{$marque->id}")->assertOk()->json('data.models'))
            ->pluck('slug');

        $this->assertNotContains('eteint', $slugs->all());
    }

    /**
     * LA MOITIE QU'IL NE FAUT PAS « HARMONISER ».
     *
     * Le delta doit continuer a porter la ligne desactivee, avec son drapeau :
     * c'est le seul moyen qu'une application deja installee apprenne qu'elle
     * doit cesser de la proposer. La retirer du delta la figerait pour toujours
     * dans le cache du telephone.
     */
    public function test_la_synchronisation_continue_de_servir_les_desactives(): void
    {
        [, , $eteint] = $this->marqueAvecUnModeleEteint();

        $modeles = collect($this->getJson('/api/catalog/sync')->assertOk()->json('vehicle_models'))
            ->keyBy('slug');

        $this->assertArrayHasKey(
            'eteint',
            $modeles->all(),
            'Le delta ne porte plus la ligne desactivee : une application deja installee '.
            'gardera ce modele en cache indefiniment, sans jamais apprendre qu\'il a ete retire.'
        );

        $this->assertFalse(
            (bool) $modeles['eteint']['is_active'],
            'Le delta porte la ligne mais pas son drapeau : le client ne peut pas savoir quoi en faire.'
        );
    }

    /** Le cas reel : six entrees pour deux vehicules. */
    public function test_le_selecteur_ne_propose_plus_les_doublons_tacoma_et_venza(): void
    {
        $this->seed([
            \Database\Seeders\CountriesSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
        ]);

        $toyota = Brand::where('name', 'Toyota')->firstOrFail();

        $proposes = collect($this->getJson("/api/catalog/brands/{$toyota->id}/models")
            ->assertOk()
            ->json('data'))
            ->pluck('slug');

        // La ligne nue doit avoir disparu, les generations rester.
        $this->assertNotContains('tacoma', $proposes->all(), 'La ligne nue du Tacoma est encore proposee a cote de ses generations.');
        $this->assertNotContains('venza', $proposes->all(), 'La ligne nue du Venza est encore proposee a cote de ses generations.');

        $this->assertContains('tacoma-iii', $proposes->all());
        $this->assertContains('venza-xu80', $proposes->all());

        // Et le filtre ne doit pas avoir emporte autre chose au passage :
        // exactement les deux lignes nues sont desactivees, pas une de plus.
        $this->assertSame(
            ['tacoma', 'venza'],
            VehicleModel::where('is_active', false)->orderBy('slug')->pluck('slug')->all()
        );
    }

    /**
     * LE PIEGE QUE LE FILTRE SEUL NE FERME PAS.
     *
     * `catalog:import-nhtsa` reposait `is_active => true` sur chaque ligne
     * rencontree. Un seul passage aurait donc rallume les lignes nues, d'un
     * coup et sans un mot, et le selecteur se serait remis a proposer les
     * doublons — sans que rien ne relie la cause a l'effet.
     *
     * On avait d'abord cru ce chemin intestable parce qu'il appelle le reseau.
     * C'est faux : il passe par la facade `Http`, que `Http::fake()` intercepte
     * entierement. Il n'etait pas intestable, il n'etait pas teste.
     */
    public function test_import_nhtsa_ne_ressuscite_pas_un_modele_desactive(): void
    {
        $toyota = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);

        $nue = VehicleModel::create([
            'brand_id' => $toyota->id, 'name' => 'Tacoma', 'slug' => 'tacoma', 'is_active' => false,
        ]);

        Http::fake([
            '*' => Http::response(['Results' => [
                ['Model_Name' => 'Tacoma'],
                ['Model_Name' => 'Corolla'],
            ]], 200),
        ]);

        $this->artisan('catalog:import-nhtsa', ['--make' => 'Toyota'])->assertSuccessful();

        $this->assertFalse(
            (bool) $nue->fresh()->is_active,
            'Un import NHTSA vient de rallumer une ligne desactivee a la main. '.
            'Les doublons du selecteur reapparaissent, et rien dans la sortie ne le dit.'
        );
    }

    /** Et il doit toujours creer les nouveaux modeles ACTIFS : ne pas sur-corriger. */
    public function test_import_nhtsa_cree_toujours_les_nouveaux_modeles_actifs(): void
    {
        $toyota = Brand::create(['name' => 'Toyota', 'slug' => 'toyota', 'is_active' => true]);

        Http::fake([
            '*' => Http::response(['Results' => [['Model_Name' => 'Corolla']]], 200),
        ]);

        $this->artisan('catalog:import-nhtsa', ['--make' => 'Toyota'])->assertSuccessful();

        $cree = VehicleModel::where('brand_id', $toyota->id)->where('slug', 'corolla')->first();

        $this->assertNotNull($cree, "L'import ne cree plus rien.");
        $this->assertTrue((bool) $cree->is_active, 'Un modele nouvellement importe arrive eteint : il ne sera jamais proposable.');
    }
}
