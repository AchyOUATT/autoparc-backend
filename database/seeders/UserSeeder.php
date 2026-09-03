<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Un compte par rôle métier, avec mot de passe connu pour tester en dev.
        // Adapte les rôles ci-dessous si les noms de cas de ton enum UserRole diffèrent.
        $staffRoles = collect(UserRole::cases())
            ->reject(fn ($case) => strtolower($case->value) === 'client')
            ->values();

        foreach ($staffRoles as $role) {
            User::create([
                'name' => ucfirst($role->value).' Test',
                'email' => strtolower($role->value).'@autoparc.test',
                'password' => Hash::make('password'),
                'role' => $role->value,
                'phone' => '+226 70 00 00 '.random_int(10, 99),
                'is_active' => true,
                'email_verified_at' => now(),
            ]);
        }

        // Comptes clients (self-service, provisionnés via Firebase côté mobile).
        $clientRole = collect(UserRole::cases())
            ->first(fn ($case) => strtolower($case->value) === 'client');

        if ($clientRole) {
            for ($i = 1; $i <= 5; $i++) {
                User::create([
                    'name' => 'Client Test '.$i,
                    'email' => "client{$i}@autoparc.test",
                    'password' => Hash::make('password'),
                    'role' => $clientRole->value,
                    'phone' => '+226 76 11 11 '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
                    'is_active' => true,
                    'email_verified_at' => now(),
                    // firebase_uid volontairement laissé null : à remplir lors du vrai login mobile.
                ]);
            }
        }
    }
}
