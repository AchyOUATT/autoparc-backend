<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\FuelFill;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * L'API des passages a la pompe, de bout en bout : middleware Firebase, liaison
 * de modele, policy, validation, ressource.
 *
 * Comme GarageTest, ces tests traversent le vrai middleware plutot que de le
 * desactiver. Le defaut qui avait mis un ecran entier hors service dans ce
 * projet — apiResource nommant {vehicle} la ou le controleur attend
 * $ownedVehicle — vivait dans l'assemblage et non dans une methode isolee :
 * aucun test unitaire ne l'aurait vu.
 *
 * Les nombres sont poses a la main, jamais tires au hasard : 47 litres sur
 * 600 km font 7,8 l/100 km, et ce 7,8 doit sortir de l'API tel quel. Une
 * factory aleatoire aurait oblige a recalculer l'attendu avec le code teste,
 * c'est-a-dire a ne plus rien tester.
 */
class PleinsApiTest extends TestCase
{
    use RefreshDatabase;

    private function vehiculeDe(User $user, int $compteur = 96000): OwnedVehicle
    {
        $brand = Brand::create([
            'name' => 'Toyota '.$user->id, 'slug' => 'toyota-'.$user->id, 'is_active' => true,
        ]);
        $model = VehicleModel::create([
            'brand_id' => $brand->id, 'name' => 'Corolla', 'slug' => 'corolla-'.$user->id, 'is_active' => true,
        ]);

        return OwnedVehicle::create([
            'user_id'            => $user->id,
            'brand_id'           => $brand->id,
            'vehicle_model_id'   => $model->id,
            'manufacturing_year' => 2018,
            'nickname'           => 'La Corolla',
            'mileage_km'         => $compteur,
        ]);
    }

    private function passage(OwnedVehicle $vehicule, int $compteur, float $litres, bool $plein, ?int $montant = null): FuelFill
    {
        return FuelFill::create([
            'owned_vehicle_id' => $vehicule->id,
            'mileage_km'       => $compteur,
            'litres'           => $litres,
            'is_full'          => $plein,
            'amount_fcfa'      => $montant,
        ]);
    }

    // ── Acces ────────────────────────────────────────────────────────────────

    public function test_les_passages_exigent_un_jeton(): void
    {
        $vehicule = $this->vehiculeDe(User::factory()->create(['role' => 'client']));

        $this->getJson("/api/my/vehicles/{$vehicule->id}/fuel-fills")->assertUnauthorized();
    }

    /**
     * Le cas qui compte vraiment : on ne lit ni n'ecrit les pleins d'un autre.
     *
     * Les litres d'un voisin fausseraient sa moyenne autant que la notre, et le
     * compteur saisi dirait ou il roule.
     */
    public function test_on_ne_touche_pas_aux_passages_du_vehicule_d_autrui(): void
    {
        $voisin   = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-voisin']);
        $vehicule = $this->vehiculeDe($voisin);
        $passage  = $this->passage($vehicule, 96000, 41.0, true);

        $intrus = User::factory()->create(['role' => 'client', 'firebase_uid' => 'uid-intrus']);

        $this->actingAsClient($intrus)
            ->getJson("/api/my/vehicles/{$vehicule->id}/fuel-fills")
            ->assertForbidden();

        $this->actingAsClient($intrus)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 96500, 'litres' => 40, 'is_full' => true,
            ])
            ->assertForbidden();

        $this->actingAsClient($intrus)
            ->deleteJson("/api/my/fuel-fills/{$passage->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('fuel_fills', ['id' => $passage->id]);
    }

    // ── Enregistrement ───────────────────────────────────────────────────────

    public function test_on_enregistre_un_plein_et_un_demi_plein(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $plein = $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km'      => 96000,
            'litres'          => 41.2,
            'is_full'         => true,
            'amount_fcfa'     => 30000,
            'price_per_litre' => 728,
            'filled_at'       => '2026-10-12',
            'note'            => 'Station Total Ouaga 2000',
        ])->assertCreated();

        $plein->assertJsonPath('data.is_full', true);
        $plein->assertJsonPath('data.filled_at', '2026-10-12');

        // Le premier plein ouvre la chaine : il ne ferme rien, donc pas de
        // chiffre. C'est la que se logerait une division par la distance depuis
        // un compteur inconnu.
        $plein->assertJsonPath('data.l_100km', null);
        $plein->assertJsonPath('data.distance_km', null);
        $plein->assertJsonPath('data.retenu', false);

        $demi = $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km'  => 96300,
            'litres'      => 10,
            'is_full'     => false,
            'amount_fcfa' => 7280,
        ])->assertCreated();

        // Un demi-plein ne borne jamais un intervalle : ses litres comptent,
        // mais « la moitie du reservoir » n'est pas un niveau qu'un conducteur
        // sait reproduire, donc il ne peut pas servir de borne.
        $demi->assertJsonPath('data.is_full', false);
        $demi->assertJsonPath('data.l_100km', null);
        $demi->assertJsonPath('data.retenu', false);
        $demi->assertJsonPath('data.motif_ecart', null);
    }

    /**
     * Les litres du demi-plein entrent dans la mesure du plein suivant.
     *
     * 10 L au demi-plein plus 47 L au plein, sur 600 km : 57 x 100 / 600 = 9,5.
     * Les ecarter aurait rendu 7,8 — un chiffre trop beau, et faux d'un quart.
     */
    public function test_le_demi_plein_est_compte_dans_l_intervalle_du_plein_suivant(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->passage($vehicule, 96000, 41.0, true);
        $this->passage($vehicule, 96300, 10.0, false);

        $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km' => 96600, 'litres' => 47, 'is_full' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.distance_km', 600)
            ->assertJsonPath('data.l_100km', 9.5)
            ->assertJsonPath('data.retenu', true);
    }

    /**
     * Le compteur du vehicule suit celui de la pompe.
     *
     * Le meme nombre saisi a deux endroits qui divergent est un defaut. Effet
     * voulu : le rappel de vidange depend de mileage_km, donc il se nourrit
     * desormais de chaque passage sans que le proprietaire ait a y penser.
     */
    public function test_un_passage_releve_le_compteur_du_vehicule(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user, 96000);

        $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km' => 97400, 'litres' => 41.2, 'is_full' => true,
        ])->assertCreated();

        $this->assertSame(97400, $vehicule->fresh()->mileage_km);
    }

    /**
     * Un passage saisi en retard ne fait pas reculer le vehicule.
     *
     * On saisit le soir le passage du matin, et parfois celui de la semaine
     * derniere : son compteur est alors plus bas que le dernier releve, et
     * l'abaisser remettrait le rappel de vidange en arriere.
     */
    public function test_un_passage_plus_ancien_ne_rabaisse_pas_le_compteur(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user, 97400);

        $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km' => 96800, 'litres' => 38, 'is_full' => false,
        ])->assertCreated();

        $this->assertSame(97400, $vehicule->fresh()->mileage_km);
    }

    /**
     * Le rejeu apres coupure retrouve son passage au lieu d'en creer un second.
     *
     * La saisie se fait debout a la station : le reseau y est mauvais et
     * l'envoi sera rejoue. Sans cette reprise, un pouce impatient doublerait
     * les litres du mois — et la consommation affichee avec.
     */
    public function test_le_rejeu_par_client_reference_ne_cree_pas_de_doublon(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $envoi = [
            'mileage_km'       => 97400,
            'litres'           => 41.2,
            'is_full'          => true,
            'client_reference' => 'pompe-2026-10-12-a',
        ];

        $premier = $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", $envoi)
            ->assertCreated();

        // 200 et non 201 : l'application doit savoir qu'elle n'a rien cree.
        $rejeu = $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", $envoi)
            ->assertOk();

        $this->assertSame($premier->json('data.id'), $rejeu->json('data.id'));
        $this->assertSame(1, FuelFill::where('owned_vehicle_id', $vehicule->id)->count());
    }

    /**
     * LE DOUBLON SANS CLE : le serveur se defend tout seul.
     *
     * La cle d'idempotence ne survit pas a la fermeture de la boite de saisie.
     * Le proprietaire sans reseau ferme, ressaisit, et le second envoi porte
     * une cle NEUVE : pour le serveur, deux passages distincts.
     *
     * Le vehicule est pose a 8,0 l/100 sur six intervalles de 600 km, dont les
     * deux derniers contiennent un demi-plein de 16 L (16 + 32 = 48 L). Chaque
     * demi-plein est ressaisi une fois. Sans cette defense, les deux derniers
     * intervalles passent a 64 L sur 600 km, soit 10,7 l/100 : ils franchissent
     * les deux seuils (8,0 x 1,25 = 10,0 et 8,0 + 1,5 = 9,5), `abnormal`
     * devient vrai et l'alerte part sur un vehicule dont la consommation n'a
     * pas bouge d'un dixieme. Un doublon est le seul defaut de saisie qui tire
     * le chiffre vers le HAUT — l'asymetrie qui protege l'alerte ne couvre que
     * les oublis.
     */
    public function test_un_doublon_sans_cle_est_rattrape_par_le_serveur(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user, 90000);

        foreach ([
            [90000, 48.0, true],
            [90600, 48.0, true],
            [91200, 48.0, true],
            [91800, 48.0, true],
            [92400, 48.0, true],
            [92900, 16.0, false],
            [93000, 32.0, true],
            [93500, 16.0, false],
            [93600, 32.0, true],
        ] as [$compteur, $litres, $plein]) {
            $this->passage($vehicule, $compteur, $litres, $plein);
        }

        $avant = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->json('data.fuel_tracking');

        // assertEquals et non assertSame : json_encode ecrit 8 pour le float
        // 8.0, et c'est bien ce que le telephone recoit.
        $this->assertEquals(8.0, $avant['l_100km']);
        $this->assertFalse($avant['abnormal']);

        // Les deux ressaisies, sans cle : rien ne les relie au premier envoi
        // sinon leur contenu.
        foreach ([92900, 93500] as $compteur) {
            $reponse = $this->actingAsClient($user)
                ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                    'mileage_km' => $compteur, 'litres' => 16, 'is_full' => false,
                ])
                ->assertOk(); // 200 : rien n'a ete cree

            $this->assertSame(
                FuelFill::where('owned_vehicle_id', $vehicule->id)->where('mileage_km', $compteur)->value('id'),
                $reponse->json('data.id'),
                'Le serveur doit rendre le passage deja en base, pas un second.',
            );
        }

        $this->assertSame(9, FuelFill::where('owned_vehicle_id', $vehicule->id)->count());

        $apres = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->json('data.fuel_tracking');

        $this->assertEquals(8.0, $apres['l_100km'], 'Deux doublons auraient porte les deux derniers intervalles a 10,7.');
        $this->assertFalse($apres['abnormal'], 'Et fait partir l\'alerte sur un vehicule stable.');
    }

    /**
     * Ce que la fenetre de dix minutes laisse passer, et c'est assume.
     *
     * Une ressaisie faite le soir pour un envoi du matin n'est plus un doublon
     * aux yeux du serveur : elle cree une seconde ligne. Dix minutes ne
     * couvrent que le geste repete dans la foulee — allonger la fenetre
     * finirait par avaler des passages reels, et c'est la cle du telephone qui
     * couvre le rejeu differe.
     */
    public function test_la_meme_saisie_onze_minutes_plus_tard_cree_une_ligne(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $envoi = ['mileage_km' => 97400, 'litres' => 41.2, 'is_full' => true];

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", $envoi)
            ->assertCreated();

        $this->travel(11)->minutes();

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", $envoi)
            ->assertCreated();

        $this->assertSame(2, FuelFill::where('owned_vehicle_id', $vehicule->id)->count());
    }

    /**
     * La date du jour a defaut de date envoyee.
     *
     * La colonne est nullable, le champ facultatif, et l'application ne
     * l'envoyait pas : filled_at restait NULL pour TOUT passage saisi depuis le
     * telephone, l'historique n'affichait aucune date et last_filled_at valait
     * null en permanence. Le repli est le jour du SERVEUR — approximatif d'un
     * jour pour une saisie de fin de soiree — et c'est quand meme mieux qu'une
     * colonne vide. La date du telephone, elle, reste prioritaire : c'est lui
     * qui connait le fuseau du proprietaire.
     */
    public function test_un_passage_sans_date_prend_le_jour_du_serveur(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400, 'litres' => 41.2, 'is_full' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.filled_at', today()->toDateString());

        $this->assertNotNull(
            FuelFill::where('owned_vehicle_id', $vehicule->id)->value('filled_at'),
            'Une colonne vide prive l\'historique de toute date.',
        );

        $this->assertSame(
            today()->toDateString(),
            $this->actingAsClient($user)
                ->getJson("/api/my/vehicles/{$vehicule->id}")
                ->json('data.fuel_tracking.last_filled_at'),
            'last_filled_at valait null en permanence, alors que le contrat l\'illustre par une date.',
        );
    }

    /** La date envoyee par le telephone l'emporte sur le repli du serveur. */
    public function test_la_date_envoyee_l_emporte_sur_le_repli(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $hier = now()->subDay()->toDateString();

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400, 'litres' => 41.2, 'is_full' => true, 'filled_at' => $hier,
            ])
            ->assertCreated()
            ->assertJsonPath('data.filled_at', $hier);
    }

    // ── Validation ───────────────────────────────────────────────────────────

    public function test_un_passage_de_zero_litre_est_refuse(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400, 'litres' => 0, 'is_full' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('litres');
    }

    /**
     * La nature du passage n'est jamais devinee.
     *
     * Un serveur qui completerait le champ manquant fermerait les mesures sur
     * des demi-pleins : chaque intervalle se bornerait sur un niveau de
     * reservoir inconnu, et le l/100 affiche serait faux sans qu'aucune erreur
     * ne le dise. Le refus bruyant est le seul comportement sur.
     */
    public function test_un_passage_sans_is_full_est_refuse(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400, 'litres' => 41.2,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('is_full');

        $this->assertSame(0, FuelFill::count());
    }

    /** Un demi-plein explicite passe : false n'est pas « absent ». */
    public function test_is_full_a_false_est_une_reponse_valide(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400, 'litres' => 13, 'is_full' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.is_full', false);
    }

    public function test_une_date_future_est_refusee(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->actingAsClient($user)
            ->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
                'mileage_km' => 97400,
                'litres'     => 41.2,
                'is_full'    => true,
                'filled_at'  => now()->addDay()->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('filled_at');
    }

    // ── Lecture et suppression ───────────────────────────────────────────────

    /**
     * La liste porte le chiffre de chaque intervalle, et le motif des ecartes.
     *
     * Quatre passages, et deux comportements a prouver cote API.
     *
     * Le plein de 96 020 n'est qu'a 20 km du precedent : il ne ferme rien, il
     * PROLONGE. Ses 2 litres rejoignent ceux du plein de 96 600, et l'intervalle
     * publie va de 96 000 a 96 600 — 47 L sur 600 km, soit 7,8 l/100 km. La
     * ligne de 96 020 ne porte donc ni chiffre ni motif : elle n'a rien a
     * expliquer, ses litres sont comptes ailleurs. Auparavant elle affichait
     * « trop court » et ces 20 km de mesure etaient perdus.
     *
     * Le plein de 99 200, lui, est a 2 600 km : au-dela de 2 000 km, rien ne
     * garantit qu'aucun passage n'a ete oublie entre les deux, et le motif est
     * expose plutot que tu — un chiffre qui disparait sans explication passe
     * pour une panne.
     */
    public function test_la_liste_rend_les_chiffres_et_les_motifs(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->passage($vehicule, 96000, 40.0, true);
        $this->passage($vehicule, 96020, 2.0, true);
        $this->passage($vehicule, 96600, 45.0, true);
        $this->passage($vehicule, 99200, 190.0, true);

        $reponse = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}/fuel-fills")
            ->assertOk();

        // Par compteur decroissant : le passage le plus recent en tete.
        $reponse->assertJsonPath('data.0.mileage_km', 99200);
        $reponse->assertJsonPath('data.0.distance_km', 2600);
        $reponse->assertJsonPath('data.0.retenu', false);
        $reponse->assertJsonPath('data.0.motif_ecart', 'trop_long');

        $reponse->assertJsonPath('data.1.mileage_km', 96600);
        $reponse->assertJsonPath('data.1.distance_km', 600);
        $reponse->assertJsonPath('data.1.l_100km', 7.8);
        $reponse->assertJsonPath('data.1.retenu', true);
        $reponse->assertJsonPath('data.1.motif_ecart', null);

        // Le plein absorbe : aucune mesure n'a ete jetee, elle a simplement ete
        // reportee sur la ligne qui a ferme l'intervalle.
        $reponse->assertJsonPath('data.2.mileage_km', 96020);
        $reponse->assertJsonPath('data.2.distance_km', null);
        $reponse->assertJsonPath('data.2.retenu', false);
        $reponse->assertJsonPath('data.2.motif_ecart', null);

        $reponse->assertJsonPath('data.3.mileage_km', 96000);
        $reponse->assertJsonPath('data.3.motif_ecart', null);

        // decimal, donc une CHAINE : c'est l'incident qui avait fait tomber le
        // catalogue entier cote mobile. La fixture du contrat doit refleter
        // cela, pas le masquer.
        $this->assertIsString($reponse->json('data.0.litres'));
    }

    public function test_on_supprime_un_passage_fautif(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);
        $passage  = $this->passage($vehicule, 974000, 41.0, true);

        $this->actingAsClient($user)
            ->deleteJson("/api/my/fuel-fills/{$passage->id}")
            ->assertOk();

        // Pas de SoftDeletes : la ligne disparait vraiment, sinon elle
        // continuerait de borner un intervalle que plus personne ne voit.
        $this->assertDatabaseMissing('fuel_fills', ['id' => $passage->id]);
    }

    // ── La cle 'fuel_tracking' de la fiche vehicule ──────────────────────────

    /**
     * La cle est la meme sur la liste et sur la fiche, et elle y est toujours.
     *
     * C'est le point ou une relation oubliee a un seul endroit rendrait la cle
     * juste ailleurs et fausse ici, sans aucune erreur pour le signaler.
     */
    public function test_fuel_tracking_est_present_partout_meme_sans_passage(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        foreach ([
            $this->actingAsClient($user)->getJson('/api/my/vehicles')->json('data.0.fuel_tracking'),
            $this->actingAsClient($user)->getJson("/api/my/vehicles/{$vehicule->id}")->json('data.fuel_tracking'),
        ] as $suivi) {
            $this->assertIsArray($suivi, 'Une cle absente ne distingue pas « aucun passage » de « pas demande ».');
            $this->assertNull($suivi['l_100km']);
            $this->assertSame(0, $suivi['total_fills']);
            // assertEquals et non assertSame : json_encode rend le float 0.0
            // comme « 0 », et c'est exactement ce que le telephone recoit. Le
            // modele Dart lit ces champs avec un parseur tolerant ; exiger ici
            // un float que le reseau ne transporte pas ne protegerait rien.
            $this->assertEquals(['litres' => 0, 'amount_fcfa' => 0, 'distance_km' => 0], $suivi['totals']);
        }
    }

    /**
     * 41 L puis 47 L sur 600 km : 47 x 100 / 600 = 7,83, soit 7,8 l/100 km.
     * Les 30 000 FCFA du plein de fin sur ces 600 km font 5 000 FCFA aux 100.
     *
     * Les litres du plein d'ouverture n'entrent PAS dans l'intervalle : ils
     * appartiennent a celui d'avant, qui n'existe pas ici.
     */
    public function test_fuel_tracking_rend_la_consommation_mesuree(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->passage($vehicule, 96000, 41.0, true, 29000);
        $this->passage($vehicule, 96600, 47.0, true, 30000);

        $suivi = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->assertOk()
            ->json('data.fuel_tracking');

        $this->assertSame(7.8, $suivi['l_100km']);
        $this->assertSame(5000, $suivi['fcfa_100km']);
        $this->assertSame(1, $suivi['based_on_fills']);
        $this->assertSame(2, $suivi['total_fills']);
        $this->assertSame(2, $suivi['full_fills']);
        $this->assertSame(0, $suivi['excluded_intervals']);
        $this->assertSame(96600, $suivi['last_mileage_km']);
        $this->assertFalse($suivi['abnormal']);

        // Les faits bruts, toujours calcules : 88 litres achetes, 59 000 FCFA
        // depenses, 600 km entre le premier et le dernier passage.
        $this->assertEquals(
            ['litres' => 88, 'amount_fcfa' => 59000, 'distance_km' => 600],
            $suivi['totals'],
        );
    }

    /**
     * Le passage enregistre nourrit immediatement la fiche du vehicule.
     *
     * La relation est relue apres l'ecriture : sans cela le chiffre de la
     * reponse ignorerait le passage qu'on vient de creer, et l'application
     * afficherait un ecran d'un coup de retard.
     */
    public function test_le_chiffre_tient_compte_du_passage_qui_vient_d_etre_cree(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->passage($vehicule, 96000, 41.0, true);

        $this->actingAsClient($user)->postJson("/api/my/vehicles/{$vehicule->id}/fuel-fills", [
            'mileage_km' => 96600, 'litres' => 47, 'is_full' => true,
        ])->assertCreated()->assertJsonPath('data.l_100km', 7.8);

        $this->assertSame(
            7.8,
            $this->actingAsClient($user)
                ->getJson("/api/my/vehicles/{$vehicule->id}")
                ->json('data.fuel_tracking.l_100km'),
        );
    }

    /**
     * Ce qu'une suppression detruirait, compte par 'bornes_intervalles'.
     *
     * Supprimer un plein CORRECT ne retire pas une mesure : il en FUSIONNE
     * deux, et la fusion ment. Mesure — 90 000 / 40 L, 90 600 / 48 L,
     * 91 200 / 50 L affichent 8,2 l/100 km ; en effacant celui du milieu il ne
     * reste qu'un intervalle de 1 200 km pour 50 L, soit 4,2, que l'ecran
     * presente comme une mesure valide. Le plein du milieu borne DEUX
     * intervalles retenus, les deux autres un seul, et le demi-plein aucun :
     * c'est ce nombre-la que le mobile lit pour rediger sa confirmation.
     */
    public function test_bornes_intervalles_dit_ce_qu_une_suppression_detruirait(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user, 91200);

        $this->passage($vehicule, 90000, 40.0, true);
        $milieu = $this->passage($vehicule, 90600, 48.0, true);
        $this->passage($vehicule, 90900, 5.0, false);
        $this->passage($vehicule, 91200, 45.0, true);

        $liste = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}/fuel-fills")
            ->assertOk();

        // Par compteur decroissant : 91 200, 90 900, 90 600, 90 000.
        $liste->assertJsonPath('data.0.bornes_intervalles', 1); // ferme le second, n'en ouvre aucun
        $liste->assertJsonPath('data.1.bornes_intervalles', 0); // un demi-plein ne borne rien
        $liste->assertJsonPath('data.2.bornes_intervalles', 2); // ferme le premier ET ouvre le second
        $liste->assertJsonPath('data.3.bornes_intervalles', 1); // ouvre le premier

        $avant = $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}")
            ->json('data.fuel_tracking.l_100km');

        // 48 L sur 600 km puis 50 L sur 600 km : 98 L sur 1 200, soit 8,2.
        $this->assertEquals(8.2, $avant);

        $this->actingAsClient($user)
            ->deleteJson("/api/my/fuel-fills/{$milieu->id}")
            ->assertOk();

        // Et voila le chiffre divise par deux, retenu et plausible. C'est
        // exactement ce que l'avertissement doit empecher d'atteindre sans
        // confirmation.
        $this->assertEquals(
            4.2,
            $this->actingAsClient($user)
                ->getJson("/api/my/vehicles/{$vehicule->id}")
                ->json('data.fuel_tracking.l_100km'),
        );
    }

    /** Un intervalle ECARTE ne compte pas : le supprimer ne coute aucune mesure. */
    public function test_un_passage_qui_ne_borne_que_des_intervalles_ecartes_compte_pour_zero(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user, 99200);

        $this->passage($vehicule, 96000, 40.0, true);
        $this->passage($vehicule, 99200, 190.0, true); // 3 200 km : 'trop_long'

        $this->actingAsClient($user)
            ->getJson("/api/my/vehicles/{$vehicule->id}/fuel-fills")
            ->assertOk()
            ->assertJsonPath('data.0.motif_ecart', 'trop_long')
            ->assertJsonPath('data.0.bornes_intervalles', 0)
            ->assertJsonPath('data.1.bornes_intervalles', 0);
    }

    /**
     * Les cles nouvelles du suivi sont sur les QUATRE reponses qui rendent un
     * vehicule.
     *
     * C'est le point ou la relation fuelFills oubliee a un seul endroit rendrait
     * la cle juste ailleurs et fausse ici, sans aucune erreur pour le signaler
     * — seulement une requete par vehicule a la lenteur de la liste.
     */
    public function test_alerte_et_serie_rompue_sont_sur_index_show_store_et_update(): void
    {
        $user     = User::factory()->create(['role' => 'client']);
        $vehicule = $this->vehiculeDe($user);

        $this->passage($vehicule, 96000, 41.0, true);
        $this->passage($vehicule, 96600, 47.0, true);

        $reponses = [
            'index'  => $this->actingAsClient($user)->getJson('/api/my/vehicles')
                ->assertOk()->json('data.0.fuel_tracking'),
            'show'   => $this->actingAsClient($user)->getJson("/api/my/vehicles/{$vehicule->id}")
                ->assertOk()->json('data.fuel_tracking'),
            'store'  => $this->actingAsClient($user)->postJson('/api/my/vehicles', [
                'brand_id'           => $vehicule->brand_id,
                'vehicle_model_id'   => $vehicule->vehicle_model_id,
                'manufacturing_year' => 2019,
            ])->assertCreated()->json('data.fuel_tracking'),
            'update' => $this->actingAsClient($user)->putJson("/api/my/vehicles/{$vehicule->id}", [
                'brand_id'           => $vehicule->brand_id,
                'vehicle_model_id'   => $vehicule->vehicle_model_id,
                'manufacturing_year' => 2018,
                'nickname'           => 'La Corolla bis',
            ])->assertOk()->json('data.fuel_tracking'),
        ];

        foreach ($reponses as $route => $suivi) {
            $this->assertArrayHasKey('alerte_l_100km', $suivi, "Cle absente sur « {$route} ».");
            $this->assertArrayHasKey('series_rompue', $suivi, "Cle absente sur « {$route} ».");
        }

        // Sur un vehicule stable, le chiffre du verdict est nul — il n'y a pas
        // eu de verdict — et la chaine n'a pas ete rompue.
        $this->assertNull($reponses['show']['alerte_l_100km']);
        $this->assertFalse($reponses['show']['series_rompue']);
    }
}
