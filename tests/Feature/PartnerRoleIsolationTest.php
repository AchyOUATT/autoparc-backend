<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AppNotification;
use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerRoleIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_notifications_only_target_staff_accounts(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        User::factory()->create(['role' => UserRole::Partner]);
        User::factory()->create(['role' => UserRole::Client]);

        AppNotification::notifyAllStaff('new_need', 'Besoin client', 'Message interne');

        $this->assertSame(
            [$admin->id, $manager->id],
            AppNotification::orderBy('recipient_id')->pluck('recipient_id')->map(fn ($id) => (int) $id)->all(),
        );
    }

    public function test_the_staff_seeder_does_not_create_an_unreviewed_partner_account(): void
    {
        $this->seed(UserSeeder::class);

        $this->assertDatabaseMissing('users', ['role' => UserRole::Partner->value]);
        $this->assertDatabaseHas('users', ['role' => UserRole::Admin->value]);
        $this->assertDatabaseHas('users', ['role' => UserRole::Client->value]);
    }
}
