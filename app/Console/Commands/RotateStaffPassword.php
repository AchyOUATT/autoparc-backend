<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Change le mot de passe d'un compte du personnel pour une valeur forte,
 * tiree au hasard, affichee une seule fois.
 *
 * Les comptes du seeder partagent tous le mot de passe « password ». Acceptable
 * en local, ouvert a tous les vents une fois deploye : un administrateur
 * possede toutes les capacites, donc aucune regle de role ne protege quoi que
 * ce soit tant que ce mot de passe tient.
 *
 * Les jetons Sanctum existants sont revoques au passage : changer le mot de
 * passe sans cela laisserait une session deja ouverte continuer comme avant.
 */
class RotateStaffPassword extends Command
{
    protected $signature = 'staff:rotate-password
                            {email? : Adresse du compte a traiter}
                            {--all : Tous les comptes du personnel}';

    protected $description = 'Attribue un mot de passe fort a un compte du personnel et revoque ses jetons';

    public function handle(): int
    {
        $email = $this->argument('email');
        $all = (bool) $this->option('all');

        if (($email === null) === ! $all) {
            $this->error('Indiquer une adresse, ou --all pour tout le personnel.');

            return self::INVALID;
        }

        $users = $all
            ? User::query()->whereIn('role', UserRole::staffValues())->orderBy('email')->get()
            : User::query()->whereIn('role', UserRole::staffValues())->where('email', $email)->get();

        if ($users->isEmpty()) {
            $this->error($all ? 'Aucun compte du personnel.' : "Aucun compte pour {$email}.");

            return self::FAILURE;
        }

        $rows = [];

        foreach ($users as $user) {
            // 24 caracteres : hors de portee d'une attaque hors ligne, et
            // destines a un gestionnaire de mots de passe, pas a la memoire.
            $row = DB::transaction(function () use ($user): ?array {
                $current = User::query()->whereKey($user->id)->whereIn('role', UserRole::staffValues())->lockForUpdate()->first();
                if (! $current) {
                    return null;
                }
                $password = Str::password(24);
                $current->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                $revoked = $current->tokens()->delete();
                if ($current->email !== null) {
                    Password::broker('users')->deleteToken($current);
                }

                return [$current->email, $current->role->value, $password, $revoked];
            });
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        $this->newLine();
        $this->table(['Compte', 'Role', 'Nouveau mot de passe', 'Jetons revoques'], $rows);
        $this->newLine();

        $this->warn('Ces mots de passe ne seront plus jamais affiches : les enregistrer maintenant.');
        $this->line('Les sessions ouvertes sur ces comptes sont fermees ; il faudra se reconnecter.');

        return self::SUCCESS;
    }
}
