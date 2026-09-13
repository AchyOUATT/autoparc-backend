<?php

namespace Tests\Feature;

use App\Models\Manufacturer;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * La matrice des droits, verifiee la ou elle compte : sur des requetes HTTP
 * reelles.
 *
 * Deux tests la couvrent deja par ailleurs — l'un verrouille les capacites de
 * chaque role, l'autre verifie que toute ecriture du back-office declare bien
 * une capacite. Aucun des deux ne prouve que le middleware s'applique
 * vraiment : une erreur d'alias, un ordre de middleware, un groupe mal ferme,
 * et les regles resteraient justes sur le papier pendant que l'API laisse tout
 * passer. Celui-ci ferme la boucle.
 *
 * La verification a d'abord ete faite a la main, au curl, sur les six comptes.
 * Elle est ici pour ne plus jamais avoir a la refaire.
 */
class StaffPermissionsHttpTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'manager', 'sales', 'mechanic', 'warehouse', 'viewer'];

    /**
     * Action → roles autorises. Tout role absent doit recevoir un 403.
     *
     * @return array<string, array{string, string, array<int, string>}>
     */
    public static function actions(): array
    {
        return [
            'creer un vehicule'      => ['POST',   '/api/vehicles',      ['admin', 'manager', 'sales']],
            'enregistrer une vente'  => ['POST',   '/api/sales',         ['admin', 'manager', 'sales']],
            'creer un client'        => ['POST',   '/api/customers',     ['admin', 'manager', 'sales']],
            'creer un partenaire'    => ['POST',   '/api/partners',      ['admin', 'manager']],
            'notifier tous les clients' => ['POST', '/api/broadcast-tip', ['admin', 'manager']],
            'creer un numero OEM'    => ['POST',   '/api/oem-numbers',   ['admin', 'manager', 'sales']],
        ];
    }

    /** @param array<int, string> $autorises */
    #[DataProvider('actions')]
    public function test_l_action_est_refusee_aux_roles_non_autorises(
        string $methode,
        string $chemin,
        array $autorises,
    ): void {
        foreach (self::ROLES as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $status = $this->json($methode, $chemin, [])->getStatusCode();

            if (in_array($role, $autorises, true)) {
                // Le corps est vide : on attend la validation (422), surtout pas
                // un refus. Ce qui compte est d'avoir franchi la barriere.
                $this->assertNotSame(403, $status, "{$role} devrait pouvoir atteindre {$chemin}");
            } else {
                $this->assertSame(403, $status, "{$role} ne devrait pas atteindre {$chemin}");
            }
        }
    }

    public function test_la_consultation_reste_ouverte_a_tout_le_personnel(): void
    {
        foreach (self::ROLES as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->getJson('/api/vehicles')->assertOk();
        }
    }

    /** La suppression est plus restreinte que la modification : elle ne se rattrape pas. */
    public function test_seuls_admin_et_manager_suppriment_un_vehicule(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);

        foreach (['sales', 'mechanic', 'warehouse', 'viewer'] as $role) {
            $vehicule = Vehicle::factory()->create();
            Sanctum::actingAs(User::factory()->create(['role' => $role]));

            $this->deleteJson("/api/vehicles/{$vehicule->id}")->assertForbidden();
            $this->assertDatabaseHas('vehicles', ['id' => $vehicule->id]);
        }

        $vehicule = Vehicle::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => 'manager']));
        $this->deleteJson("/api/vehicles/{$vehicule->id}")->assertSuccessful();
    }

    /** Le stock appartient au magasin, pas au commercial. */
    public function test_seuls_les_roles_du_stock_ajustent_les_quantites(): void
    {
        $piece = Part::create([
            'sku' => 'PRT-STOCK1', 'name' => 'Filtre a huile',
            'part_category_id' => PartCategory::create(['name' => 'Filtres', 'slug' => 'filtres'])->id,
            'manufacturer_id'  => Manufacturer::create(['name' => 'Bosch'])->id,
            'type' => 'aftermarket', 'condition' => 'new',
            'cost_price' => 2000, 'selling_price' => 4000, 'currency' => 'XOF',
            'vat_rate' => 18.00, 'stock_quantity' => 10, 'is_active' => true,
        ]);

        foreach (['sales', 'mechanic', 'viewer'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson("/api/parts/{$piece->id}/stock", ['operation' => 'increase', 'quantity' => 5])
                ->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'warehouse']));
        $this->postJson("/api/parts/{$piece->id}/stock", ['operation' => 'increase', 'quantity' => 5])
            ->assertSuccessful();

        $this->assertSame(15, $piece->refresh()->stock_quantity);
    }

    /** Le mecanicien declare les pannes ; le commercial non. */
    public function test_seuls_les_roles_techniques_declarent_une_panne(): void
    {
        $this->seed(\Database\Seeders\ReferenceDataSeeder::class);
        $vehicule = Vehicle::factory()->create();

        foreach (['sales', 'warehouse', 'viewer'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            $this->postJson("/api/vehicles/{$vehicule->id}/faults", [])->assertForbidden();
        }

        Sanctum::actingAs(User::factory()->create(['role' => 'mechanic']));
        $this->postJson("/api/vehicles/{$vehicule->id}/faults", [])->assertStatus(422);
    }

    public function test_un_client_n_entre_pas_dans_le_back_office(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'client']));

        $this->getJson('/api/vehicles')->assertForbidden();
        $this->postJson('/api/vehicles', [])->assertForbidden();
    }

    public function test_sans_authentification_le_back_office_repond_401(): void
    {
        $this->getJson('/api/vehicles')->assertUnauthorized();
    }
}
