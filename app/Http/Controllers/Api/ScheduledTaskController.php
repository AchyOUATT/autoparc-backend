<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * Declencheur externe des taches planifiees.
 *
 * Render ne propose aucun plan gratuit pour les taches cron : leur
 * documentation annonce un minimum d'un dollar par mois et par service. Le
 * render.yaml de ce projet en declarait pourtant une avec `plan: free`, donc
 * elle n'a jamais pu exister — et rien ne le signalait. Pendant ce temps aucun
 * rappel d'entretien n'est parti, alors que des visites techniques etaient
 * depassees depuis des semaines.
 *
 * L'ordonnanceur vit donc chez GitHub Actions, qui est gratuit et surtout
 * OBSERVABLE : chaque execution laisse une trace datee dans l'onglet Actions.
 * C'etait le vrai probleme — pas que la tache ne tournait pas, mais que rien ne
 * permettait de s'en apercevoir.
 *
 * Un declencheur accessible depuis l'exterieur se garde serieusement :
 *
 *   - un secret partage, compare en temps constant ;
 *   - un secret NON CONFIGURE ferme la porte au lieu de l'ouvrir. C'est le
 *     piege classique : env vide, attendu vide, en-tete vide — et tout le monde
 *     passe. Ici l'absence de secret est un refus ;
 *   - une cadence limitee, parce que la tache balaie le parc entier ;
 *   - chaque appel est journalise, accepte ou refuse.
 *
 * La tache reste par ailleurs lancable a la main sur une instance qui offre un
 * shell : cette route ne la remplace pas, elle lui donne une horloge.
 */
class ScheduledTaskController extends Controller
{
    /** Les taches declenchables, et la commande artisan de chacune. */
    private const TACHES = [
        'reminders' => 'reminders:maintenance',
    ];

    public function run(Request $request, string $tache): JsonResponse
    {
        if (! $this->autorise($request)) {
            Log::warning('Declenchement de tache refuse', [
                'tache' => $tache,
                'ip'    => $request->ip(),
            ]);

            return response()->json(['message' => 'Jeton de tache invalide.'], 401);
        }

        $commande = self::TACHES[$tache] ?? null;

        if ($commande === null) {
            return response()->json(['message' => "Tache inconnue : {$tache}."], 404);
        }

        $dryRun = $request->boolean('dry_run');

        $code = Artisan::call($commande, $dryRun ? ['--dry-run' => true] : []);
        $sortie = trim(Artisan::output());

        Log::info('Tache planifiee executee', [
            'tache'   => $tache,
            'dry_run' => $dryRun,
            'code'    => $code,
            'sortie'  => $sortie,
        ]);

        return response()->json([
            'task'     => $tache,
            'command'  => $commande,
            'dry_run'  => $dryRun,
            'exit_code' => $code,

            // Renvoyee telle quelle : c'est elle qui s'affiche dans le journal
            // GitHub Actions, et qui permet de voir combien de rappels sont
            // partis sans avoir a ouvrir quoi que ce soit d'autre.
            'output'   => $sortie,
        ], $code === 0 ? 200 : 500);
    }

    /**
     * Le porteur du secret, et lui seul.
     *
     * hash_equals plutot que === : la comparaison d'une chaine secrete s'arrete
     * au premier caractere different, et ce temps se mesure.
     */
    private function autorise(Request $request): bool
    {
        $attendu = (string) config('services.tasks.secret');

        // Sans secret configure, la route est fermee. L'inverse — accepter
        // parce qu'il n'y a rien a comparer — transformerait un oubli de
        // variable d'environnement en porte ouverte.
        if ($attendu === '') {
            return false;
        }

        $fourni = (string) ($request->header('X-Task-Token') ?? '');

        return $fourni !== '' && hash_equals($attendu, $fourni);
    }
}
