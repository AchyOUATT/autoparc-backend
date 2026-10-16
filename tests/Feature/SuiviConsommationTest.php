<?php

namespace Tests\Feature;

use App\Models\FuelFill;
use App\Models\Motorisation;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Services\SuiviConsommation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le suivi de consommation : des passages a la pompe vers un l/100 km.
 *
 * Ce que ces tests protegent tient en trois points, et aucun ne se defend tout
 * seul une fois le code ecrit.
 *
 *   - Les demi-pleins comptent. Au Burkina Faso on achete pour un montant
 *     — « mets-moi pour 10 000 » — pas jusqu'au debordement. Un calcul qui
 *     ignorerait leurs litres rendrait un chiffre faux, et faux VERS LE BAS :
 *     la distance des deux pleins, mais seulement une partie du carburant
 *     brule. Un 7,8 l/100 deviendrait 4,2 sans qu'aucune erreur ne soit levee.
 *
 *   - Seul le plein ferme une mesure. « La moitie du reservoir » n'est pas un
 *     niveau qu'un conducteur reproduit ; l'arret automatique du pistolet l'est.
 *     Un demi-plein apporte ses litres sans jamais borner un intervalle.
 *
 *   - Un oubli doit produire un silence, jamais une fausse alerte. C'est le
 *     role du plancher a 3,0 l/100 : un passage non enregistre laisse une
 *     distance reelle et des litres incomplets, donc un taux effondre, donc un
 *     intervalle ecarte. Toute l'alerte repose sur cette asymetrie — un chiffre
 *     peut manquer, il ne doit pas mentir.
 *
 * Les nombres de ces tests sont poses a la main et verifiables de tete. C'est
 * voulu : un test qui recalcule la formule qu'il verifie ne verifie rien.
 */
class SuiviConsommationTest extends TestCase
{
    use RefreshDatabase;

    // ── Outils ───────────────────────────────────────────────────────

    private function vehicule(): OwnedVehicle
    {
        return OwnedVehicle::factory()->create([
            'user_id' => User::factory()->create([
                'role'         => 'client',
                'firebase_uid' => 'client-'.uniqid(),
            ])->id,
        ]);
    }

    /**
     * Un passage a la pompe. Le quatrieme argument est la nature du passage, et
     * c'est le seul qui change le calcul : il dit si la pompe s'est arretee
     * seule.
     */
    private function passage(
        OwnedVehicle $vehicule,
        int $km,
        float $litres,
        bool $plein,
        ?int $montant = null,
        ?string $date = null,
    ): FuelFill {
        return FuelFill::create([
            'owned_vehicle_id' => $vehicule->id,
            'mileage_km'       => $km,
            'litres'           => $litres,
            'is_full'          => $plein,
            'amount_fcfa'      => $montant,
            'filled_at'        => $date,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    private function intervalles(OwnedVehicle $vehicule): array
    {
        return app(SuiviConsommation::class)->intervalles($vehicule->load('fuelFills'));
    }

    /** @return array<string, mixed> */
    private function resume(OwnedVehicle $vehicule): array
    {
        return app(SuiviConsommation::class)->resume($vehicule->load('fuelFills'));
    }

    /**
     * Une suite de pleins espaces de 500 km, un par couple (compteur, litres).
     * Le premier n'est qu'une borne d'ouverture : il ne produit pas
     * d'intervalle.
     *
     * @param  array<int, float>  $litresParPlein
     */
    private function serieDePleins(OwnedVehicle $vehicule, array $litresParPlein, int $depart = 100_000): void
    {
        foreach (array_values($litresParPlein) as $rang => $litres) {
            $this->passage($vehicule, $depart + 500 * $rang, $litres, true);
        }
    }

    // ── Les cas vides : ce que voit un proprietaire qui commence ─────

    public function test_sans_aucun_passage_le_resume_est_entierement_nul(): void
    {
        // La cle 'fuel_tracking' est toujours rendue, meme vide : l'application
        // doit distinguer « aucun passage » de « le serveur n'a rien dit ». Un
        // resume absent ou partiel laisserait l'ecran choisir, et il
        // choisirait mal.
        $resume = $this->resume($this->vehicule());

        $this->assertNull($resume['l_100km']);
        $this->assertNull($resume['fcfa_100km']);
        $this->assertSame(0, $resume['based_on_fills']);
        $this->assertSame(0, $resume['total_fills']);
        $this->assertSame(0, $resume['full_fills']);
        $this->assertSame(0, $resume['excluded_intervals']);
        $this->assertNull($resume['last_filled_at']);
        $this->assertNull($resume['last_mileage_km']);
        $this->assertNull($resume['baseline_l_100km']);
        $this->assertFalse($resume['abnormal']);
        $this->assertNull($resume['alerte_l_100km']);
        $this->assertFalse($resume['series_rompue']);
        $this->assertSame(
            ['litres' => 0.0, 'amount_fcfa' => 0, 'distance_km' => 0],
            $resume['totals'],
        );
    }

    public function test_un_seul_plein_ne_produit_aucun_intervalle(): void
    {
        // Un plein isole ne mesure rien : il dit ou etait le niveau, pas ce qui
        // a ete brule depuis. Il faut deux bornes pour une distance.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 42.0, true, 30_000);

        $this->assertSame([], $this->intervalles($vehicule));

        $resume = $this->resume($vehicule);
        $this->assertNull($resume['l_100km']);
        $this->assertSame(1, $resume['total_fills']);
        $this->assertSame(0, $resume['based_on_fills']);

        // Mais ce qu'il a paye et mis dans le reservoir est un fait, lui.
        $this->assertSame(42.0, $resume['totals']['litres']);
        $this->assertSame(30_000, $resume['totals']['amount_fcfa']);
        $this->assertSame(0, $resume['totals']['distance_km']);
    }

    // ── Le calcul de base ────────────────────────────────────────────

    public function test_deux_pleins_donnent_le_premier_chiffre(): void
    {
        // 500 km parcourus, 40 litres remis pour revenir au meme niveau :
        // 40 x 100 / 500 = 8,0 l/100 km. Cout : 30 000 FCFA pour 500 km, soit
        // 6 000 FCFA aux 100 km.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true, 33_000, '2026-10-01');
        $this->passage($vehicule, 100_500, 40.0, true, 30_000, '2026-10-12');

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(1, $intervalles);
        $this->assertSame(500, $intervalles[0]['distance_km']);
        $this->assertSame(40.0, $intervalles[0]['litres']);
        $this->assertSame(8.0, $intervalles[0]['l_100km']);
        $this->assertTrue($intervalles[0]['retenu']);
        $this->assertNull($intervalles[0]['motif_ecart']);

        $resume = $this->resume($vehicule);
        $this->assertSame(8.0, $resume['l_100km']);
        $this->assertSame(6_000, $resume['fcfa_100km']);
        $this->assertSame(1, $resume['based_on_fills']);
        $this->assertSame(2, $resume['total_fills']);
        $this->assertSame(2, $resume['full_fills']);
        $this->assertSame('2026-10-12', $resume['last_filled_at']);
        $this->assertSame(100_500, $resume['last_mileage_km']);
    }

    public function test_les_demi_pleins_entre_deux_pleins_entrent_dans_le_calcul(): void
    {
        // Le coeur de la methode. Niveau identique en A et en B — la pompe s'est
        // arretee seule aux deux — donc tout ce qui a ete achete entre les deux
        // est passe dans le moteur : 10 + 12 + 25 = 47 litres pour 600 km, soit
        // 47 x 100 / 600 = 7,83 arrondi a 7,8 l/100 km.
        //
        // Ignorer les demi-pleins ne ferait pas « un peu moins precis » : il
        // resterait 25 litres pour 600 km, soit 4,2 l/100 km — un chiffre
        // parfaitement plausible a l'ecran, et faux d'un tiers.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true);
        $this->passage($vehicule, 100_200, 10.0, false);
        $this->passage($vehicule, 100_400, 12.0, false);
        $this->passage($vehicule, 100_600, 25.0, true);

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(1, $intervalles, 'Un demi-plein ne borne jamais un intervalle.');
        $this->assertSame(600, $intervalles[0]['distance_km']);
        $this->assertSame(47.0, $intervalles[0]['litres']);
        $this->assertSame(7.8, $intervalles[0]['l_100km']);
        $this->assertNotSame(4.2, $intervalles[0]['l_100km']);

        $resume = $this->resume($vehicule);
        $this->assertSame(7.8, $resume['l_100km']);
        $this->assertSame(4, $resume['total_fills']);
        $this->assertSame(2, $resume['full_fills']);
    }

    public function test_que_des_demi_pleins_ne_donne_aucun_taux_mais_des_totaux(): void
    {
        // Le proprietaire qui ne fait jamais le plein — le cas majoritaire ici.
        // On ne peut rien lui dire de sa consommation : aucune borne reproductible,
        // donc aucun intervalle. Mais ce qu'il a depense et roule est un fait, et
        // c'est ce qu'on lui montre, sans jamais l'appeler une moyenne.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 50_000, 10.0, false, 7_000);
        $this->passage($vehicule, 50_300, 12.0, false, 8_400);
        $this->passage($vehicule, 50_700, 11.0, false, 7_700);

        $this->assertSame([], $this->intervalles($vehicule));

        $resume = $this->resume($vehicule);
        $this->assertNull($resume['l_100km']);
        $this->assertNull($resume['fcfa_100km']);
        $this->assertSame(3, $resume['total_fills']);
        $this->assertSame(0, $resume['full_fills']);
        $this->assertSame(
            ['litres' => 33.0, 'amount_fcfa' => 23_100, 'distance_km' => 700],
            $resume['totals'],
        );
    }

    public function test_le_chiffre_affiche_est_une_somme_sur_somme_et_non_une_moyenne_de_taux(): void
    {
        // Deux intervalles : 100 km a 10,0 l/100 et 900 km a 5,0 l/100.
        //
        //   moyenne des taux : (10,0 + 5,0) / 2         = 7,5
        //   somme sur somme  : 55 x 100 / 1 000          = 5,5
        //
        // La bonne reponse est 5,5 : le conducteur a bien brule 55 litres sur
        // 1 000 km. La moyenne des taux donnerait au petit intervalle — celui qui
        // porte le plus de bruit de coupure de pompe — le meme poids qu'a un
        // intervalle neuf fois plus long.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 80_000, 30.0, true);
        $this->passage($vehicule, 80_100, 10.0, true);
        $this->passage($vehicule, 81_000, 45.0, true);

        $resume = $this->resume($vehicule);

        $this->assertSame(5.5, $resume['l_100km']);
        $this->assertNotSame(7.5, $resume['l_100km']);
    }

    // ── Les intervalles ecartes, et leur motif ───────────────────────

    public function test_un_intervalle_de_30_km_ne_ferme_rien_et_attend_le_plein_suivant(): void
    {
        // Sous 50 km, la variation du point de coupure de la pompe — deux ou
        // trois litres d'un passage a l'autre — pese plus que la consommation
        // mesuree. Mais ce n'est pas une raison de JETER la mesure : le niveau
        // du reservoir est le meme aux deux pleins, donc l'intervalle se
        // prolonge au lieu de se fermer.
        //
        // Tant que le plein suivant n'est pas arrive, il n'y a donc ni
        // intervalle ni intervalle ECARTE : excluded_intervals reste a zero. La
        // nuance compte a l'ecran — « rien a mesurer pour l'instant » n'est pas
        // « une mesure a ete rejetee ».
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 90_000, 40.0, true);
        $this->passage($vehicule, 90_030, 2.5, true);

        $this->assertSame([], $this->intervalles($vehicule));

        $resume = $this->resume($vehicule);
        $this->assertNull($resume['l_100km']);
        $this->assertSame(0, $resume['excluded_intervals']);
        $this->assertSame(0, $resume['based_on_fills']);
    }

    public function test_deux_pleins_rapproches_de_30_puis_40_km_font_un_intervalle_de_70_km(): void
    {
        // Le defaut repare : 30 km puis 40 km tombaient tous les deux en
        // 'trop_court', et 70 km de mesure parfaitement valides partaient a la
        // poubelle avec leurs 5,6 litres.
        //
        // Sauter le plein intermediaire ne biaise rien — la pompe s'est arretee
        // seule aux trois passages, donc le niveau est le meme aux trois, et
        // 90 000 -> 90 070 est physiquement la meme borne que 90 000 -> 90 030
        // -> 90 070. Resultat : 5,6 x 100 / 70 = 8,0 l/100 km, et pas un litre
        // perdu.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 90_000, 40.0, true);
        $this->passage($vehicule, 90_030, 2.5, true);
        $this->passage($vehicule, 90_070, 3.1, true);

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(1, $intervalles, 'Le plein intermediaire ne ferme rien, il prolonge.');
        $this->assertSame(70, $intervalles[0]['distance_km']);
        $this->assertSame(5.6, $intervalles[0]['litres'], 'Les litres du plein saute restent dans la mesure.');
        $this->assertSame(8.0, $intervalles[0]['l_100km']);
        $this->assertTrue($intervalles[0]['retenu']);
        $this->assertNull($intervalles[0]['motif_ecart']);

        $this->assertSame(8.0, $this->resume($vehicule)['l_100km']);
    }

    public function test_deux_pleins_au_meme_compteur_ne_divisent_plus_par_zero(): void
    {
        // Cas normal a la station : la pompe s'arrete, on complete en deux fois,
        // et le compteur n'a pas bouge entre les deux tickets. La distance vaut
        // 0 — et c'est exactement la que le code calculait autrefois un taux
        // avant de juger le motif. Le second plein prolonge, ses 10 litres
        // rejoignent les 30 du suivant : 40 L sur 500 km, soit 8,0 l/100 km.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 90_000, 40.0, true);
        $this->passage($vehicule, 90_000, 10.0, true);
        $this->passage($vehicule, 90_500, 30.0, true);

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(1, $intervalles);
        $this->assertSame(500, $intervalles[0]['distance_km']);
        $this->assertSame(40.0, $intervalles[0]['litres']);
        $this->assertSame(8.0, $intervalles[0]['l_100km']);
    }

    public function test_un_intervalle_de_2500_km_est_ecarte_comme_trop_long(): void
    {
        // 2 500 km entre deux pleins : l'hypothese « aucun passage n'a ete
        // oublie » devient moins credible que son contraire. Le taux calcule
        // ici, 7,2 l/100, est plausible — et c'est precisement pour cela qu'on
        // l'ecarte : rien a l'ecran ne permettrait de le mettre en doute.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 90_000, 40.0, true);
        $this->passage($vehicule, 92_500, 180.0, true);

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(1, $intervalles);
        $this->assertSame(2_500, $intervalles[0]['distance_km']);
        $this->assertFalse($intervalles[0]['retenu']);
        $this->assertSame('trop_long', $intervalles[0]['motif_ecart']);
    }

    public function test_un_passage_oublie_est_ecarte_et_ne_tire_pas_la_base_vers_le_bas(): void
    {
        // Le passage entre 101 000 et 102 000 n'a pas ete enregistre : la
        // distance est reelle, les litres ne le sont qu'a moitie. 21 x 100 /
        // 1 000 = 2,1 l/100 km — sous le plancher de 3,0, qui est la signature
        // de cet oubli et non une borne de vraisemblance mecanique.
        //
        // Ce qui est teste ici est l'asymetrie dont depend toute l'alerte : un
        // oubli ne fait pas monter le chiffre, il le fait tomber, donc il
        // produit un silence et jamais une fausse alerte. Et comme l'intervalle
        // est ecarte, il ne contamine pas non plus la moyenne des autres : le
        // resultat reste exactement 8,0.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 40.0, true);
        $this->passage($vehicule, 100_500, 40.0, true);
        $this->passage($vehicule, 101_000, 40.0, true);
        $this->passage($vehicule, 102_000, 21.0, true);
        $this->passage($vehicule, 102_500, 40.0, true);
        $this->passage($vehicule, 103_000, 40.0, true);
        $this->passage($vehicule, 103_500, 40.0, true);

        $intervalles = $this->intervalles($vehicule);

        $ecartes = array_values(array_filter($intervalles, fn (array $i) => ! $i['retenu']));
        $this->assertCount(1, $ecartes);
        $this->assertSame(2.1, $ecartes[0]['l_100km']);
        $this->assertSame('invraisemblable', $ecartes[0]['motif_ecart']);

        $resume = $this->resume($vehicule);
        $this->assertSame(8.0, $resume['l_100km'], 'Un oubli ne doit pas abaisser le chiffre affiche.');
        $this->assertSame(1, $resume['excluded_intervals']);
        $this->assertSame(5, $resume['based_on_fills']);
    }

    // ── L'ordre de la chaine ─────────────────────────────────────────

    public function test_la_chaine_suit_le_compteur_et_non_l_ordre_de_saisie(): void
    {
        // Un passage se saisit souvent le lendemain, parfois la semaine
        // suivante. Celui-ci porte le created_at le plus recent et le compteur
        // intermediaire : il doit couper l'intervalle en deux.
        //
        // Trie par identifiant, la chaine serait 100 000 -> 101 000 puis
        // 101 000 -> 100 500, soit une distance negative. Rien ne planterait :
        // le resultat serait simplement faux.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true);
        $this->passage($vehicule, 101_000, 80.0, true);

        $retard = $this->passage($vehicule, 100_500, 40.0, true);
        $retard->forceFill(['created_at' => now()->addDay()])->save();

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(2, $intervalles);
        $this->assertSame([500, 500], array_column($intervalles, 'distance_km'));
        $this->assertSame([8.0, 16.0], array_column($intervalles, 'l_100km'));
    }

    // ── L'alerte ─────────────────────────────────────────────────────

    public function test_aucune_alerte_a_cinq_intervalles_meme_tres_au_dessus(): void
    {
        // Cinq intervalles retenus : 8, 8, 8, 20, 20. Le dernier double le
        // regime habituel, et pourtant rien ne doit partir. La base se calcule
        // en excluant les deux derniers ; a cinq, elle reposerait sur trois
        // valeurs, ce qui est trop peu pour qu'une mediane veuille dire quelque
        // chose. Une alerte qu'on a vue se tromper une fois ne se lit plus
        // jamais : on prefere la retarder d'un plein.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 100.0, 100.0]);

        $resume = $this->resume($vehicule);

        $this->assertSame(5, $resume['based_on_fills']);
        $this->assertNull($resume['baseline_l_100km']);
        $this->assertFalse($resume['abnormal']);
    }

    public function test_l_alerte_part_a_six_intervalles_quand_les_deux_derniers_passent_les_deux_seuils(): void
    {
        // Six intervalles : 8, 8, 8, 8, 12, 12. Base = mediane des quatre
        // premiers = 8,0. Les deux derniers doivent franchir les deux seuils :
        // 8 x 1,25 = 10,0 et 8 + 1,5 = 9,5. 12,0 passe les deux, deux fois de
        // suite — ce n'est plus une faute de frappe au compteur.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 60.0, 60.0]);

        $resume = $this->resume($vehicule);

        $this->assertSame(6, count(array_filter($this->intervalles($vehicule), fn (array $i) => $i['retenu'])));
        $this->assertSame(8.0, $resume['baseline_l_100km']);
        $this->assertTrue($resume['abnormal']);
    }

    public function test_un_seul_intervalle_au_dessus_ne_declenche_rien(): void
    {
        // Six intervalles : 8, 8, 8, 8, 8, 12. Base = 8,0, et le dernier passe
        // largement les deux seuils — mais lui seul. Un chiffre de compteur mal
        // tape produit exactement cette forme : un ecart isole, suivi d'un
        // retour au regime habituel.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 40.0, 60.0]);

        $resume = $this->resume($vehicule);

        $this->assertSame(8.0, $resume['baseline_l_100km']);
        $this->assertFalse($resume['abnormal']);
    }

    public function test_le_seuil_relatif_ne_suffit_pas_sans_le_seuil_absolu(): void
    {
        // Base 4,0 l/100, deux derniers intervalles a 5,0. Un litre d'ecart aux
        // 100 km sur un vehicule sobre, c'est le passage de la saison seche a la
        // saison des pluies ou deux trajets en ville de plus — pas une panne qui
        // s'annonce. Le seuil absolu de 1,5 l est la pour cela : sans lui,
        // chaque petite cylindree du parc sonnerait.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [20.0, 20.0, 20.0, 20.0, 20.0, 25.0, 25.0]);

        $resume = $this->resume($vehicule);

        $this->assertSame(4.0, $resume['baseline_l_100km']);
        $this->assertFalse($resume['abnormal']);

        // Et un cran plus haut, la ou le seuil relatif est franchement depasse
        // — 5,1 > 4,0 x 1,25 — le seuil absolu tient toujours : il faudrait
        // atteindre 5,5 pour que l'alerte parte.
        $autre = $this->vehicule();
        $this->serieDePleins($autre, [20.0, 20.0, 20.0, 20.0, 20.0, 25.5, 25.5]);

        $resumeAutre = $this->resume($autre);

        $this->assertSame(4.0, $resumeAutre['baseline_l_100km']);
        $this->assertSame(5.1, $this->intervalles($autre)[5]['l_100km']);
        $this->assertFalse($resumeAutre['abnormal']);
    }

    // ── Le plancher relatif : les intervalles anormalement bas ───────

    public function test_un_intervalle_sous_60_pour_cent_de_la_mediane_est_ecarte(): void
    {
        // La faille que le plancher absolu de 3,0 ne voyait pas. Pour qu'un
        // vehicule a 8,0 descende sous 3,0, il faut que plus de 62 % du
        // carburant manque ; un passage oublie sur deux rend 4,0, qui passait
        // tranquillement et entrait dans la mediane de reference.
        //
        // Sept intervalles : 8, 8, 8, 4, 8, 8, 8. La mediane vaut 8,0, le
        // plancher relatif 0,60 x 8,0 = 4,8, et le 4,0 tombe dessous. Il est
        // ecarte avec son motif, et la reference reste 8,0 — c'est-a-dire que
        // les intervalles JUSTES ne se font plus juger par un oubli.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 20.0, 40.0, 40.0, 40.0]);

        $intervalles = $this->intervalles($vehicule);

        $this->assertSame([8.0, 8.0, 8.0, 4.0, 8.0, 8.0, 8.0], array_column($intervalles, 'l_100km'));
        $this->assertFalse($intervalles[3]['retenu']);
        $this->assertSame('anormalement_bas', $intervalles[3]['motif_ecart']);

        $resume = $this->resume($vehicule);
        $this->assertSame(1, $resume['excluded_intervals']);
        $this->assertSame(8.0, $resume['l_100km'], 'Un oubli ne doit plus peser sur le chiffre affiche.');
        $this->assertSame(8.0, $resume['baseline_l_100km']);
        $this->assertFalse($resume['abnormal']);
    }

    public function test_un_trajet_econome_legitime_survit_au_plancher_relatif(): void
    {
        // La contrepartie, et c'est elle qui fixe le facteur a 0,60 plutot que
        // plus haut : six intervalles 8, 8, 6, 8, 8, 8. Le 6,0 est un trajet
        // d'autoroute contre une mediane urbaine a 8,0 — de la conduite, pas
        // une saisie manquante. 6,0 > 4,8, il est retenu.
        //
        // Un oubli, lui, divise le taux par deux environ : c'est la marge entre
        // 6,0 et 4,0 que le facteur exploite.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 30.0, 40.0, 40.0, 40.0]);

        $intervalles = $this->intervalles($vehicule);

        $this->assertSame([8.0, 8.0, 6.0, 8.0, 8.0, 8.0], array_column($intervalles, 'l_100km'));
        $this->assertTrue($intervalles[2]['retenu'], 'Un trajet econome n\'est pas un oubli.');
        $this->assertNull($intervalles[2]['motif_ecart']);
        $this->assertSame(0, $this->resume($vehicule)['excluded_intervals']);
    }

    public function test_le_plancher_relatif_ne_s_applique_pas_sous_quatre_intervalles(): void
    {
        // Trois intervalles retenus : 8, 8, 3,2. La mediane vaudrait 8,0 et le
        // plancher 4,8, donc le 3,2 serait ecarte — mais une mediane sur trois
        // valeurs n'est pas une reference : un seul long trajet d'autoroute la
        // deplacerait assez pour condamner les autres. La seconde passe se tait.
        $court = $this->vehicule();
        $this->serieDePleins($court, [40.0, 40.0, 40.0, 16.0]);

        $intervalles = $this->intervalles($court);

        $this->assertSame([8.0, 8.0, 3.2], array_column($intervalles, 'l_100km'));
        $this->assertTrue($intervalles[2]['retenu']);
        $this->assertNull($intervalles[2]['motif_ecart']);
        $this->assertSame(0, $this->resume($court)['excluded_intervals']);

        // Un intervalle de plus, et la meme valeur basse est ecartee : la
        // mediane de [3,2 ; 8 ; 8 ; 8] vaut 8,0, le plancher 4,8.
        $assez = $this->vehicule();
        $this->serieDePleins($assez, [40.0, 40.0, 40.0, 16.0, 40.0]);

        $intervalles = $this->intervalles($assez);

        $this->assertFalse($intervalles[2]['retenu']);
        $this->assertSame('anormalement_bas', $intervalles[2]['motif_ecart']);
    }

    public function test_la_mediane_empoisonnee_reste_une_limite_connue_du_plancher_relatif(): void
    {
        // Le cas que le plancher relatif NE rattrape PAS, et il est ici pour
        // qu'on cesse de croire qu'il le rattrape.
        //
        // Un proprietaire oublie un passage sur deux pendant quatre
        // intervalles : 1 000 km pour 40 L, soit 4,0 l/100 sur une voiture qui
        // en fait 8,0. Serie obtenue : 4 4 4 4 8 8. La mediane vaut alors 4,0
        // — les intervalles amputes sont MAJORITAIRES, donc ils emportent la
        // reference avec eux — et le plancher relatif vaut 2,4. Aucun 4,0 n'est
        // ecarte, la base reste 4,0, et les deux intervalles JUSTES a 8,0
        // franchissent 4,0 x 1,25 = 5,0 et 4,0 + 1,5 = 5,5 : l'alerte part
        // encore sur un vehicule dont la consommation n'a pas varie.
        //
        // Aucun facteur applique a une MEDIANE ne peut corriger cela : il
        // faudrait une reference qui resiste a plus de 50 % de contamination.
        // Ce test fixe le comportement reel pour que la decision se prenne les
        // yeux ouverts, pas pour l'approuver.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 40.0, true);
        $this->passage($vehicule, 101_000, 40.0, true);
        $this->passage($vehicule, 102_000, 40.0, true);
        $this->passage($vehicule, 103_000, 40.0, true);
        $this->passage($vehicule, 104_000, 40.0, true);
        $this->passage($vehicule, 104_500, 40.0, true);
        $this->passage($vehicule, 105_000, 40.0, true);

        $intervalles = $this->intervalles($vehicule);

        $this->assertSame([4.0, 4.0, 4.0, 4.0, 8.0, 8.0], array_column($intervalles, 'l_100km'));
        $this->assertSame([], array_values(array_filter(
            array_column($intervalles, 'motif_ecart'),
            fn (?string $motif) => $motif === 'anormalement_bas',
        )));

        $resume = $this->resume($vehicule);
        $this->assertSame(4.0, $resume['baseline_l_100km']);
        $this->assertTrue($resume['abnormal'], 'Limite connue : la mediane est elle-meme empoisonnee.');

        // Au moins la notification ne se contredit plus : le chiffre du verdict
        // vaut 8,0 contre une base de 4,0, donc elle annonce bien une hausse.
        $this->assertSame(8.0, $resume['alerte_l_100km']);
    }

    // ── Le compteur remplace ─────────────────────────────────────────

    public function test_un_compteur_remplace_coupe_la_serie_et_seule_la_recente_compte(): void
    {
        // 7 pleins a 11,0 l/100 depuis 200 000 km (66 L par 600 km), puis le
        // compteur est remplace et 4 pleins a 7,0 reels repartent de 1 800 km
        // (42 L par 600 km).
        //
        // Trie par kilometrage seul, toute la vie d'avant se retrouvait EN FIN
        // de chaine : l'ecran annoncait 11,0 a un proprietaire qui fait 7,0,
        // « dernier passage 203 600 km » alors que son compteur lit 3 600, et
        // les deux derniers intervalles du verdict etaient a jamais ceux du
        // vieux compteur — plus aucune derive future n'aurait pu declencher
        // l'alerte.
        $vehicule = $this->vehicule();

        foreach (range(0, 6) as $rang) {
            $this->passage($vehicule, 200_000 + 600 * $rang, 66.0, true, null, '2025-01-01');
        }

        foreach (range(0, 3) as $rang) {
            $this->passage($vehicule, 1_800 + 600 * $rang, 42.0, true, null, '2026-10-10');
        }

        $intervalles = $this->intervalles($vehicule);

        $this->assertSame([7.0, 7.0, 7.0], array_column($intervalles, 'l_100km'));

        $resume = $this->resume($vehicule);
        $this->assertTrue($resume['series_rompue']);
        $this->assertSame(7.0, $resume['l_100km'], 'Le proprietaire fait 7,0, pas 11,0.');
        $this->assertSame(3_600, $resume['last_mileage_km'], 'Son compteur lit 3 600, pas 203 600.');
        $this->assertSame(4, $resume['total_fills'], 'L\'histoire d\'avant n\'entre plus dans le calcul.');
        $this->assertSame('2026-10-10', $resume['last_filled_at']);
    }

    public function test_un_ticket_saisi_en_retard_dans_la_plage_connue_ne_rompt_pas_la_serie(): void
    {
        // La distinction qui empeche la detection d'etre speculative. Ce ticket
        // est saisi APRES les autres et porte un compteur plus bas que le
        // dernier — mais 100 500 tombe DANS la plage deja connue [100 000 ;
        // 101 000], donc il s'insere. Un compteur neuf, lui, repart sous le
        // MINIMUM de tout ce qui precede, la ou aucune insertion n'est possible.
        //
        // Confondre les deux couperait la serie a chaque ticket retrouve dans
        // une poche, et le proprietaire verrait son historique disparaitre.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true);
        $this->passage($vehicule, 101_000, 80.0, true);

        $retard = $this->passage($vehicule, 100_500, 40.0, true);
        $retard->forceFill(['created_at' => now()->addDay()])->save();

        $resume = $this->resume($vehicule);

        $this->assertFalse($resume['series_rompue']);
        $this->assertSame(3, $resume['total_fills']);
        $this->assertCount(2, $this->intervalles($vehicule));
    }

    // ── Les chiffres publies ─────────────────────────────────────────

    public function test_le_chiffre_du_verdict_est_publie_a_part_du_chiffre_affiche(): void
    {
        // Serie 8 8 8 8 3,1 3,1 11 11, mesuree par la revue. L'ancien code
        // retenait les 3,1 — au-dessus du plancher absolu — et les faisait
        // entrer dans la fenetre de cinq : l_100km tombait a 7,2, et la
        // notification disait « 7,2 l/100 km, contre 8,0 d'habitude » sous le
        // titre « Consommation en hausse ». Elle annoncait une baisse.
        //
        // Deux corrections se croisent ici. Les 3,1 sont desormais ecartes
        // (0,60 x 8,0 = 4,8), donc la fenetre affiche 9,2. Et surtout le
        // chiffre QUI A TRANCHE est publie a part : somme sur somme des deux
        // derniers intervalles retenus, 110 L sur 1 000 km, soit 11,0 — le
        // nombre exact que anormal() a compare a 8,0.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 15.5, 15.5, 55.0, 55.0]);

        $intervalles = $this->intervalles($vehicule);
        $this->assertSame([8.0, 8.0, 8.0, 8.0, 3.1, 3.1, 11.0, 11.0], array_column($intervalles, 'l_100km'));

        $resume = $this->resume($vehicule);

        $this->assertSame(8.0, $resume['baseline_l_100km']);
        $this->assertTrue($resume['abnormal']);
        $this->assertSame(9.2, $resume['l_100km']);
        $this->assertSame(11.0, $resume['alerte_l_100km'], 'Le chiffre du verdict, pas celui de la fenetre.');
        $this->assertGreaterThan($resume['baseline_l_100km'], $resume['alerte_l_100km']);
    }

    public function test_alerte_l_100km_reste_nulle_quand_rien_n_est_anormal(): void
    {
        // Pas de verdict, pas de chiffre de verdict. L'ecran ne doit pas
        // pouvoir opposer deux nombres quand il n'y a rien a opposer.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 40.0, 40.0]);

        $resume = $this->resume($vehicule);

        $this->assertFalse($resume['abnormal']);
        $this->assertNull($resume['alerte_l_100km']);
    }

    public function test_la_base_publiee_est_celle_qui_a_servi_au_verdict(): void
    {
        // Retenus 7,0 / 7,1 / 7,2 / 7,2 / 9,0 / 9,0. La mediane des quatre
        // premiers vaut 7,15.
        //
        // Avant : le verdict se prenait sur 7,15 — seuil 7,15 x 1,25 = 8,9375,
        // que 9,0 franchit — et l'ecran publiait 7,2, dont le seuil vaut
        // exactement 9,0, que 9,0 ne franchit pas. Le proprietaire qui refaisait
        // le calcul sur les nombres montres ne retrouvait pas son alerte, et une
        // alerte qu'on ne peut pas verifier ne se croit pas.
        //
        // Maintenant : la mediane est arrondie UNE fois, dans base(), et c'est
        // cette valeur qui tranche comme qui s'affiche. Le verdict se refait a
        // la main : 7,2 x 1,25 = 9,0, et 9,0 ne depasse pas 9,0.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 35.0, 35.5, 36.0, 36.0, 45.0, 45.0]);

        $intervalles = $this->intervalles($vehicule);
        $this->assertSame([7.0, 7.1, 7.2, 7.2, 9.0, 9.0], array_column($intervalles, 'l_100km'));

        $resume = $this->resume($vehicule);

        $this->assertSame(7.2, $resume['baseline_l_100km']);
        $this->assertFalse(
            $resume['abnormal'],
            'Le verdict doit se refaire sur les nombres publies : 7,2 x 1,25 = 9,0, et 9,0 ne le depasse pas.',
        );
    }

    public function test_le_motif_se_juge_sur_le_taux_arrondi_qui_est_publie(): void
    {
        // 29,6 L sur 1 000 km font 2,96, publies 3,0. L'ancien code jugeait le
        // motif sur 2,96 et affichait « 3,0 l/100 km — ecarte, invraisemblable »
        // a cote d'une regle annoncee comme « retenu a partir de 3,0 » : l'ecran
        // se contredisait sur la meme ligne.
        $limite = $this->vehicule();
        $this->passage($limite, 100_000, 40.0, true);
        $this->passage($limite, 101_000, 29.6, true);

        $intervalles = $this->intervalles($limite);

        $this->assertSame(3.0, $intervalles[0]['l_100km']);
        $this->assertTrue($intervalles[0]['retenu']);
        $this->assertNull($intervalles[0]['motif_ecart']);

        // Un dixieme plus bas, le chiffre publie passe sous le plancher et le
        // motif suit : 29,4 L sur 1 000 km font 2,94, publies 2,9.
        $dessous = $this->vehicule();
        $this->passage($dessous, 100_000, 40.0, true);
        $this->passage($dessous, 101_000, 29.4, true);

        $intervalles = $this->intervalles($dessous);

        $this->assertSame(2.9, $intervalles[0]['l_100km']);
        $this->assertSame('invraisemblable', $intervalles[0]['motif_ecart']);
    }

    public function test_la_derniere_date_est_la_plus_recente_et_non_celle_du_plus_haut_compteur(): void
    {
        // last_filled_at se lisait sur le dernier passage de la CHAINE,
        // c'est-a-dire du plus haut compteur. Ici le ticket du plein de 100 500
        // a ete perdu : sans date, l'ecran annoncait « aucune date » alors que
        // le vehicule avait bien roule et que la saisie, elle, est datee.
        //
        // La reponse est le maximum des dates vecues, avec la date de saisie
        // pour defaut : un passage sans ticket a eu lieu au plus tard le jour ou
        // il a ete enregistre.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true, null, '2026-10-02');
        $this->passage($vehicule, 100_500, 40.0, true);

        $this->assertSame(now()->toDateString(), $this->resume($vehicule)['last_filled_at']);
    }

    // ── La delegation ────────────────────────────────────────────────

    public function test_le_modele_delegue_au_service(): void
    {
        // Une seule source de verite : si le modele recalculait quoi que ce soit
        // de son cote, la ressource API et la tache d'alerte finiraient par
        // afficher deux chiffres pour un meme vehicule. Ce projet a deja perdu
        // un rappel de vidange a ce jeu-la.
        $vehicule = $this->vehicule();
        $this->passage($vehicule, 100_000, 45.0, true);
        $this->passage($vehicule, 100_500, 40.0, true);

        $this->assertSame(
            app(SuiviConsommation::class)->resume($vehicule->load('fuelFills')),
            $vehicule->load('fuelFills')->suiviConsommation(),
        );
    }

    // ── Les fausses alertes nees d'un passage oublie ──────────────────
    //
    // Ces cas protegent la seule promesse que toute cette fonction porte :
    // un passage non enregistre doit produire un SILENCE, jamais une alerte.
    // Elle a ete fausse deux fois avant d'etre tenue, et chaque correction a
    // ete rattrapee par la mesure, pas par le raisonnement.

    /** Une cote officielle, pour servir de repere exterieur au vehicule. */
    private function cote(float $combinee): Motorisation
    {
        $attributs = [
            'source' => 'nrcan', 'cycle' => '5-cycle', 'model_year' => 2013,
            'make_raw' => 'Toyota', 'model_raw' => 'Corolla '.uniqid(),
            'engine_l' => 1.8, 'cylinders' => 4,
            'transmission_code' => 'AS6', 'fuel_code' => 'X',
            'consumption_city' => $combinee + 1.5,
            'consumption_highway' => $combinee - 1.0,
            'consumption_combined' => $combinee,
        ];
        $attributs['cle_source'] = md5(json_encode($attributs));

        return Motorisation::create($attributs);
    }

    private function vehiculeCote(float $combinee): OwnedVehicle
    {
        $vehicule = $this->vehicule();
        $vehicule->update(['motorisation_id' => $this->cote($combinee)->id]);

        return $vehicule->refresh();
    }

    public function test_des_oublis_majoritaires_ne_declenchent_plus_une_fausse_alerte(): void
    {
        // Le cas qui a mis en defaut les deux premieres parades.
        //
        // Un vehicule reellement a 8,0 l/100 dont le proprietaire oublie un
        // passage sur deux produit 4 4 4 4 8 8. Le plancher RELATIF n'y voit
        // rien : la mediane de cette serie vaut 4,0, et 60 % de 4,0 font 2,4.
        // Le garde-fou de DISPERSION n'y voit rien non plus : quatre valeurs
        // sur six fixent la mediane, l'ecart median vaut zero — cette serie a
        // exactement la meme forme que 8 8 8 8 16 16, qui est une vraie
        // derive. Les deux intervalles honnetes a 8,0 franchissaient alors
        // 4,0 x 1,25 et 4,0 + 1,5, et l'alerte partait sur une consommation
        // qui n'avait jamais bouge.
        //
        // Seule la cote constructeur tranche, parce qu'elle ne descend pas
        // avec les oublis : plancher 0,60 x 7,0 = 4,2.
        $vehicule = $this->vehiculeCote(7.0);
        $this->serieDePleins($vehicule, [40.0, 20.0, 20.0, 20.0, 20.0, 40.0, 40.0]);

        $intervalles = $this->intervalles($vehicule);
        $resume = $this->resume($vehicule);

        $ecartes = array_filter($intervalles, fn (array $i) => $i['motif_ecart'] === 'anormalement_bas');

        $this->assertCount(4, $ecartes, 'Les quatre intervalles a 4,0 sont des mesures perdues.');
        $this->assertFalse($resume['abnormal'], 'Un oubli doit rendre le silence, jamais une alerte.');
        $this->assertSame(
            8.0,
            $resume['l_100km'],
            'Le chiffre affiche redevient juste : seules les deux mesures honnetes comptent.',
        );
    }

    public function test_la_cote_ecarte_aussi_les_oublis_de_moitie(): void
    {
        $vehicule = $this->vehiculeCote(7.0);
        $this->serieDePleins($vehicule, [40.0, 20.0, 20.0, 20.0, 40.0, 40.0, 40.0]);

        $resume = $this->resume($vehicule);

        $this->assertFalse($resume['abnormal']);
        $this->assertSame(8.0, $resume['l_100km']);
    }

    public function test_une_vraie_derive_alerte_toujours_malgre_le_plancher(): void
    {
        // Le garde-fou ne doit pas rendre l'alerte muette. 8 8 8 8 11 11 avec
        // une cote a 7,0 : plancher 4,2, rien d'ecarte, base 8,0, et les deux
        // derniers franchissent 10,0 et 9,5.
        $vehicule = $this->vehiculeCote(7.0);
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 55.0, 55.0]);

        $resume = $this->resume($vehicule);

        $this->assertSame(8.0, $resume['baseline_l_100km']);
        $this->assertTrue($resume['abnormal']);
        $this->assertSame(11.0, $resume['alerte_l_100km']);
    }

    public function test_un_doublement_reel_reste_une_alerte_et_non_un_oubli(): void
    {
        // 8 8 8 8 16 16 a la meme FORME que la serie d'oublis ci-dessus. Ce
        // qui les separe est le niveau : 16,0 est plausible, 4,0 ne l'est pas.
        $vehicule = $this->vehiculeCote(7.0);
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 80.0, 80.0]);

        $resume = $this->resume($vehicule);

        $this->assertTrue($resume['abnormal']);
        $this->assertSame(16.0, $resume['alerte_l_100km']);
    }

    public function test_un_trajet_econome_survit_au_plancher_de_la_cote(): void
    {
        // 6,0 contre une cote de 7,0 : plancher 4,2, le trajet d'autoroute
        // reste une mesure. Un plancher pose trop haut effacerait la seule
        // bonne nouvelle que cet ecran puisse annoncer.
        $vehicule = $this->vehiculeCote(7.0);
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 40.0, 30.0, 40.0]);

        $intervalles = $this->intervalles($vehicule);
        $resume = $this->resume($vehicule);

        $this->assertSame(
            [],
            array_values(array_filter($intervalles, fn (array $i) => $i['motif_ecart'] === 'anormalement_bas')),
            'Rouler econome n\'est pas une mesure perdue.',
        );
        $this->assertFalse($resume['abnormal']);
    }

    public function test_sans_cote_le_plancher_relatif_prend_le_relais(): void
    {
        // Fortuner, Prado, D-Max : aucune source publique ne les couvre, et
        // ils sont nombreux ici. Le repere redevient interne, donc faillible
        // sur des oublis majoritaires — c'est assume et mesure ci-dessous —
        // mais il attrape toujours le cas minoritaire.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 20.0, 40.0, 40.0]);

        $intervalles = $this->intervalles($vehicule);

        $this->assertCount(
            1,
            array_filter($intervalles, fn (array $i) => $i['motif_ecart'] === 'anormalement_bas'),
            'Mediane 8,0, plancher 4,8 : le 4,0 isole sort.',
        );
    }

    public function test_la_dispersion_fait_taire_une_serie_coupee_en_deux(): void
    {
        // Sans cote, la serie 4 4 4 8 8 8 donne une mediane de 6,0 et un
        // plancher de 3,6 : aucun 4,0 n'en sort. C'est le garde-fou de
        // dispersion qui rend le silence — l'ecart median a la mediane vaut
        // 2,0 sur 6,0, soit 0,33, au-dela de la limite de 0,25.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 20.0, 20.0, 20.0, 40.0, 40.0, 40.0]);

        $resume = $this->resume($vehicule);

        $this->assertFalse($resume['abnormal']);
    }

    public function test_la_dispersion_ne_fait_pas_taire_une_derive_franche(): void
    {
        // 8 8 8 11 11 11, sans cote : ecart median 1,5 sur une mediane de
        // 9,5, soit 0,16. En deca de la limite, donc l'alerte passe.
        $vehicule = $this->vehicule();
        $this->serieDePleins($vehicule, [40.0, 40.0, 40.0, 40.0, 55.0, 55.0, 55.0]);

        $resume = $this->resume($vehicule);

        $this->assertTrue($resume['abnormal']);
    }

}
