<?php

namespace Tests\Feature;

use App\Models\OwnedVehicle;
use App\Models\PartCategory;
use App\Models\User;
use App\Models\VehicleCheck;
use Carbon\CarbonImmutable;
use Database\Seeders\VehicleCheckItemsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le controle avant voyage.
 *
 * Toute la fonction repose sur une these : une liste de controle generique ne
 * sert a rien. Elle se remplit une fois, puis s'abandonne — et pire, elle
 * apprend a cocher sans regarder, ce qui vaut moins que de ne rien verifier.
 * Ces tests protegent donc d'abord la composition : qu'un point n'apparaisse
 * jamais sans raison, et qu'il porte cette raison.
 *
 * Deux regles se defendent particulierement mal toutes seules et sont verrouillees
 * ici :
 *
 *   - une donnee manquante ne declenche pas un point. Un vehicule sans
 *     kilometrage saisi ne doit pas voir les points kilometriques « par
 *     precaution » : le proprietaire ne peut pas les juger, et la liste
 *     s'allonge de points qu'il cochera sans lire.
 *
 *   - le verdict ne certifie rien. L'application n'a rien constate : elle a pose
 *     des questions et recopie des reponses. « Rien a signaler sur les treize
 *     points verifies » est vrai ; « votre vehicule est en bon etat » ne l'est
 *     pas, et un test le verifie mot par mot.
 */
class ControleAvantVoyageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(VehicleCheckItemsSeeder::class);
    }

    // ── Outils ───────────────────────────────────────────────────────

    /**
     * Un client. L'identifiant Firebase est unique par defaut : la colonne
     * l'impose, et un test qui cree deux vehicules sans y penser echouerait sur
     * la contrainte plutot que sur son sujet.
     */
    private function client(?string $uid = null): User
    {
        return User::factory()->create([
            'role'         => 'client',
            'firebase_uid' => $uid ?? 'client-'.uniqid(),
        ]);
    }

    private function vehicule(array $attributs = [], ?User $user = null): OwnedVehicle
    {
        return OwnedVehicle::factory()->create(array_merge(
            ['user_id' => ($user ?? $this->client())->id],
            $attributs,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    private function liste(OwnedVehicle $vehicule, ?int $distanceKm = null, ?CarbonImmutable $jour = null): array
    {
        if ($jour !== null) {
            $this->travelTo($jour);
        }

        return app(\App\Services\ControleAvantVoyage::class)->liste($vehicule->fresh()->load([
            'vehicleModel', 'engineType', 'trim.defaultEngineType', 'motorisation',
        ]), $distanceKm, $jour);
    }

    /** @param  array<int, array<string, mixed>>  $liste */
    private function codes(array $liste): array
    {
        return array_column($liste, 'code');
    }

    /** @param  array<int, array<string, mixed>>  $liste */
    private function point(array $liste, string $code): ?array
    {
        foreach ($liste as $ligne) {
            if ($ligne['code'] === $code) {
                return $ligne;
            }
        }

        return null;
    }

    private function moteur(string $code): int
    {
        return \App\Models\EngineType::firstOrCreate(
            ['code' => $code],
            ['label' => $code === 'diesel' ? 'Diesel' : 'Essence', 'uses_fuel' => true, 'uses_battery' => false],
        )->id;
    }

    // ── Composition : le noyau et rien d'autre ───────────────────────

    public function test_le_noyau_est_pose_a_tout_vehicule(): void
    {
        $liste = $this->liste($this->vehicule(['mileage_km' => null]));

        $codes = $this->codes($liste);

        foreach (['papiers-assurance', 'papiers-visite', 'papiers-bord', 'pneus-etat',
            'pneus-secours', 'niveaux-capot', 'niveaux-fuite', 'freins',
            'eclairage-feux', 'visibilite', 'securite-equipement'] as $attendu) {
            $this->assertContains($attendu, $codes, "Le point « {$attendu} » doit etre pose a tout vehicule.");
        }
    }

    public function test_un_point_kilometrique_n_apparait_pas_sans_kilometrage(): void
    {
        // Le seuil de la courroie est 60 000 km. Sans kilometrage saisi, on ne
        // sait pas si on est au-dessus : afficher le point « par precaution »
        // reviendrait a demander un avis que le proprietaire ne peut pas donner.
        $liste = $this->liste($this->vehicule(['mileage_km' => null]), 400);

        $this->assertNotContains('moteur-courroie', $this->codes($liste));
    }

    public function test_la_liste_reste_courte_pour_une_berline_recente(): void
    {
        // C'est la these de la fonction, et elle se perd en une seule migration
        // de contenu : un point ajoute au noyau plutot qu'en conditionnel, et la
        // liste devient une liste generique. Quinze points, c'est deja le haut de
        // ce qu'on remplit en trois minutes.
        $vehicule = $this->vehicule(['manufacturing_year' => 2019, 'mileage_km' => 45_000]);

        $liste = $this->liste($vehicule, 360, CarbonImmutable::parse('2026-09-28'));

        $this->assertLessThanOrEqual(
            15,
            count($liste),
            'Une berline recente ne doit pas recevoir une liste de controle generique.',
        );
    }

    // ── Composition : chaque point porte sa raison ───────────────────

    public function test_la_courroie_apparait_avec_sa_raison_chiffree(): void
    {
        $liste = $this->liste($this->vehicule(['mileage_km' => 190_000]));

        $point = $this->point($liste, 'moteur-courroie');

        $this->assertNotNull($point, 'Passe 60 000 km, la courroie se verifie.');
        $this->assertContains('190 000 km au compteur', $point['reasons']);
        $this->assertSame('blocking', $point['severity']);
    }

    public function test_les_conditions_d_un_point_se_cumulent(): void
    {
        $diesel = $this->moteur('diesel');
        $essence = $this->moteur('petrol');

        // Le filtre a gasoil attend un diesel ET 80 000 km. Les trois cas qui
        // ne remplissent pas les deux conditions doivent rester muets.
        $this->assertNotContains(
            'moteur-filtre-gasoil',
            $this->codes($this->liste($this->vehicule(['engine_type_id' => $essence, 'mileage_km' => 120_000]))),
            'Un moteur a essence n\'a pas de filtre a gasoil.',
        );

        $this->assertNotContains(
            'moteur-filtre-gasoil',
            $this->codes($this->liste($this->vehicule(['engine_type_id' => $diesel, 'mileage_km' => 40_000]))),
            'Un diesel qui n\'a pas roule n\'a pas ce souci.',
        );

        $point = $this->point(
            $this->liste($this->vehicule(['engine_type_id' => $diesel, 'mileage_km' => 120_000])),
            'moteur-filtre-gasoil',
        );

        $this->assertNotNull($point);
        $this->assertContains('Diesel', $point['reasons']);
        $this->assertContains('120 000 km au compteur', $point['reasons']);
    }

    public function test_une_electrique_ne_se_fait_pas_demander_une_courroie(): void
    {
        // Demander de verifier une courroie d'accessoires sur une electrique — et
        // bloquer le depart dessus — decredibilise la liste entiere : celui qui
        // lit ca cesse de croire aux autres points.
        $electrique = $this->vehicule([
            'engine_type_id' => \App\Models\EngineType::firstOrCreate(
                ['code' => 'electric'],
                ['label' => 'Électrique', 'uses_fuel' => false, 'uses_battery' => true],
            )->id,
            'mileage_km' => 190_000, 'manufacturing_year' => 2014,
        ]);

        $codes = $this->codes($this->liste($electrique, 400, CarbonImmutable::parse('2027-01-15')));

        foreach (['moteur-courroie', 'moteur-distribution', 'moteur-filtre-air', 'moteur-refroidissement'] as $thermique) {
            $this->assertNotContains($thermique, $codes, "« {$thermique} » n'existe pas sur une electrique.");
        }

        // Ce qui reste vrai pour elle ne doit pas disparaitre au passage.
        $this->assertContains('pneus-etat', $codes);
        $this->assertContains('moteur-batterie', $codes, 'Une electrique a aussi une batterie 12 V.');
    }

    public function test_un_point_thermique_reste_pose_quand_le_moteur_n_est_pas_saisi(): void
    {
        // L'exclusion ne joue que sur une motorisation connue. La plupart des
        // proprietaires ne saisissent pas leur type de moteur : traiter
        // « inconnu » comme « electrique » priverait presque tout le parc du
        // point sur la courroie.
        $sansMoteur = $this->vehicule(['mileage_km' => 190_000]);

        $this->assertContains('moteur-courroie', $this->codes($this->liste($sansMoteur, 400)));
    }

    public function test_une_echeance_de_demain_se_dit_au_singulier(): void
    {
        $vehicule = $this->vehicule([
            'insurance_expiry' => CarbonImmutable::parse('2026-09-29')->toDateString(),
        ]);

        $point = $this->point(
            $this->liste($vehicule, null, CarbonImmutable::parse('2026-09-28')),
            'papiers-assurance',
        );

        $this->assertSame('Expire demain', $point['prefill_reason']);
    }

    public function test_la_raison_du_pre_remplissage_ne_se_dit_qu_une_fois(): void
    {
        // Elle sortait a la fois dans reasons et dans prefill_reason, et l'ecran
        // affichait donc « Expirée depuis 12 jours » deux fois sur la meme carte.
        $vehicule = $this->vehicule(['insurance_expiry' => now()->subDays(12)->toDateString()]);

        $point = $this->point($this->liste($vehicule), 'papiers-assurance');

        $this->assertSame('Expirée depuis 12 jours', $point['prefill_reason']);
        $this->assertNotContains('Expirée depuis 12 jours', $point['reasons']);
    }

    public function test_un_point_saisonnier_suit_le_mois(): void
    {
        $vehicule = $this->vehicule();

        $point = $this->point(
            $this->liste($vehicule, 100, CarbonImmutable::parse('2027-01-15')),
            'moteur-filtre-air',
        );

        $this->assertNotNull($point, 'En saison seche, la poussiere bouche le filtre a air.');

        // Une saison ne produit aucun chiffre : sans libelle de declenchement, ce
        // point apparaitrait sans aucune raison affichee.
        $this->assertContains('Saison sèche', $point['reasons']);

        $this->assertNotContains(
            'moteur-filtre-air',
            $this->codes($this->liste($vehicule, 100, CarbonImmutable::parse('2026-09-15'))),
        );
    }

    public function test_aucun_point_n_apparait_sans_raison_d_apparaitre(): void
    {
        // Le garde-fou du contenu. Un point ajoute a la liste sans condition et
        // sans etre declare du noyau s'afficherait pour tous les vehicules, en
        // silence, sans porter la moindre raison — et la liste redeviendrait
        // generique sans qu'aucun autre test ne s'en apercoive.
        $fautifs = \App\Models\VehicleCheckItem::query()
            ->actifs()
            ->where('is_core', false)
            ->get()
            ->filter(fn ($point) => $point->prefill_source === null
                && $point->min_trip_distance_km === null
                && $point->min_mileage_km === null
                && $point->min_age_years === null
                && empty($point->engine_codes)
                && empty($point->body_types)
                && empty($point->months))
            ->pluck('code')
            ->all();

        $this->assertSame([], $fautifs, 'Ces points doivent porter une condition, ou rejoindre le noyau.');
    }

    public function test_un_point_conditionnel_annonce_toujours_pourquoi(): void
    {
        // Un pick-up diesel ancien et tres kilometre declenche presque tous les
        // points conditionnels d'un coup : de quoi verifier qu'aucun n'arrive
        // muet.
        $vehicule = OwnedVehicle::factory()
            ->carrosserie('pick-up')
            ->vidangeDans(200, compteur: 240_000)
            ->create([
                'user_id'            => $this->client('tout')->id,
                'manufacturing_year' => 2012,
                'engine_type_id'     => $this->moteur('diesel'),
            ]);

        $liste = $this->liste($vehicule, 400, CarbonImmutable::parse('2027-01-15'));

        $muets = [];
        foreach ($liste as $point) {
            $aUneRaison = $point['reasons'] !== [] || $point['prefill_reason'] !== null;
            if (! $aUneRaison && ! $this->estDuNoyau($point['code'])) {
                $muets[] = $point['code'];
            }
        }

        $this->assertSame([], $muets, 'Un point conditionnel sans raison affichee se coche sans etre lu.');
    }

    private function estDuNoyau(string $code): bool
    {
        return (bool) \App\Models\VehicleCheckItem::query()
            ->where('code', $code)
            ->value('is_core');
    }

    public function test_l_arrimage_ne_concerne_qu_un_vehicule_qui_charge(): void
    {
        $berline = $this->vehicule();
        $pickup  = OwnedVehicle::factory()->carrosserie('pick-up')->create(['user_id' => $this->client('pick')->id]);

        $this->assertNotContains('charge-arrimage', $this->codes($this->liste($berline, 360)));

        $point = $this->point($this->liste($pickup, 360), 'charge-arrimage');
        $this->assertNotNull($point, 'Un pick-up qui part charge doit voir ce point.');
        $this->assertContains('Pick-up', $point['reasons']);
    }

    public function test_la_distance_commande_les_points_de_long_trajet(): void
    {
        $vehicule = $this->vehicule();

        $this->assertNotContains('confort-clim', $this->codes($this->liste($vehicule, 20)));

        $point = $this->point($this->liste($vehicule, 360), 'confort-clim');
        $this->assertNotNull($point);
        $this->assertContains('Trajet de 360 km', $point['reasons']);
    }

    // ── Composition : ce que l'application sait deja ─────────────────

    public function test_une_assurance_expiree_est_pre_remplie_en_defaut(): void
    {
        $vehicule = $this->vehicule(['insurance_expiry' => now()->subDays(12)->toDateString()]);

        $point = $this->point($this->liste($vehicule), 'papiers-assurance');

        $this->assertSame('bad', $point['prefill_status']);
        $this->assertSame('Expirée depuis 12 jours', $point['prefill_reason']);
    }

    public function test_une_assurance_valide_est_pre_remplie_avec_sa_date(): void
    {
        // Pre-remplir n'est pas decider : le proprietaire garde la main, mais il
        // n'a pas a retaper ce que l'application porte deja.
        $vehicule = $this->vehicule(['insurance_expiry' => '2027-03-12']);

        $point = $this->point($this->liste($vehicule, null, CarbonImmutable::parse('2026-09-28')), 'papiers-assurance');

        $this->assertSame('ok', $point['prefill_status']);
        $this->assertSame('Valide jusqu\'au 12/03/2027', $point['prefill_reason']);
    }

    public function test_la_vidange_n_apparait_que_lorsqu_elle_approche(): void
    {
        $loin = OwnedVehicle::factory()->vidangeDans(9_000)->create(['user_id' => $this->client('loin')->id]);
        $this->assertNotContains(
            'entretien-vidange',
            $this->codes($this->liste($loin, 360)),
            'Annoncer une vidange dans 9 000 km avant un voyage n\'apprend rien.',
        );

        $proche = OwnedVehicle::factory()->vidangeDans(600)->create(['user_id' => $this->client('proche')->id]);
        $point = $this->point($this->liste($proche, 200), 'entretien-vidange');
        $this->assertSame('watch', $point['prefill_status']);
        $this->assertSame('Dans 600 km', $point['prefill_reason']);
    }

    public function test_un_trajet_plus_long_que_la_marge_de_vidange_devient_un_defaut(): void
    {
        // Deux cents kilometres avant la vidange et un trajet de 360 : la
        // vidange se fait avant de partir, pas au retour.
        $vehicule = OwnedVehicle::factory()->vidangeDans(200)->create(['user_id' => $this->client('court')->id]);

        $point = $this->point($this->liste($vehicule, 360), 'entretien-vidange');

        $this->assertSame('bad', $point['prefill_status']);
        $this->assertStringContainsString('le trajet en fait 360', $point['prefill_reason']);
    }

    public function test_la_vidange_porte_son_kilometrage_restant_en_nombre(): void
    {
        // Ce nombre n'etait lisible qu'a travers le libelle « Dans 4000 km », que
        // la tache de rappel relisait par expression reguliere : reformuler ce
        // libelle eteignait le rappel de vidange sans que rien ne le signale.
        $vehicule = OwnedVehicle::factory()->vidangeDans(400)->create(['user_id' => $this->client('km')->id]);

        $vidange = collect($vehicule->deadlines())->firstWhere('kind', 'service');

        $this->assertSame(400, $vidange['km_left']);
        $this->assertNull($vidange['days_left'], 'Une vidange ne se compte pas en jours.');
    }

    // ── L'API de composition ─────────────────────────────────────────

    public function test_le_proprietaire_demande_sa_liste(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule(['mileage_km' => 182_000], $user);

        $reponse = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}/check-template?trip_distance_km=360")
            ->assertOk()
            ->assertJsonPath('data.trip_distance_km', 360)
            ->assertJsonPath('data.mileage_km', 182_000);

        $this->assertNotEmpty($reponse->json('data.items'));
    }

    public function test_un_autre_client_ne_demande_pas_la_liste_d_un_vehicule_qui_n_est_pas_le_sien(): void
    {
        $vehicule = $this->vehicule([], $this->client('proprio'));

        $this->actingAsClient($this->client('intrus'))
            ->getJson("/api/my/vehicles/{$vehicule->id}/check-template")
            ->assertForbidden();
    }

    // ── Enregistrement et verdict ────────────────────────────────────

    /** @param  array<string, string>  $reponses  code => etat */
    private function envoyer(User $user, OwnedVehicle $vehicule, array $reponses, array $extra = [])
    {
        $charge = array_merge([
            'answers' => array_map(
                fn ($code, $etat) => ['item_code' => $code, 'status' => $etat],
                array_keys($reponses),
                array_values($reponses),
            ),
        ], $extra);

        return $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/checks", $charge);
    }

    public function test_un_defaut_bloquant_interdit_le_depart(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $this->envoyer($user, $vehicule, [
            'pneus-etat'        => 'bad',   // bloquant
            'papiers-assurance' => 'ok',
            'freins'            => 'ok',
        ])
            ->assertCreated()
            ->assertJsonPath('data.verdict.value', 'blocked')
            ->assertJsonPath('data.verdict.label', 'À régler avant de partir')
            ->assertJsonPath('data.verdict.detail', '1 point à régler avant de partir')
            ->assertJsonPath('data.counts.blocking', 1);
    }

    public function test_un_defaut_sur_un_point_a_surveiller_ne_bloque_pas(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $this->envoyer($user, $vehicule, [
            'visibilite'        => 'bad',   // a surveiller
            'papiers-assurance' => 'ok',
            'pneus-etat'        => 'watch',
        ])
            ->assertCreated()
            ->assertJsonPath('data.verdict.value', 'attention')
            ->assertJsonPath('data.verdict.detail', '2 points à surveiller, rien qui empêche de partir')
            ->assertJsonPath('data.counts.blocking', 0);
    }

    public function test_sans_defaut_le_verdict_ne_certifie_rien(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $reponse = $this->envoyer($user, $vehicule, [
            'papiers-assurance' => 'ok',
            'pneus-etat'        => 'ok',
            'freins'            => 'ok',
        ])
            ->assertCreated()
            ->assertJsonPath('data.verdict.value', 'clear')
            ->assertJsonPath('data.verdict.label', 'Rien à signaler')
            ->assertJsonPath('data.verdict.detail', 'Rien à signaler sur les 3 points vérifiés');

        // L'application n'a rien constate : elle a pose trois questions. Ces
        // formulations lui feraient certifier l'etat du vehicule, ce qu'elle
        // n'est pas en mesure de faire — la nuance est juridique autant
        // qu'honnete.
        $corps = mb_strtolower($reponse->getContent());
        foreach (['bon état', 'bon etat', 'prêt à partir', 'pret a partir', 'en règle', 'conforme'] as $interdit) {
            $this->assertStringNotContainsString(
                $interdit,
                $corps,
                "Le verdict ne doit pas dire « {$interdit} » : l'application ne certifie pas un vehicule.",
            );
        }
    }

    public function test_les_points_rates_disent_ou_trouver_la_piece(): void
    {
        // Un verdict qui compte sans dire quoi faire s'arrete a mi-chemin : un
        // pneu use doit mener au rayon des pneus.
        PartCategory::firstOrCreate(['slug' => 'pneus'], ['name' => 'Pneus']);

        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $reponse = $this->envoyer($user, $vehicule, ['pneus-etat' => 'bad', 'freins' => 'ok']);

        $aReprendre = $reponse->assertCreated()->json('data.to_fix');

        $this->assertCount(1, $aReprendre);
        $this->assertSame('pneus-etat', $aReprendre[0]['item_code']);
        $this->assertTrue($aReprendre[0]['blocking']);
        $this->assertNotNull($aReprendre[0]['part_category_id'], 'Le point doit mener a une categorie de pieces.');
        $this->assertNotNull($aReprendre[0]['help'], 'L\'aide dit ou regarder : elle sert encore apres le controle.');
    }

    // ── Le kilometrage releve ────────────────────────────────────────

    public function test_le_kilometrage_releve_remonte_sur_le_vehicule(): void
    {
        // C'est la contrepartie discrete de la fonction : sans kilometrage tenu
        // a jour, le rappel de vidange ne part jamais — et personne n'ouvre
        // l'application pour saisir un compteur.
        $user = $this->client();
        $vehicule = $this->vehicule(['mileage_km' => 180_000], $user);

        $this->envoyer($user, $vehicule, ['pneus-etat' => 'ok'], ['mileage_km' => 182_400])
            ->assertCreated()
            ->assertJsonPath('data.mileage_km', 182_400);

        $this->assertSame(182_400, $vehicule->fresh()->mileage_km);
    }

    public function test_un_kilometrage_inferieur_ne_fait_pas_reculer_le_compteur(): void
    {
        // Un compteur ne recule pas : un chiffre inferieur est une faute de
        // frappe. L'accepter ferait repartir le calcul de vidange sans que
        // personne ne comprenne pourquoi.
        $user = $this->client();
        $vehicule = $this->vehicule(['mileage_km' => 180_000], $user);

        $this->envoyer($user, $vehicule, ['pneus-etat' => 'ok'], ['mileage_km' => 18_000])
            ->assertCreated();

        $this->assertSame(180_000, $vehicule->fresh()->mileage_km);
    }

    // ── Le reseau hesitant ───────────────────────────────────────────

    public function test_un_envoi_rejoue_n_enregistre_qu_un_controle(): void
    {
        // Un controle se remplit capot ouvert, souvent sans reseau : l'envoi
        // sera rejoue. Sans reprise par cle, l'historique du vehicule
        // compterait deux passages pour un seul controle.
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);
        $reference = '3f1c9a2e-5b7d-4e8a-9c1f-2d6b8e4a7c05';

        $premier = $this->envoyer($user, $vehicule, ['pneus-etat' => 'bad'], ['client_reference' => $reference])
            ->assertCreated();

        $second = $this->envoyer($user, $vehicule, ['pneus-etat' => 'bad'], ['client_reference' => $reference])
            ->assertOk();

        $this->assertSame($premier->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, VehicleCheck::where('owned_vehicle_id', $vehicule->id)->count());
    }

    public function test_deux_reponses_pour_un_meme_point_sont_refusees(): void
    {
        // La table interdit deux reponses pour un meme point. Sans cette
        // validation, l'utilisateur verrait une erreur 500 pour un controle
        // correctement rempli.
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $erreurs = $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/checks", [
                'answers' => [
                    ['item_code' => 'pneus-etat', 'status' => 'ok'],
                    ['item_code' => 'pneus-etat', 'status' => 'bad'],
                ],
            ])
            ->assertStatus(422)
            ->json('errors');

        $this->assertNotEmpty(
            array_filter(array_keys($erreurs), fn ($cle) => str_starts_with($cle, 'answers.')),
            'Le doublon doit etre refuse a la validation, pas par la contrainte d\'unicite.',
        );
    }

    public function test_un_controle_sans_aucun_point_est_refuse(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/checks", ['answers' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors('answers');
    }

    // ── Acces ────────────────────────────────────────────────────────

    public function test_un_autre_client_ne_peut_pas_enregistrer_un_controle(): void
    {
        $vehicule = $this->vehicule([], $this->client('proprio'));

        $this->actingAsClient($this->client('intrus'))
            ->postJson("/api/my/vehicles/{$vehicule->id}/checks", [
                'answers' => [['item_code' => 'pneus-etat', 'status' => 'ok']],
            ])
            ->assertForbidden();
    }

    public function test_un_autre_client_ne_lit_pas_la_fiche_d_un_controle(): void
    {
        $proprio = $this->client('proprio');
        $vehicule = $this->vehicule([], $proprio);
        $id = $this->envoyer($proprio, $vehicule, ['pneus-etat' => 'bad'])->json('data.id');

        $this->actingAsClient($this->client('intrus'))
            ->getJson("/api/my/checks/{$id}")
            ->assertForbidden();
    }

    // ── Historique ───────────────────────────────────────────────────

    public function test_l_historique_ne_liste_que_les_controles_du_vehicule(): void
    {
        $user = $this->client();
        $unVehicule = $this->vehicule([], $user);
        $unAutre = $this->vehicule([], $user);

        $this->envoyer($user, $unVehicule, ['pneus-etat' => 'ok']);
        $this->envoyer($user, $unAutre, ['freins' => 'bad']);

        $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$unVehicule->id}/checks")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.verdict.value', 'clear');
    }

    public function test_le_dernier_controle_apparait_sur_la_fiche_du_garage(): void
    {
        // Le garage doit montrer ce qui reste a reprendre entre deux controles :
        // c'est la seule partie d'un passage qui interesse encore quelqu'un une
        // semaine plus tard.
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $this->envoyer($user, $vehicule, ['pneus-etat' => 'bad', 'visibilite' => 'watch']);

        $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->assertOk()
            ->assertJsonPath('data.last_check.verdict', 'blocked')
            ->assertJsonPath('data.last_check.blocking_count', 1)
            ->assertJsonPath('data.last_check.watch_count', 1);
    }

    public function test_un_vehicule_jamais_controle_ne_promet_rien(): void
    {
        $user = $this->client();
        $vehicule = $this->vehicule([], $user);

        $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->assertOk()
            ->assertJsonPath('data.last_check', null);
    }
}
