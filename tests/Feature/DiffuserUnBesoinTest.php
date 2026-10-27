<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\CustomerNeed;
use App\Models\PartnerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Un besoin client, approuve par le personnel, part a tous les partenaires.
 *
 * ── CE QUE LE PROPRIETAIRE A DEMANDE ──────────────────────────────────────
 *
 * « Le staff recoit les notifs clients. Si approuve, il fait un broadcast a
 * tous les partenaires. » L'approbation EST la diffusion : un geste separe
 * serait oublie, et le personnel s'etonnerait du silence.
 *
 * ── CE QUE CE BANC TIENT, ET POURQUOI CHAQUE POINT COMPTE ─────────────────
 *
 * 1. LE CONTACT DU CLIENT NE PART PAS. Ni nom, ni numero, ni courriel. Ce
 *    client s'est adresse a la boutique, pas a quinze vendeurs : leur donner
 *    son numero le ferait appeler par tous, et la boutique aurait donne son
 *    marche en meme temps que sa demande. C'est le banc le plus important du
 *    fichier.
 *
 * 2. ON NE DIFFUSE QU'UNE FOIS. Un aller-retour de statut, une double
 *    soumission, un clic nerveux : quinze partenaires et trois clics font
 *    quarante-cinq notifications pour une demande.
 *
 * 3. SEULS LES COMPTES APPROUVES ET ACTIFS RECOIVENT. Un compte en attente
 *    n'a pas ete examine ; un compte rejete ou suspendu l'a ete.
 *
 * 4. UN PARTENAIRE NE LIT QUE SA BOITE. Marquer lue la notification d'un
 *    autre lui apprendrait au passage combien il y en a.
 */
class DiffuserUnBesoinTest extends TestCase
{
    use RefreshDatabase;

    private User $personnel;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('partenaires.espace_ouvert', true);

        $this->personnel = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    private function besoin(array $champs = []): CustomerNeed
    {
        return CustomerNeed::create(array_merge([
            'type' => 'part',
            'description' => 'Cherche un filtre a huile pour Corolla E180.',
            'budget_max' => 25_000,
            'contact_name' => 'Awa Traore',
            'contact_phone' => '+22670000001',
            'contact_phones' => ['+22670000001'],
            'contact_email' => 'awa@example.test',
            'status' => 'pending',
        ], $champs));
    }

    private function partenaire(string $statut = 'approved', bool $actif = true): User
    {
        $user = User::factory()->create(['role' => 'partner', 'is_active' => $actif]);

        PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Garage '.$user->id,
            'contact_name' => 'Responsable',
            'phone' => $this->numeroDePartenaire($user->id),
            'status' => $statut,
        ]);

        return $user;
    }

    private function approuver(CustomerNeed $besoin): TestResponse
    {
        Sanctum::actingAs($this->personnel);

        return $this->putJson('/api/needs/'.$besoin->id, ['status' => 'approved']);
    }

    private function diffusions(): \Illuminate\Database\Eloquent\Collection
    {
        return AppNotification::where('type', 'need_broadcast')->get();
    }

    // ── LA DIFFUSION ──────────────────────────────────────────────────────

    public function test_approuver_un_besoin_le_diffuse_a_tous_les_partenaires(): void
    {
        $a = $this->partenaire();
        $b = $this->partenaire();

        $this->approuver($this->besoin())->assertOk();

        $this->assertEqualsCanonicalizing(
            [(string) $a->id, (string) $b->id],
            $this->diffusions()->pluck('recipient_id')->all(),
        );
    }

    public function test_le_besoin_porte_la_trace_de_sa_diffusion(): void
    {
        $this->partenaire();
        $besoin = $this->besoin();

        $this->approuver($besoin)->assertOk();

        $this->assertNotNull($besoin->refresh()->broadcast_at);
        $this->assertSame('approved', $besoin->status);
    }

    /** Le corps dit de quoi il s'agit sans qu'on ait a ouvrir l'ecran. */
    public function test_la_notification_dit_le_type_et_le_budget(): void
    {
        $this->partenaire();

        $this->approuver($this->besoin())->assertOk();

        $notification = $this->diffusions()->first();
        $this->assertStringContainsString('Pièce détachée', $notification->body);
        $this->assertStringContainsString('25 000', $notification->body,
            'Un budget a sept chiffres colles se relit mal, et c est la que se '
            .'glisse le zero de trop.');
        $this->assertStringContainsString('filtre a huile', $notification->body);
    }

    // ── LE BANC LE PLUS IMPORTANT DU FICHIER ──────────────────────────────

    /**
     * LE CONTACT DU CLIENT NE PART PAS.
     *
     * Le besoin porte un nom, un numero et un courriel. Les transmettre a
     * quinze vendeurs ferait appeler le client par tous, et la boutique aurait
     * donne son marche en meme temps que sa demande.
     *
     * On cherche les valeurs dans le JSON COMPLET de la notification, et non
     * champ par champ : une fuite par une clef a laquelle on n'a pas pense ne
     * se verrait pas autrement.
     */
    public function test_le_contact_du_client_ne_part_pas_aux_partenaires(): void
    {
        $this->partenaire();

        $this->approuver($this->besoin())->assertOk();

        $brut = json_encode($this->diffusions()->first()->toArray(), JSON_UNESCAPED_UNICODE);

        foreach (['Awa Traore', '+22670000001', 'awa@example.test'] as $prive) {
            $this->assertStringNotContainsString($prive, $brut,
                "« $prive » est parti aux partenaires : le client sera appele "
                .'par tout le monde.');
        }
    }

    /** Ce qui part, en revanche, doit suffire a savoir si on a la piece. */
    public function test_les_criteres_partent_bien(): void
    {
        $this->partenaire();

        $this->approuver($this->besoin(['oem_number' => '90915-YZZD2']))->assertOk();

        $besoin = $this->diffusions()->first()->data['besoin'];

        $this->assertSame('part', $besoin['type']);
        $this->assertSame('90915-YZZD2', $besoin['oem_number']);
        $this->assertSame(25_000.0, (float) $besoin['budget_max']);
        $this->assertArrayNotHasKey('contact_phone', $besoin);
        $this->assertArrayNotHasKey('staff_notes', $besoin);
    }

    // ── ON NE DIFFUSE QU'UNE FOIS ─────────────────────────────────────────

    public function test_approuver_deux_fois_ne_diffuse_qu_une_fois(): void
    {
        $this->partenaire();
        $besoin = $this->besoin();

        $this->approuver($besoin)->assertOk();
        $this->approuver($besoin->refresh())->assertOk();

        $this->assertSame(1, $this->diffusions()->count());
    }

    /**
     * L'aller-retour de statut non plus.
     *
     * Approuver, revenir en attente, re-approuver : c'est un geste plausible
     * — une correction de note, un clic de trop — et il ne doit pas rejouer
     * la diffusion.
     */
    public function test_un_aller_retour_de_statut_ne_rediffuse_pas(): void
    {
        $this->partenaire();
        $besoin = $this->besoin();

        $this->approuver($besoin)->assertOk();

        Sanctum::actingAs($this->personnel);
        $this->putJson('/api/needs/'.$besoin->id, ['status' => 'pending'])->assertOk();

        $this->approuver($besoin->refresh())->assertOk();

        $this->assertSame(1, $this->diffusions()->count());
    }

    public function test_les_autres_statuts_ne_diffusent_pas(): void
    {
        $this->partenaire();

        foreach (['contacted', 'fulfilled', 'cancelled'] as $statut) {
            Sanctum::actingAs($this->personnel);
            $this->putJson('/api/needs/'.$this->besoin()->id, ['status' => $statut])->assertOk();
        }

        $this->assertSame(0, $this->diffusions()->count());
    }

    // ── QUI RECOIT ────────────────────────────────────────────────────────

    public function test_seuls_les_comptes_approuves_recoivent(): void
    {
        $approuve = $this->partenaire();
        $this->partenaire('pending');
        $this->partenaire('rejected');
        $this->partenaire('suspended');

        $this->approuver($this->besoin())->assertOk();

        $this->assertSame(
            [(string) $approuve->id],
            $this->diffusions()->pluck('recipient_id')->all(),
        );
    }

    public function test_un_compte_desactive_ne_recoit_pas(): void
    {
        $actif = $this->partenaire();
        $this->partenaire(actif: false);

        $this->approuver($this->besoin())->assertOk();

        $this->assertSame(
            [(string) $actif->id],
            $this->diffusions()->pluck('recipient_id')->all(),
        );
    }

    /** Sans aucun partenaire, l'approbation passe quand meme. */
    public function test_sans_partenaire_l_approbation_passe(): void
    {
        $besoin = $this->besoin();

        $this->approuver($besoin)->assertOk();

        $this->assertSame('approved', $besoin->refresh()->status);
        $this->assertSame(0, $this->diffusions()->count());
    }

    // ── LA BOITE AUX LETTRES DU PARTENAIRE ────────────────────────────────

    public function test_un_partenaire_lit_sa_boite(): void
    {
        $partenaire = $this->partenaire();
        $this->approuver($this->besoin())->assertOk();

        Sanctum::actingAs($partenaire, ['partner']);

        $reponse = $this->getJson('/api/partner/notifications')->assertOk();

        $this->assertCount(1, $reponse->json('data'));
        $this->assertSame('need_broadcast', $reponse->json('data.0.type'));
        $this->assertSame(1, $this->getJson('/api/partner/notifications/unread-count')
            ->assertOk()->json('count'));
    }

    public function test_un_partenaire_ne_voit_pas_la_boite_d_un_autre(): void
    {
        $a = $this->partenaire();
        $this->partenaire();
        $this->approuver($this->besoin())->assertOk();

        Sanctum::actingAs($a, ['partner']);

        $this->assertCount(1, $this->getJson('/api/partner/notifications')
            ->assertOk()->json('data'));
    }

    /**
     * Marquer lue CELLE D'UN AUTRE est refuse.
     *
     * Sans ce controle, un partenaire les marquerait lues une a une et
     * apprendrait au passage combien il y en a — la route liant le modele par
     * son identifiant, la reponse dirait si chacun existe.
     */
    public function test_un_partenaire_ne_marque_pas_lue_celle_d_un_autre(): void
    {
        $a = $this->partenaire();
        $b = $this->partenaire();
        $this->approuver($this->besoin())->assertOk();

        $deB = AppNotification::where('recipient_id', (string) $b->id)->firstOrFail();

        Sanctum::actingAs($a, ['partner']);
        $this->postJson('/api/partner/notifications/'.$deB->id.'/read')->assertForbidden();

        $this->assertNull($deB->refresh()->read_at);
    }

    public function test_un_partenaire_marque_les_siennes_lues(): void
    {
        $partenaire = $this->partenaire();
        $this->approuver($this->besoin())->assertOk();

        Sanctum::actingAs($partenaire, ['partner']);
        $this->postJson('/api/partner/notifications/read-all')->assertOk();

        $this->assertSame(0, $this->getJson('/api/partner/notifications/unread-count')
            ->assertOk()->json('count'));
    }

    /**
     * La boite reste ouverte a un compte NON approuve.
     *
     * C'est par elle qu'il apprendra son approbation : l'enfermer derriere
     * `partner.approved` rendrait cette notification illisible par celui a qui
     * elle s'adresse.
     */
    public function test_un_compte_en_attente_peut_lire_sa_boite(): void
    {
        $enAttente = $this->partenaire('pending');

        Sanctum::actingAs($enAttente, ['partner']);

        $this->getJson('/api/partner/notifications')->assertOk();
    }

    /** Le personnel n'a pas acces a la boite des partenaires, et inversement. */
    public function test_le_personnel_ne_passe_pas_par_la_route_partenaire(): void
    {
        Sanctum::actingAs($this->personnel);

        $this->getJson('/api/partner/notifications')->assertForbidden();
    }

    // ── CE QUE LE PERSONNEL CONTINUE DE RECEVOIR ──────────────────────────

    /** Le depot d'un besoin notifie toujours le personnel, et lui seul. */
    public function test_le_depot_notifie_le_personnel_et_pas_les_partenaires(): void
    {
        $this->partenaire();

        $this->postJson('/api/needs', [
            'type' => 'part',
            'description' => 'Cherche un filtre a huile.',
            'contact_phones' => ['+22670000002'],
        ])->assertCreated();

        $this->assertSame(1, AppNotification::where('type', 'new_need')->count());
        $this->assertSame(0, $this->diffusions()->count());
    }
}
