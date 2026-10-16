<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Models\ClientFcmToken;
use App\Models\OwnedVehicle;
use App\Services\FcmService;
use Illuminate\Console\Command;

/**
 * Alerte de surconsommation.
 *
 * Le proprietaire enregistre ses passages a la pompe ; cette tache le previent
 * quand sa consommation sort du regime qu'il avait jusque-la. C'est la
 * contrepartie de la saisie : sans elle, l'application demande un effort a
 * chaque plein et ne rend qu'un nombre que personne ne regarde.
 *
 * Le verdict n'est PAS recalcule ici. Il se lit dans
 * OwnedVehicle::suiviConsommation()['abnormal'], c'est-a-dire dans le meme
 * appel que celui qui alimente 'fuel_tracking' a l'ecran. Le projet a deja paye
 * le prix de la regle recopiee : les kilometres avant vidange n'existaient que
 * dans le libelle « Dans 4 000 km », la tache de rappel les relisait par
 * expression reguliere, et une reformulation du libelle a eteint le rappel sans
 * lever la moindre erreur. Un seuil duplique ici ferait pire : l'ecran dirait
 * « tout va bien » pendant que la notification dit le contraire, sur le meme
 * vehicule et le meme jour.
 *
 * La surete du declenchement ne vient pas d'ici non plus, mais de l'asymetrie
 * posee dans SuiviConsommation : un passage oublie laisse une distance reelle
 * et des litres incomplets, donc un l/100 effondre, que le plancher absolu de
 * 3,0 ou le plancher relatif de la seconde passe ecarte. Un oubli produit un
 * silence.
 *
 * L'asymetrie a UNE exception, et elle ne se corrige pas ici : le DOUBLON. Des
 * litres comptes deux fois tirent le chiffre vers le haut — un demi-plein de
 * 16 L saisi deux fois sur un intervalle de 600 km a 48 L le porte a 64 L, soit
 * 10,7 contre 8,0 — et deux intervalles ainsi gonfles font partir cette tache
 * sur un vehicule qui n'a pas bouge. C'est pourquoi le doublon est arrete a
 * l'entree, par la cle d'idempotence du telephone et par la fenetre de dix
 * minutes de FuelFillController. Le silence promis ci-dessus vaut pour les
 * oublis, pas pour les doubles saisies.
 */
class SendFuelAnomalyAlerts extends Command
{
    protected $signature = 'alerts:fuel-anomaly
                            {--dry-run : Affiche les alertes sans rien envoyer}';

    protected $description = 'Previent les proprietaires dont la consommation sort de son regime habituel';

    /**
     * Deux alertes pour le meme vehicule ne se suivent pas de plus pres que
     * cela. La derive dure des semaines — un filtre a air ne se debouche pas
     * tout seul — donc tant qu'elle n'est pas traitee, la tache quotidienne
     * reverrait le meme vehicule anormal chaque matin. Un mois laisse le temps
     * de passer a l'atelier avant de redemander.
     */
    private const REPEAT_AFTER_DAYS = 30;

    public function __construct(private readonly FcmService $fcm)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $sent = 0;
        $skipped = 0;

        // fuelFills est charge ici parce que suiviConsommation() la parcourt
        // pour chaque vehicule : sans cela, le balayage du parc fait une requete
        // par vehicule. whereHas ecarte d'emblee ceux qui n'ont jamais rien
        // saisi — c'est le gros du parc, et leur verdict est connu d'avance.
        OwnedVehicle::with(['user', 'brand', 'vehicleModel', 'fuelFills', 'motorisation'])
            ->whereHas('fuelFills')
            ->chunkById(100, function ($vehicles) use ($dryRun, &$sent, &$skipped) {
                foreach ($vehicles as $vehicle) {
                    $uid = $vehicle->user?->firebase_uid;

                    // Sans identifiant Firebase, aucun canal pour joindre le
                    // proprietaire : ni notification en base, ni push.
                    if ($uid === null) {
                        continue;
                    }

                    $suivi = $vehicle->suiviConsommation();

                    if (! $suivi['abnormal']) {
                        continue;
                    }

                    if ($this->alreadyAlerted($uid, $vehicle->id)) {
                        $skipped++;
                        continue;
                    }

                    $this->notify($vehicle, $uid, $suivi, $dryRun);
                    $sent++;
                }
            });

        $this->info($dryRun
            ? "{$sent} alerte(s) de consommation a envoyer, {$skipped} deja envoyee(s) recemment. Rien n'a ete emis."
            : "{$sent} alerte(s) de consommation envoyee(s), {$skipped} ignoree(s) car deja envoyees recemment.");

        return self::SUCCESS;
    }

    /**
     * Calque de SendMaintenanceReminders::alreadyReminded(), volontairement :
     * c'est la meme garantie, et elle repose sur le meme detail fragile — la
     * valeur comparee dans le JSON doit etre du meme type que celle ecrite.
     */
    private function alreadyAlerted(string $uid, int $vehicleId): bool
    {
        return AppNotification::query()
            ->where('recipient_type', 'client')
            ->where('recipient_id', $uid)
            ->where('type', 'fuel_anomaly')
            ->where('created_at', '>=', now()->subDays(self::REPEAT_AFTER_DAYS))
            ->whereJsonContains('data->owned_vehicle_id', $vehicleId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $suivi
     */
    private function notify(OwnedVehicle $vehicle, string $uid, array $suivi, bool $dryRun): void
    {
        $name = $vehicle->nickname
            ?: trim(($vehicle->brand?->name ?? '') . ' ' . ($vehicle->vehicleModel?->name ?? ''))
            ?: 'Votre vehicule';

        // Le chiffre QUI A TRANCHE, et non celui de l'ecran. 'l_100km' porte
        // les cinq derniers intervalles, le verdict se prend sur les DEUX
        // derniers : sur la serie 8 8 8 8 11 11, 'l_100km' vaut 9,2 et le corps
        // annoncait « 9,2 l/100 km, contre 8,0 d'habitude » — un ecart de 1,2
        // sous le titre « Consommation en hausse », c'est-a-dire une alerte que
        // ses propres chiffres contredisent. Pire sur 8 8 8 8 3,1 3,1 11 11 :
        // 'l_100km' tombait a 7,2 et la notification annoncait une BAISSE.
        //
        // Jamais nul quand 'abnormal' est vrai : le verdict exige six
        // intervalles retenus, et les deux derniers font au moins 50 km chacun,
        // donc tauxDeLAlerte() a toujours une distance a diviser.
        $actuel = (float) $suivi['alerte_l_100km'];
        $base   = (float) $suivi['baseline_l_100km'];

        $title = 'Consommation en hausse';

        // Le corps dit quoi faire, et pas seulement que quelque chose cloche.
        // Une alerte qui se contente de constater se lit une fois puis se
        // desactive ; les deux causes citees sont celles qu'un proprietaire
        // peut verifier le jour meme, et ce sont aussi celles qui menent au
        // catalogue de pieces — c'est la raison d'etre de l'alerte ici.
        $body = "{$name} : {$this->nombre($actuel)} l/100 km, contre "
            . "{$this->nombre($base)} l/100 km d'habitude. Faites vérifier le filtre à air "
            . 'et la pression des pneus — les pièces sont au catalogue.';

        // `type` est la convention du projet : c'est sur lui que l'application
        // aiguille le tap, aussi bien depuis la liste que depuis le push.
        //
        // `owned_vehicle_id` reste un entier : c'est la valeur que relit
        // alreadyAlerted() via whereJsonContains, et un passage en chaine ferait
        // echouer la comparaison, donc le dedoublonnage — sans rien lever.
        // Les deux chiffres, et pas seulement celui du verdict : l'ecran
        // ouvert par le tap affiche 'l_100km', donc le payload doit le porter
        // sous le meme nom, sinon la notification et la fiche se contrediraient
        // a un clic d'intervalle. 'alerte_l_100km' est celui que le corps cite.
        $payload = [
            'type'             => 'fuel_anomaly',
            'owned_vehicle_id' => $vehicle->id,
            'l_100km'          => $suivi['l_100km'],
            'alerte_l_100km'   => $actuel,
            'baseline_l_100km' => $base,
        ];

        if ($dryRun) {
            $this->line("  [{$name}] {$title} — {$body}");

            return;
        }

        AppNotification::notifyClient($uid, 'fuel_anomaly', $title, $body, $payload);

        $tokens = ClientFcmToken::tokensForUid($uid);
        if ($tokens) {
            $this->fcm->sendToTokens($tokens, $title, $body, $payload);
        }
    }

    /** Virgule decimale : « 8,2 » et non « 8.2 », comme partout a l'ecran. */
    private function nombre(float $valeur): string
    {
        return number_format($valeur, 1, ',', ' ');
    }
}
