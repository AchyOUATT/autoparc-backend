<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\OwnedVehicle;
use App\Services\FcmService;
use Illuminate\Console\Command;

/**
 * Rappels d'entretien du garage client.
 *
 * Balaie les vehicules declares, releve les echeances proches ou depassees et
 * previent le proprietaire. C'est le moteur de recurrence de l'application :
 * un acheteur ne revient qu'une fois tous les quelques annees, un proprietaire
 * a une echeance a surveiller chaque trimestre.
 *
 * Chaque rappel est utile en soi — personne ne vit un avis d'expiration
 * d'assurance comme une sollicitation commerciale — et debouche naturellement
 * sur une piece ou un passage a l'atelier.
 */
class SendMaintenanceReminders extends Command
{
    protected $signature = 'reminders:maintenance
                            {--days=30 : Fenetre d\'alerte, en jours, pour les echeances datees}
                            {--km=500 : Fenetre d\'alerte, en kilometres, pour la vidange}
                            {--dry-run : Affiche les rappels sans rien envoyer}';

    protected $description = "Previent les proprietaires dont une echeance d'entretien approche";

    /**
     * Deux rappels du meme type pour le meme vehicule ne sont pas renvoyes
     * avant ce delai — sans quoi la tache quotidienne repeterait le meme avis
     * chaque matin pendant tout le mois precedant l'echeance.
     */
    private const REPEAT_AFTER_DAYS = 30;

    public function __construct(private readonly FcmService $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $days   = (int) $this->option('days');
        $km     = (int) $this->option('km');
        $dryRun = (bool) $this->option('dry-run');

        $sent = 0;
        $skipped = 0;

        OwnedVehicle::with(['user', 'brand', 'vehicleModel'])
            ->where(function ($q) {
                $q->whereNotNull('technical_inspection_expiry')
                  ->orWhereNotNull('insurance_expiry')
                  ->orWhereNotNull('service_interval_km');
            })
            ->chunkById(100, function ($vehicles) use ($days, $km, $dryRun, &$sent, &$skipped) {
                foreach ($vehicles as $vehicle) {
                    $uid = $vehicle->user?->firebase_uid;

                    // Sans identifiant Firebase, aucun canal pour joindre le
                    // proprietaire : ni notification en base, ni push.
                    if ($uid === null) {
                        continue;
                    }

                    foreach ($vehicle->deadlines() as $deadline) {
                        if (! $this->isDue($deadline, $days, $km)) {
                            continue;
                        }

                        if ($this->alreadyReminded($uid, $vehicle->id, $deadline['kind'])) {
                            $skipped++;
                            continue;
                        }

                        $this->notify($vehicle, $uid, $deadline, $dryRun);
                        $sent++;
                    }
                }
            });

        $this->info($dryRun
            ? "{$sent} rappel(s) a envoyer, {$skipped} deja envoye(s) recemment. Rien n'a ete emis."
            : "{$sent} rappel(s) envoye(s), {$skipped} ignore(s) car deja envoyes recemment.");

        return self::SUCCESS;
    }

    /** L'echeance entre-t-elle dans la fenetre d'alerte, ou est-elle depassee ? */
    private function isDue(array $deadline, int $days, int $km): bool
    {
        if ($deadline['overdue']) {
            return true;
        }

        if ($deadline['days_left'] !== null) {
            return $deadline['days_left'] <= $days;
        }

        // Vidange : le detail porte le kilometrage restant.
        if ($deadline['kind'] === 'service' && preg_match('/(\d+)/', (string) $deadline['detail'], $m)) {
            return (int) $m[1] <= $km;
        }

        return false;
    }

    private function alreadyReminded(string $uid, int $vehicleId, string $kind): bool
    {
        return AppNotification::query()
            ->where('recipient_type', 'client')
            ->where('recipient_id', $uid)
            ->where('type', "maintenance_{$kind}")
            ->where('created_at', '>=', now()->subDays(self::REPEAT_AFTER_DAYS))
            ->whereJsonContains('data->owned_vehicle_id', $vehicleId)
            ->exists();
    }

    private function notify(OwnedVehicle $vehicle, string $uid, array $deadline, bool $dryRun): void
    {
        $name = $vehicle->nickname
            ?: trim(($vehicle->brand?->name ?? '') . ' ' . ($vehicle->vehicleModel?->name ?? ''))
            ?: 'Votre vehicule';

        $title = $deadline['overdue']
            ? "{$deadline['label']} depassee"
            : "{$deadline['label']} bientot a echeance";

        $body = $this->body($name, $deadline);

        $payload = [
            'owned_vehicle_id' => $vehicle->id,
            'kind'             => $deadline['kind'],
            'due_on'           => $deadline['due_on'],
            'overdue'          => $deadline['overdue'],
        ];

        if ($dryRun) {
            $this->line("  [{$name}] {$title} — {$body}");

            return;
        }

        AppNotification::notifyClient($uid, "maintenance_{$deadline['kind']}", $title, $body, $payload);

        $tokens = ClientFcmToken::tokensForUid($uid);
        if ($tokens) {
            $this->fcm->sendToTokens($tokens, $title, $body, $payload);
        }
    }

    private function body(string $name, array $deadline): string
    {
        if ($deadline['detail'] !== null) {
            return "{$name} : {$deadline['label']} — {$deadline['detail']}.";
        }

        $days = $deadline['days_left'];

        if ($deadline['overdue']) {
            return "{$name} : {$deadline['label']} expiree depuis " . abs($days) . ' jour(s).';
        }

        return $days === 0
            ? "{$name} : {$deadline['label']} expire aujourd'hui."
            : "{$name} : {$deadline['label']} expire dans {$days} jour(s).";
    }
}
