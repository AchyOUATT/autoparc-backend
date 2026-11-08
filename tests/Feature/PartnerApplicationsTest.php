<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Support\NotificationText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PartnerApplicationsTest extends TestCase
{
    use RefreshDatabase;

    private function account(): PartnerAccount
    {
        $user = User::factory()->create(['role' => 'partner']);

        return PartnerAccount::create([
            'user_id' => $user->id,
            'company_name' => 'Entreprise a valider',
            'contact_name' => $user->name,
            'phone' => $this->numeroDePartenaire($user->id),
            'status' => 'pending',
        ])->load('user');
    }

    private function bearer(string $token): static
    {
        Auth::forgetGuards();

        return $this->withToken($token);
    }

    public function test_la_direction_peut_lister_consulter_et_approuver_une_inscription(): void
    {
        $account = $this->account();
        $manager = User::factory()->create(['role' => 'manager']);
        $token = $manager->createToken('direction')->plainTextToken;

        $this->bearer($token)->getJson('/api/partner-applications?status=pending')
            ->assertOk()->assertJsonPath('data.0.id', $account->id);
        $this->bearer($token)->getJson('/api/partner-applications/'.$account->id)
            ->assertOk()->assertJsonPath('data.company_name', 'Entreprise a valider');
        $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.reviewed_by', $manager->id);
        $this->assertNotNull($account->fresh()->reviewed_at);
    }

    public function test_rejet_et_suspension_exigent_un_motif_et_revoquent_les_jetons(): void
    {
        foreach (['reject' => 'rejected', 'suspend' => 'suspended'] as $action => $status) {
            $account = $this->account();
            $account->update(['status' => 'approved']);
            $partnerToken = $account->user->createToken('partenaire')->plainTextToken;
            $admin = User::factory()->create(['role' => 'admin']);
            $adminToken = $admin->createToken('direction')->plainTextToken;

            $this->bearer($adminToken)->postJson('/api/partner-applications/'.$account->id.'/'.$action)
                ->assertUnprocessable()->assertJsonValidationErrors('review_reason');
            $this->assertSame(1, $account->user->tokens()->count());

            $this->bearer($adminToken)->postJson('/api/partner-applications/'.$account->id.'/'.$action, [
                'review_reason' => 'Document de verification manquant.',
            ])->assertOk()->assertJsonPath('data.status', $status);
            $this->assertSame(0, $account->user->tokens()->count());
            $this->bearer($partnerToken)->getJson('/api/partner/me')->assertUnauthorized();
        }
    }

    public function test_les_autres_roles_ne_peuvent_pas_gerer_les_inscriptions(): void
    {
        $account = $this->account();
        foreach (['sales', 'warehouse', 'mechanic', 'viewer', 'client', 'partner'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $token = $user->createToken('test')->plainTextToken;
            $this->bearer($token)->getJson('/api/partner-applications')->assertForbidden();
            $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/approve')->assertForbidden();
        }
        $this->assertSame('pending', $account->fresh()->status);
    }

    public function test_une_suspension_est_reverifiee_pour_un_jeton_qui_existerait_encore(): void
    {
        $account = $this->account();
        $account->update(['status' => 'approved']);
        $token = $account->user->createToken('partenaire')->plainTextToken;

        $this->bearer($token)->getJson('/api/partner/vehicles')->assertOk();
        // Simule une mise a jour d'etat externe sans suppression des jetons.
        $account->update(['status' => 'suspended']);
        $this->bearer($token)->getJson('/api/partner/vehicles')->assertForbidden();
    }

    /**
     * L'approbation se lit dans la boite du partenaire, dans SA langue.
     *
     * Le texte est ecrit en francais et traduit a la livraison, comme toutes
     * les notifications automatiques : c'est la langue de la requete qui
     * compte, pas celle du personnel qui a clique.
     */
    public function test_l_approbation_notifie_le_partenaire_dans_sa_langue(): void
    {
        $account = $this->account();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->bearer($admin->createToken('direction')->plainTextToken)
            ->postJson('/api/partner-applications/'.$account->id.'/approve')->assertOk();

        $this->assertDatabaseHas('app_notifications', [
            'recipient_type' => 'partner', 'recipient_id' => (string) $account->user_id,
            'type' => 'partner_account_approved', 'read_at' => null,
        ]);

        $token = $account->user->createToken('partenaire')->plainTextToken;
        $this->bearer($token)->withHeader('Accept-Language', 'fr')->getJson('/api/partner/notifications')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'partner_account_approved')
            ->assertJsonPath('data.0.title', 'Compte approuvé')
            ->assertJsonPath('data.0.body', 'Votre compte est approuvé. Publiez votre première annonce.');
        $this->bearer($token)->withHeader('Accept-Language', 'en')->getJson('/api/partner/notifications')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Account approved')
            ->assertJsonPath('data.0.body', 'Your account is approved. Publish your first listing.');
        $this->assertSame(1, $this->bearer($token)->getJson('/api/partner/notifications/unread-count')->json('count'));
    }

    /**
     * Le refus et la suspension laissent le motif dans la boite.
     *
     * Le partenaire ne la lira pas tout de suite : ses jetons viennent d'etre
     * revoques et la connexion lui est refusee tant que le compte reste dans
     * cet etat (`AuthController::login` rend 403 avec le motif). La ligne est
     * la trace qu'il retrouvera si le compte est reapprouve ; le banc verifie
     * qu'elle porte le motif tel quel, en francais comme en anglais.
     */
    public function test_le_refus_et_la_suspension_notifient_le_motif(): void
    {
        $issues = [
            'reject' => ['partner_account_rejected', 'Demande refusée', 'Votre demande a été refusée : ',
                'Application declined', 'Your application was declined: '],
            'suspend' => ['partner_account_suspended', 'Compte suspendu', 'Votre compte est suspendu : ',
                'Account suspended', 'Your account is suspended: '],
        ];
        foreach ($issues as $action => [$type, $titre, $corps, $title, $body]) {
            $account = $this->account();
            $account->update(['status' => 'approved']);
            $admin = User::factory()->create(['role' => 'admin']);

            $this->bearer($admin->createToken('direction')->plainTextToken)
                ->postJson('/api/partner-applications/'.$account->id.'/'.$action, [
                    'review_reason' => 'Document de vérification manquant.',
                ])->assertOk();

            $notification = AppNotification::forPartner($account->user_id)->where('type', $type)->firstOrFail();
            $this->assertSame($titre, $notification->title);
            $this->assertSame($corps.'Document de vérification manquant.', $notification->body);
            $this->assertNull($notification->read_at);
            $this->assertSame(
                ['title' => $title, 'body' => $body.'Document de vérification manquant.'],
                NotificationText::forLocale($notification->title, $notification->body, ['type' => $type], 'en'),
            );
        }
    }

    /**
     * La meme decision envoyee deux fois — instance lente, client qui
     * reessaie, double appui — n'est pas deux decisions : une seule ligne
     * dans la boite, et la pastille dit « 1 ».
     */
    public function test_la_meme_decision_envoyee_deux_fois_ne_notifie_qu_une_fois(): void
    {
        $account = $this->account();
        $token = User::factory()->create(['role' => 'admin'])->createToken('direction')->plainTextToken;

        $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/approve')->assertOk();
        $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(1, AppNotification::forPartner($account->user_id)->count());

        // Un motif qui change sur une suspension, lui, est une autre decision :
        // le partenaire doit lire le motif corrige. Deux suspensions, deux motifs,
        // deux lignes ; la meme suspension redite, aucune de plus.
        foreach (['Pièces manquantes.', 'Pièces manquantes.', 'Pièces manquantes et plainte client.'] as $motif) {
            $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/suspend', ['review_reason' => $motif])->assertOk();
        }
        $this->assertSame(2, AppNotification::forPartner($account->user_id)->where('type', 'partner_account_suspended')->count());
    }

    /**
     * Un compte reapprouve a deja ses annonces : « publiez votre premiere
     * annonce » serait faux, et il le saurait.
     */
    public function test_un_compte_reapprouve_lit_qu_il_est_de_nouveau_actif(): void
    {
        $account = $this->account();
        $account->update(['status' => 'approved']);
        $token = User::factory()->create(['role' => 'admin'])->createToken('direction')->plainTextToken;

        $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/suspend', ['review_reason' => 'Vérification en cours.'])->assertOk();
        $this->bearer($token)->postJson('/api/partner-applications/'.$account->id.'/approve')->assertOk();

        $approbation = fn (string $langue): array => collect(
            $this->bearer($account->user->createToken('partenaire')->plainTextToken)
                ->withHeader('Accept-Language', $langue)->getJson('/api/partner/notifications')
                ->assertOk()->assertJsonCount(2, 'data')->json('data'),
        )->firstWhere('type', 'partner_account_approved');

        $this->assertSame('Votre compte est de nouveau actif.', $approbation('fr')['body']);
        $this->assertSame('Your account is active again.', $approbation('en')['body']);
    }
}
