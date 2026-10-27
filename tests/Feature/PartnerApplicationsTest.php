<?php

namespace Tests\Feature;

use App\Models\PartnerAccount;
use App\Models\User;
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
}
