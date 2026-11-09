<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

class RotatePartnerPassword extends Command
{
    protected $signature = 'partner:rotate-password {userId : Identifiant exact du compte partenaire après vérification de son identité}';

    protected $description = 'Récupération assistée d’un partenaire : remplace son mot de passe et révoque ses sessions';

    public function handle(): int
    {
        $id = $this->argument('userId');
        if (! is_string($id) || ! ctype_digit($id) || (int) $id < 1) {
            $this->error('Indiquez l’identifiant numérique exact du compte partenaire.');

            return self::INVALID;
        }

        $result = DB::transaction(function () use ($id): ?array {
            $user = User::query()->whereKey((int) $id)->where('role', UserRole::Partner->value)->lockForUpdate()->first();
            if (! $user) {
                return null;
            }
            $password = Str::password(24);
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            $revoked = $user->tokens()->delete();
            if ($user->email !== null) {
                Password::broker('users')->deleteToken($user);
            }

            return [$password, $revoked];
        });

        if ($result === null) {
            $this->error('Aucun compte partenaire correspondant.');

            return self::FAILURE;
        }

        $this->line('Compte partenaire : '.$id);
        $this->output->writeln('Nouveau mot de passe : '.$result[0], OutputInterface::OUTPUT_RAW);
        $this->line('Jetons révoqués : '.$result[1]);
        $this->warn('Communiquer ce mot de passe uniquement après vérification de l’identité, par un canal privé. Ne pas conserver cette sortie dans un journal ou un ticket.');

        return self::SUCCESS;
    }
}
