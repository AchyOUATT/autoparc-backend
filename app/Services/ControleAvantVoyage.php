<?php

namespace App\Services;

use App\Enums\CheckReason;
use App\Enums\CheckVerdict;
use App\Models\OwnedVehicle;
use App\Models\PartCategory;
use App\Models\VehicleCheck;
use App\Models\VehicleCheckItem;
use App\Support\Saison;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Le controle avant voyage : composer la liste, puis enregistrer le passage.
 *
 * Toute la valeur de la fonction tient dans la composition. Une liste figee de
 * quarante points se remplit une fois puis s'abandonne — et pire, elle apprend a
 * cocher sans regarder, ce qui est plus dangereux que de ne rien verifier du
 * tout. La liste se compose donc a partir de ce que l'application sait deja du
 * vehicule : ses echeances, son millesime, son kilometrage, sa carrosserie, sa
 * motorisation, et la distance annoncee. Une berline essence de 2019 pour 360 km
 * recoit une douzaine de points ; un pick-up diesel de 2013 a 190 000 km en
 * recoit une vingtaine, et chacun porte la raison chiffree qui l'a fait
 * apparaitre.
 *
 * Le verdict est calcule ici et nulle part ailleurs, pour la meme raison que les
 * echeances : un seuil duplique cote application finit par contredire le
 * serveur, et l'utilisateur voit alors deux verites pour un meme vehicule.
 */
class ControleAvantVoyage
{
    /**
     * Au-dela de cette marge, une vidange a venir n'est pas un point de
     * controle : c'est une information que la fiche du vehicule porte deja.
     * En dessous, le trajet consomme ce qui restait, et la faire avant de partir
     * est une visite au garage plutot qu'une panne sur la route.
     */
    private const MARGE_VIDANGE_KM = 1000;

    /** En deca de ce delai, une echeance encore valide merite d'etre signalee. */
    private const ECHEANCE_PROCHE_JOURS = 15;

    private const LIBELLES_CATEGORIES = [
        'papiers'   => 'Papiers',
        'pneus'     => 'Pneus',
        'niveaux'   => 'Niveaux et fuites',
        'freins'    => 'Freins',
        'eclairage' => 'Éclairage et visibilité',
        'moteur'    => 'Moteur',
        'confort'   => 'Climatisation',
        'securite'  => 'Sécurité',
        'charge'    => 'Chargement',
        'voyage'    => 'Avant de démarrer',
    ];

    /**
     * La liste des points a verifier sur ce vehicule, pour ce trajet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liste(
        OwnedVehicle $vehicule,
        ?int $distanceKm = null,
        ?CarbonImmutable $jour = null,
        CheckReason $motif = CheckReason::Trip,
    ): array {
        $jour ??= CarbonImmutable::today();

        // Les deux listes ne se recouvrent pas, et c'est voulu. Un point
        // saisonnier se verifie une fois avant la saison, pas avant chaque
        // trajet ; un point de voyage n'a rien a dire d'une saison qui
        // commence. Melanger les deux allongerait les deux listes de points que
        // personne ne referait.
        $gabarits = VehicleCheckItem::query()->actifs()->get()->filter(
            fn (VehicleCheckItem $g) => $motif === CheckReason::Seasonal
                ? $g->appartientA(Saison::pour($jour))
                : ! $g->estSaisonnier(),
        );

        $retenus = [];

        foreach ($gabarits as $gabarit) {
            $raisons = $gabarit->raisonsPour($vehicule, $distanceKm, $jour);

            if ($raisons === null) {
                continue;
            }

            $preRemplissage = $gabarit->prefill_source === null
                ? null
                : $this->preRemplissage($gabarit->prefill_source, $vehicule, $distanceKm, $jour);

            // Un point sans condition chiffree et hors noyau n'existe que si
            // l'application a quelque chose a dire dessus. C'est le cas de la
            // vidange : l'annoncer « dans 9 000 km » avant un voyage n'apprend
            // rien, et allonge la liste d'un point qu'on cochera sans lire.
            if ($preRemplissage === null && $gabarit->attendUnPreRemplissage()) {
                continue;
            }

            $retenus[] = [
                'gabarit' => $gabarit,
                'raisons' => $raisons,
                'prefill' => $preRemplissage,
            ];
        }

        $categories = $this->categoriesDePieces($retenus);

        return array_map(fn (array $ligne) => [
            'code'           => $ligne['gabarit']->code,
            'category'       => $ligne['gabarit']->category,
            'category_label' => self::LIBELLES_CATEGORIES[$ligne['gabarit']->category] ?? $ligne['gabarit']->category,
            'title'          => $ligne['gabarit']->title,
            'help'           => $ligne['gabarit']->help,
            'severity'       => $ligne['gabarit']->severity,

            // Les raisons ne sont pas decoratives : elles sont ce qui distingue
            // une liste composee d'une liste generique, et ce qui donne envie
            // d'ouvrir le capot plutot que de cocher.
            //
            // La raison du pre-remplissage n'y figure pas : elle sort dans
            // prefill_reason, et l'ecran affichait sinon deux fois « Expirée
            // depuis 12 jours » sur la meme carte.
            'reasons'        => array_values($ligne['raisons']),

            'prefill_status' => $ligne['prefill']['status'] ?? null,
            'prefill_reason' => $ligne['prefill']['reason'] ?? null,

            // L'identifiant est resolu ici : l'application ne peut pas traduire
            // un slug de categorie en identifiant, la route des categories ne
            // renvoyant pas les slugs.
            'part_category_id'   => $categories[$ligne['gabarit']->part_category_slug] ?? null,
            'part_category_slug' => $ligne['gabarit']->part_category_slug,
        ], $retenus);
    }

    /**
     * Enregistre un passage, et en tire le verdict.
     *
     * @param  array<int, array{item_code:string,status:string,note?:?string}>  $reponses
     */
    public function enregistrer(
        OwnedVehicle $vehicule,
        array $reponses,
        ?int $distanceKm = null,
        ?int $kilometrage = null,
        ?string $note = null,
        ?string $referenceClient = null,
        ?CarbonImmutable $effectueLe = null,
        CheckReason $motif = CheckReason::Trip,
    ): VehicleCheck {
        // Un controle se remplit capot ouvert, souvent sans reseau : l'envoi
        // sera rejoue. Sans cette reprise, un reseau hesitant enregistrerait
        // deux passages pour un seul controle, et l'historique du vehicule
        // deviendrait faux.
        if ($referenceClient !== null) {
            $existant = VehicleCheck::query()
                ->where('owned_vehicle_id', $vehicule->id)
                ->where('client_reference', $referenceClient)
                ->first();

            if ($existant !== null) {
                return $existant->load('answers');
            }
        }

        $gabarits = VehicleCheckItem::query()
            ->whereIn('code', array_column($reponses, 'item_code'))
            ->get()
            ->keyBy('code');

        $lignes = [];

        foreach ($reponses as $reponse) {
            $gabarit = $gabarits->get($reponse['item_code']);

            if ($gabarit === null) {
                continue;
            }

            $lignes[] = [
                'item_code' => $gabarit->code,
                'title'     => $gabarit->title,
                'category'  => $gabarit->category,
                'severity'  => $gabarit->severity,
                'status'    => $reponse['status'],
                'note'      => $reponse['note'] ?? null,
            ];
        }

        $verdict = CheckVerdict::depuis($lignes);

        return DB::transaction(function () use ($vehicule, $lignes, $verdict, $distanceKm, $kilometrage, $note, $referenceClient, $effectueLe, $motif) {
            $controle = VehicleCheck::create([
                'owned_vehicle_id' => $vehicule->id,
                'user_id'          => $vehicule->user_id,
                'reason'           => $motif->value,
                'trip_distance_km' => $distanceKm,
                'mileage_km'       => $kilometrage,
                'performed_at'     => $effectueLe ?? now(),
                'verdict'          => $verdict,
                'blocking_count'   => count(array_filter($lignes, fn ($l) => $l['status'] === 'bad' && $l['severity'] === 'blocking')),
                'watch_count'      => count(array_filter($lignes, fn ($l) => $l['status'] === 'watch' || ($l['status'] === 'bad' && $l['severity'] === 'watch'))),
                'checked_count'    => count($lignes),
                'note'             => $note,
                'client_reference' => $referenceClient,
            ]);

            $controle->answers()->createMany($lignes);

            $this->reporterKilometrage($vehicule, $kilometrage);

            return $controle->load('answers');
        });
    }

    /**
     * Les controles que l'application peut proposer aujourd'hui.
     *
     * Le calendrier vit ici et non dans l'application, pour la meme raison que
     * les echeances : une saison decoupee des deux cotes finirait par ne plus
     * tomber au meme mois, et l'application proposerait un controle d'hivernage
     * que le serveur composerait en saison seche.
     *
     * @return array<int, array<string, mixed>>
     */
    public function typesDisponibles(?CarbonImmutable $jour = null): array
    {
        $saison = Saison::pour($jour ?? CarbonImmutable::today());

        return [
            [
                'value' => CheckReason::Trip->value,
                'label' => 'Avant un voyage',
                'hint'  => 'La liste dépend de la distance : un aller-retour en ville et une descente de quatre cents kilomètres ne demandent pas la même chose.',
                'season' => null,
            ],
            [
                'value' => CheckReason::Seasonal->value,
                'label' => $saison->libelleControle(),
                'hint'  => $saison->raison(),
                'season' => $saison->value,
            ],
        ];
    }

    /** Le titre de l'ecran pour ce motif, saison comprise. */
    public function titre(CheckReason $motif, ?CarbonImmutable $jour = null): string
    {
        return $motif === CheckReason::Seasonal
            ? Saison::pour($jour ?? CarbonImmutable::today())->libelleControle()
            : $motif->titreParDefaut();
    }

    /**
     * Les points a reprendre apres un passage, enrichis de quoi agir.
     *
     * Un verdict qui se contente de compter ne sert a rien : « 2 points a
     * regler » demande aussitot « lesquels, et avec quelle piece ». L'aide et la
     * categorie de pieces sont donc relues sur les gabarits — seulement pour les
     * points rates, jamais pour toute la liste, et seulement sur une fiche de
     * controle, jamais sur l'historique.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pointsAReprendre(VehicleCheck $controle): array
    {
        $reponses = $controle->aReprendre()->get();

        if ($reponses->isEmpty()) {
            return [];
        }

        $gabarits = VehicleCheckItem::query()
            ->whereIn('code', $reponses->pluck('item_code'))
            ->get()
            ->keyBy('code');

        $categories = PartCategory::query()
            ->whereIn('slug', $gabarits->pluck('part_category_slug')->filter()->unique())
            ->pluck('id', 'slug')
            ->all();

        return $reponses->map(function ($reponse) use ($gabarits, $categories) {
            $gabarit = $gabarits->get($reponse->item_code);
            $slug = $gabarit?->part_category_slug;

            return [
                'item_code'          => $reponse->item_code,
                'title'              => $reponse->title,
                'category'           => $reponse->category,
                'category_label'     => self::LIBELLES_CATEGORIES[$reponse->category] ?? $reponse->category,
                'severity'           => $reponse->severity,
                'status'             => $reponse->status,
                'note'               => $reponse->note,
                'blocking'           => $reponse->estBloquant(),
                'help'               => $gabarit?->help,
                'part_category_slug' => $slug,
                'part_category_id'   => $slug === null ? null : ($categories[$slug] ?? null),
            ];
        })->all();
    }

    /**
     * Reporte le kilometrage releve sur le vehicule.
     *
     * C'est la contrepartie discrete de la fonction : le rappel de vidange ne
     * part jamais sans un kilometrage tenu a jour, et personne n'ouvre
     * l'application pour saisir un compteur. Un controle avant voyage, lui, se
     * fait devant le tableau de bord.
     *
     * Jamais a la baisse : un compteur ne recule pas. Un chiffre inferieur est
     * une faute de frappe, ou un compteur remplace — dans les deux cas,
     * l'ecraser ferait repartir le calcul de vidange a zero sans que personne
     * ne comprenne pourquoi.
     */
    private function reporterKilometrage(OwnedVehicle $vehicule, ?int $kilometrage): void
    {
        if ($kilometrage === null) {
            return;
        }

        if ($vehicule->mileage_km !== null && $kilometrage <= $vehicule->mileage_km) {
            return;
        }

        $vehicule->update(['mileage_km' => $kilometrage]);
    }

    /**
     * L'etat que l'application peut deja proposer pour un point, et pourquoi.
     *
     * Propose, et ne decide pas : une assurance peut avoir ete renouvelee sans
     * que personne n'ait pense a le saisir ici. Le proprietaire garde la main,
     * mais il n'a pas a retaper ce que l'application sait.
     *
     * @return array{status:string,reason:?string}|null
     */
    private function preRemplissage(string $source, OwnedVehicle $vehicule, ?int $distanceKm, CarbonImmutable $jour): ?array
    {
        if ($source === 'service') {
            $restant = $vehicule->kilometresAvantVidange();

            if ($restant === null) {
                return null;
            }

            if ($restant <= 0) {
                return ['status' => 'bad', 'reason' => 'Dépassée de ' . $this->nombre(abs($restant)) . ' km'];
            }

            // Le trajet consomme ce qui restait : la vidange se fait avant de
            // partir, pas au retour.
            if ($distanceKm !== null && $restant < $distanceKm) {
                return [
                    'status' => 'bad',
                    'reason' => 'Il reste ' . $this->nombre($restant) . ' km avant la vidange, et le trajet en fait ' . $this->nombre($distanceKm),
                ];
            }

            return $restant <= max($distanceKm ?? 0, self::MARGE_VIDANGE_KM)
                ? ['status' => 'watch', 'reason' => 'Dans ' . $this->nombre($restant) . ' km']
                : null;
        }

        $date = $source === 'insurance'
            ? $vehicule->insurance_expiry
            : $vehicule->technical_inspection_expiry;

        if ($date === null) {
            return null;
        }

        $joursRestants = (int) $jour->diffInDays(CarbonImmutable::parse($date), false);

        if ($joursRestants < 0) {
            $depuis = abs($joursRestants);

            return [
                'status' => 'bad',
                'reason' => $depuis === 1 ? 'Expirée depuis hier' : "Expirée depuis {$depuis} jours",
            ];
        }

        if ($joursRestants <= self::ECHEANCE_PROCHE_JOURS) {
            return [
                'status' => 'watch',
                'reason' => match ($joursRestants) {
                    0       => "Expire aujourd'hui",
                    1       => 'Expire demain',
                    default => "Expire dans {$joursRestants} jours",
                },
            ];
        }

        return [
            'status' => 'ok',
            'reason' => 'Valide jusqu\'au ' . CarbonImmutable::parse($date)->format('d/m/Y'),
        ];
    }

    /**
     * Les identifiants de categorie de pieces, par slug.
     *
     * Une seule requete pour toute la liste : descendantIds() charge l'arbre
     * entier a chaque appel, et l'appeler par point de controle le rechargerait
     * quinze fois.
     *
     * @param  array<int, array<string, mixed>>  $retenus
     * @return array<string, int>
     */
    private function categoriesDePieces(array $retenus): array
    {
        $slugs = array_values(array_filter(array_map(
            fn (array $ligne) => $ligne['gabarit']->part_category_slug,
            $retenus,
        )));

        if ($slugs === []) {
            return [];
        }

        return PartCategory::query()
            ->whereIn('slug', array_unique($slugs))
            ->pluck('id', 'slug')
            ->all();
    }

    private function nombre(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ');
    }
}
