<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\PrixEffectif;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreeDesAnnoncesPartenaires;
use Tests\TestCase;

/**
 * Les promotions a duree limitee d'un partenaire.
 *
 * ── LE CADRE, DECIDE PAR LE PROPRIETAIRE ──────────────────────────────────
 *
 * Un partenaire cree ses promotions LIBREMENT, le personnel en est notifie, la
 * promotion BAISSE le prix et dure un temps donne. La remise est plafonnee —
 * 40 % par defaut, reglable — parce que sans plafond un partenaire affiche son
 * vehicule a 1 franc et occupe le haut de la bande des bonnes affaires, la
 * notification ne l'apprenant qu'apres coup.
 *
 * ── CE QUE CE BANC TIENT, ET POURQUOI CHAQUE POINT COMPTE ─────────────────
 *
 * 1. LA COLONNE GARDE LE PRIX NORMAL. Rien ici ne peut remettre un prix a
 *    l'expiration : pas de shell, pas de tache planifiee fiable. Un prix
 *    promotionnel ecrit dans la colonne y resterait pour toujours.
 *
 * 2. LE PRIX EFFECTIF SE CALCULE, donc il expire tout seul. Et il se calcule
 *    PARTOUT — filtre, tri, panier — sinon la remise s'affiche la ou elle ne
 *    sert pas et disparait la ou elle compte.
 *
 * 3. L'ACHETEUR VOIT LE PRIX DU JOUR DANS LE CHAMP DU PRIX. L'application deja
 *    publiee ne connait pas le bloc `deal` : lui laisser le prix normal lui
 *    ferait afficher un prix que les filtres ne retiennent plus.
 *
 * 4. LE VENDEUR VOIT SON PRIX NORMAL. Son formulaire preremplit ce champ :
 *    lui donner le prix promotionnel ferait enregistrer la remise comme prix
 *    normal a la premiere correction de description.
 */
class PromotionsPartenairesTest extends TestCase
{
    use CreeDesAnnoncesPartenaires;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('partenaires.espace_ouvert', true);
        config()->set('partenaires.marge_pourcentage', 0);
        config()->set('partenaires.promotion_remise_max_pourcentage', 40);

        $this->compte = $this->compteApprouve();

        // `notifyAllStaff` ecrit une ligne PAR membre du personnel : sans
        // aucun en base, elle n'insere rien et le banc mesurerait l'absence
        // de destinataires au lieu de l'absence de notification.
        \App\Models\User::factory()->create(['role' => 'manager', 'is_active' => true]);
    }

    /** @param  array<string, mixed>  $champs */
    private function promotion(array $champs = []): array
    {
        return array_merge([
            'sale_price' => 5_000_000,
            'deal_price' => 4_000_000,
            'deal_starts_at' => now()->subDay()->toIso8601String(),
            'deal_ends_at' => now()->addDays(7)->toIso8601String(),
        ], $champs);
    }

    // ── LE PLAFOND ────────────────────────────────────────────────────────

    public function test_une_remise_au_plafond_passe(): void
    {
        // 5 000 000 -> 3 000 000 : exactement 40 %.
        $this->creerVehicule($this->promotion(['deal_price' => 3_000_000]))
            ->assertCreated();

        $this->assertSame('3000000.00', Vehicle::firstOrFail()->deal_price);
    }

    public function test_une_remise_au_dela_du_plafond_est_refusee(): void
    {
        // 5 000 000 -> 2 900 000 : 42 %.
        $reponse = $this->creerVehicule($this->promotion(['deal_price' => 2_900_000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deal_price');

        $message = $reponse->json('errors.deal_price.0');
        $this->assertStringContainsString('40', $message);
        $this->assertStringContainsString('42', $message,
            'Le vendeur doit lire SA remise autant que le plafond : sans le '
            .'chiffre, il ne sait pas de combien il depasse.');

        $this->assertSame(0, Vehicle::count());
    }

    public function test_le_plafond_est_reglable(): void
    {
        config()->set('partenaires.promotion_remise_max_pourcentage', 60);

        $this->creerVehicule($this->promotion(['deal_price' => 2_500_000]))
            ->assertCreated();
    }

    /**
     * Le plafond se mesure sur les prix que le VENDEUR a tapes.
     *
     * La marge etant un pourcentage uniforme, le rapport se conserve — mais
     * les arrondis au franc le font deriver de quelques millemes. Mesurer sur
     * les nombres que le vendeur voit evite un refus portant sur des chiffres
     * qu'il n'a jamais saisis.
     */
    public function test_le_plafond_se_mesure_avant_la_marge(): void
    {
        config()->set('partenaires.marge_pourcentage', 10);

        // 40 % pile sur les prix proposes.
        $this->creerVehicule($this->promotion(['deal_price' => 3_000_000]))
            ->assertCreated();

        $vehicule = Vehicle::firstOrFail();
        $this->assertSame('5500000.00', $vehicule->sale_price);
        $this->assertSame('3300000.00', $vehicule->deal_price,
            'La boutique garde sa marge sur le prix promotionnel aussi : sans '
            .'cela elle paierait la remise du vendeur.');
    }

    // ── CE QU'UNE PROMOTION EXIGE ─────────────────────────────────────────

    public function test_le_prix_promotionnel_doit_etre_inferieur_au_prix_normal(): void
    {
        $this->creerVehicule($this->promotion(['deal_price' => 5_000_000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deal_price');
    }

    /**
     * Une promotion suppose un prix a remiser.
     *
     * Un vehicule en location seule n'en a pas : la promotion ne s'afficherait
     * nulle part, et l'enregistrer en silence serait pire que la refuser.
     */
    public function test_une_promotion_sans_prix_de_vente_est_refusee(): void
    {
        $this->creerVehicule($this->promotion([
            'availability' => 'rent',
            'sale_price' => null,
            'rental_daily_rate' => 25_000,
        ]))->assertUnprocessable()->assertJsonValidationErrors('deal_price');
    }

    public function test_une_promotion_sans_fin_est_refusee(): void
    {
        $this->creerVehicule($this->promotion(['deal_ends_at' => null]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deal_ends_at');
    }

    public function test_une_fin_avant_le_debut_est_refusee(): void
    {
        $this->creerVehicule($this->promotion([
            'deal_starts_at' => now()->addDays(5)->toIso8601String(),
            'deal_ends_at' => now()->addDay()->toIso8601String(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('deal_ends_at');
    }

    public function test_une_annonce_sans_promotion_reste_possible(): void
    {
        $this->creerVehicule(['sale_price' => 5_000_000])->assertCreated();

        $this->assertNull(Vehicle::firstOrFail()->deal_price);
    }

    // ── LE PLAFOND PORTE SUR L'ETAT FINAL, PAS SUR LE CORPS ──────────────

    /**
     * LE TROU QUE CE BANC FERME, ET QUI A ETE REJOUE.
     *
     * Verifier le plafond uniquement quand `deal_price` figure dans la
     * requete l'ouvrait en DEUX appels : poser une promotion a 40 %, puis
     * relever le prix normal sans reparler de la promotion. La carte
     * affichait « 10 000 000 barre / 3 000 000 », soit 70 % de remise, et le
     * filtre `?price_max=` rendait un vehicule dont le prix normal est de dix
     * millions.
     */
    public function test_relever_le_prix_normal_ne_creuse_pas_la_remise(): void
    {
        $id = $this->creerVehicule($this->promotion(['deal_price' => 3_000_000]))
            ->json('data.id');

        $reponse = $this->modifierVehicule($id, ['sale_price' => 10_000_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sale_price');

        $this->assertStringContainsString('promotion en cours',
            $reponse->json('errors.sale_price.0'),
            'Le refus porte sur une requete qui ne parlait pas de promotion : '
            .'le message doit dire pourquoi.');

        $this->assertSame('5000000.00', Vehicle::findOrFail($id)->sale_price);
    }

    /**
     * Le meme trou dans l'autre sens.
     *
     * Baisser le prix normal SOUS la promotion en cours donnerait un prix
     * effectif SUPERIEUR au prix normal : une remise negative, et un
     * vehicule EXCLU d'un filtre que son prix normal satisfait.
     */
    public function test_baisser_le_prix_sous_la_promotion_est_refuse(): void
    {
        $id = $this->creerVehicule($this->promotion())->json('data.id');

        $this->modifierVehicule($id, ['sale_price' => 3_000_000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sale_price');
    }

    /** Relever le prix dans les limites de la promotion reste possible. */
    public function test_relever_le_prix_dans_la_limite_passe(): void
    {
        $id = $this->creerVehicule($this->promotion(['deal_price' => 4_500_000]))
            ->json('data.id');

        // 4 500 000 sur 7 000 000 : 35,7 %, sous le plafond.
        $this->modifierVehicule($id, ['sale_price' => 7_000_000])->assertSuccessful();

        $this->assertSame('7000000.00', Vehicle::findOrFail($id)->sale_price);
    }

    /** Retirer la promotion libere le prix. */
    public function test_retirer_la_promotion_libere_le_prix(): void
    {
        $id = $this->creerVehicule($this->promotion(['deal_price' => 3_000_000]))
            ->json('data.id');

        $this->modifierVehicule($id, ['sale_price' => 10_000_000, 'deal_price' => null])
            ->assertSuccessful();

        $this->assertSame('10000000.00', Vehicle::findOrFail($id)->sale_price);
    }

    // ── CE QUE L'ACHETEUR VOIT ────────────────────────────────────────────

    public function test_l_acheteur_voit_le_prix_du_jour_dans_le_champ_du_prix(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $this->oublierLaSession();

        $data = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data');

        $this->assertSame(4_000_000.0, (float) $data['commercial']['sale_price'],
            'L application deja publiee ne connait pas le bloc `deal` : le prix '
            .'du jour doit etre dans le champ qu elle lit.');
        $this->assertSame(5_000_000.0, (float) $data['deal']['normal_price']);
        $this->assertSame(20.0, (float) $data['deal']['discount_percent']);
    }

    public function test_le_vendeur_voit_son_prix_normal_et_la_promotion(): void
    {
        $id = $this->creerVehicule($this->promotion())->json('data.id');

        $data = $this->getJson('/api/partner/vehicles/'.$id)->assertOk()->json('data');

        $this->assertSame(5_000_000.0, (float) $data['commercial']['sale_price'],
            'Son formulaire preremplit ce champ : lui donner le prix '
            .'promotionnel ferait enregistrer la remise comme prix normal.');
        $this->assertSame(4_000_000.0, (float) $data['deal']['price']);
    }

    /** Le panier facture la TVA sur le prix du jour, pas sur le prix normal. */
    public function test_la_tva_d_un_article_suit_la_promotion(): void
    {
        $id = $this->publier(
            $this->creerPiece([
                'selling_price' => 10_000,
                'vat_rate' => 18,
                'deal_price' => 8_000,
                'deal_starts_at' => now()->subDay()->toIso8601String(),
                'deal_ends_at' => now()->addDays(7)->toIso8601String(),
            ])->json('data.id'),
            Part::class,
        );
        $this->oublierLaSession();

        $pricing = $this->getJson('/api/catalog/parts/'.$id)->assertOk()->json('data.pricing');

        $this->assertSame(8_000.0, (float) $pricing['selling_price']);
        $this->assertSame(9_440.0, (float) $pricing['price_including_vat'],
            'La TVA assise sur le prix normal ferait payer une taxe sur un '
            .'montant que l acheteur ne verse pas.');
    }

    /**
     * LE BADGE QUE L'APPLICATION DEJA PUBLIEE SAIT DEJA AFFICHER.
     *
     * Elle ne connait pas le bloc `deal`, mais elle lit `deal_label` depuis
     * toujours — sur la carte du catalogue comme dans la bande d'accueil.
     * Lui donner ce libelle fait apparaitre la promotion sur les telephones
     * deja installes, sans nouveau binaire.
     */
    public function test_l_application_publiee_recoit_un_libelle_de_promotion(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $this->oublierLaSession();

        $this->assertSame('Promo -20 %', $this->getJson('/api/catalog/vehicles/'.$id)
            ->assertOk()->json('data.commercial.deal_label'));
    }

    /**
     * `deal_type` reste CELUI DE LA COLONNE, et jamais une valeur inventee.
     *
     * Le formulaire de vehicule prerremplit `deal_type` et le repose tel quel
     * au prochain enregistrement. Une valeur fabriquee hors de l'enumere
     * rendrait l'annonce INEDITABLE : la validation la refuserait.
     */
    public function test_le_type_de_mise_en_avant_n_est_pas_invente(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $this->oublierLaSession();

        $this->assertNull($this->getJson('/api/catalog/vehicles/'.$id)
            ->assertOk()->json('data.commercial.deal_type'));
    }

    /** Le vendeur, lui, garde son propre libelle : il n'achete pas. */
    public function test_le_vendeur_ne_recoit_pas_le_libelle_de_promotion(): void
    {
        $id = $this->creerVehicule($this->promotion())->json('data.id');

        $this->assertNull($this->getJson('/api/partner/vehicles/'.$id)
            ->assertOk()->json('data.commercial.deal_label'));
    }

    /**
     * UNE PROMOTION SANS PRIX NORMAL NE SE SUBSTITUE PAS.
     *
     * La validation l'empeche a l'ecriture, mais une reprise de donnees ou
     * une correction en base peut laisser une promotion sur une annonce dont
     * le prix de vente a disparu.
     *
     * Substituer quand meme afficherait un prix PROMOTIONNEL sans rien qui
     * dise qu'il l'est : pas de bloc `deal`, donc pas de prix barre, donc
     * l'acheteur lirait une remise comme un prix ordinaire — et personne ne
     * pourrait le corriger, puisque rien ne l'annoncerait.
     */
    public function test_une_promotion_sans_prix_normal_ne_se_substitue_pas(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));

        Vehicle::findOrFail($id)->forceFill(['sale_price' => null])->save();
        $this->oublierLaSession();

        $data = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data');

        $this->assertNull($data['deal']);
        $this->assertNull($data['commercial']['sale_price'],
            'Un prix promotionnel sans prix barre se lirait comme un prix '
            .'ordinaire, et rien ne viendrait le corriger.');
    }

    // ── L'EXPIRATION, SANS RIEN A EXECUTER ────────────────────────────────

    public function test_une_promotion_finie_rend_le_prix_normal(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));

        Vehicle::findOrFail($id)->forceFill([
            'deal_starts_at' => now()->subDays(10),
            'deal_ends_at' => now()->subDay(),
        ])->save();
        $this->oublierLaSession();

        $data = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data');

        $this->assertSame(5_000_000.0, (float) $data['commercial']['sale_price']);
        $this->assertNull($data['deal'],
            'Rien n a ete execute : la date suffit a fermer la fenetre.');
    }

    public function test_une_promotion_programmee_n_est_pas_encore_active(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion([
            'deal_starts_at' => now()->addDays(2)->toIso8601String(),
            'deal_ends_at' => now()->addDays(9)->toIso8601String(),
        ]))->json('data.id'));
        $this->oublierLaSession();

        $data = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data');

        $this->assertSame(5_000_000.0, (float) $data['commercial']['sale_price']);
        $this->assertNull($data['deal']);
    }

    // ── LE FILTRE, LE TRI, LA BANDE ───────────────────────────────────────

    /**
     * LE BANC QUI COMPTE LE PLUS.
     *
     * Une promotion qui ne se retrouve pas dans la recherche filtree est une
     * promotion invisible la ou elle sert.
     */
    public function test_le_filtre_de_prix_porte_sur_le_prix_du_jour(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $this->oublierLaSession();

        $trouves = $this->getJson('/api/catalog/vehicles?price_max=4500000')
            ->assertOk()->json('data');

        $this->assertSame([$id], array_column($trouves, 'id'),
            'Le vehicule est a 5 000 000 en colonne et a 4 000 000 pour '
            .'l acheteur : c est ce second nombre qui doit decider.');
    }

    /**
     * LE DEFAUT QUE CE BANC A TROUVE, ET QUI SE CACHAIT.
     *
     * Un `CASE` n'a pas d'affinite de type sous SQLite : son resultat se
     * compare comme du TEXTE. Le filtre marchait donc sur des nombres de
     * meme longueur — « 4000000 » < « 4500000 » par accident — et EXCLUAIT
     * un article a 900 000, « 9 » etant superieur a « 4 ».
     *
     * Le cas le plus courant du catalogue, une piece bon marche, etait donc
     * le cas faux. D'ou ce banc sur des longueurs DIFFERENTES.
     */
    public function test_le_filtre_compare_des_nombres_et_non_des_chaines(): void
    {
        $bonMarche = $this->publier(
            $this->creerVehicule(['sale_price' => 900_000])->json('data.id'),
        );
        $cher = $this->publier(
            $this->creerVehicule(['sale_price' => 12_000_000])->json('data.id'),
        );
        $this->oublierLaSession();

        $trouves = array_column(
            $this->getJson('/api/catalog/vehicles?price_max=4500000')->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$bonMarche], $trouves,
            'Compare en texte, 900 000 passerait pour superieur a 4 500 000.');
        $this->assertNotContains($cher, $trouves);
    }

    public function test_apres_l_expiration_le_filtre_ne_le_trouve_plus(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));

        Vehicle::findOrFail($id)->forceFill(['deal_ends_at' => now()->subDay()])->save();
        $this->oublierLaSession();

        $this->assertSame([], $this->getJson('/api/catalog/vehicles?price_max=4500000')
            ->assertOk()->json('data'));
    }

    public function test_le_tri_par_prix_classe_sur_le_prix_du_jour(): void
    {
        $promu = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $moinsCher = $this->publier($this->creerVehicule(['sale_price' => 4_200_000])->json('data.id'));
        $this->oublierLaSession();

        $ordre = array_column(
            $this->getJson('/api/catalog/vehicles?sort_by=sale_price&sort_dir=asc')
                ->assertOk()->json('data'),
            'id',
        );

        $this->assertSame([$promu, $moinsCher], $ordre,
            'Classe sur la colonne brute, le vehicule promu a 4 000 000 '
            .'passerait apres celui a 4 200 000.');
    }

    public function test_la_bande_des_bonnes_affaires_rend_les_promotions_en_cours(): void
    {
        $enCours = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));
        $programmee = $this->publier($this->creerVehicule($this->promotion([
            'deal_starts_at' => now()->addDays(3)->toIso8601String(),
            'deal_ends_at' => now()->addDays(10)->toIso8601String(),
        ]))->json('data.id'));
        $sansPromotion = $this->publier($this->creerVehicule(['sale_price' => 4_200_000])->json('data.id'));
        $this->oublierLaSession();

        $trouves = array_column(
            $this->getJson('/api/catalog/vehicles?deal=1')->assertOk()->json('data'),
            'id',
        );

        $this->assertContains($enCours, $trouves);
        $this->assertNotContains($programmee, $trouves,
            'Une promotion de la semaine prochaine n est pas une bonne affaire '
            .'aujourd hui.');
        $this->assertNotContains($sansPromotion, $trouves);
    }

    /** La pastille editoriale du personnel continue d'alimenter la bande. */
    public function test_la_pastille_du_personnel_reste_dans_la_bande(): void
    {
        $id = $this->publier($this->creerVehicule(['sale_price' => 4_200_000])->json('data.id'));
        Vehicle::findOrFail($id)->forceFill(['deal_type' => 'good_deal'])->save();
        $this->oublierLaSession();

        $this->assertContains($id, array_column(
            $this->getJson('/api/catalog/vehicles?deal=1')->assertOk()->json('data'),
            'id',
        ));
    }

    /**
     * Le plafond voyage avec le profil.
     *
     * Sans lui, le formulaire du vendeur ne peut pas refuser avant d envoyer :
     * il composerait sa promotion, l enverrait, et lirait un 422 apres coup.
     */
    public function test_le_profil_porte_le_plafond_de_remise(): void
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        $this->assertSame(40.0, (float) $this->getJson('/api/partner/me')
            ->assertOk()->json('boutique.promotion_remise_max_pourcentage'));
    }

    // ── LE PERSONNEL EST NOTIFIE ──────────────────────────────────────────

    public function test_une_promotion_notifie_le_personnel(): void
    {
        // Publiee : une promotion sur un brouillon n'est dans la vitrine de
        // personne, et ne notifie donc pas.
        $id = $this->creerVehicule(['sale_price' => 5_000_000])->json('data.id');
        $this->publier($id);
        $this->modifierVehicule($id, $this->promotion())->assertSuccessful();

        $notification = AppNotification::where('type', 'partner_promo')->firstOrFail();

        $this->assertStringContainsString('-20 %', $notification->body);
        $this->assertSame('vehicle', $notification->data['listing_type']);
        $this->assertSame(4_000_000.0, (float) $notification->data['deal_price']);
    }

    public function test_retirer_une_promotion_notifie_aussi(): void
    {
        $id = $this->publier($this->creerVehicule($this->promotion())->json('data.id'));

        $this->modifierVehicule($id, ['sale_price' => 5_000_000, 'deal_price' => null])
            ->assertSuccessful();

        $this->assertSame(1, AppNotification::where('type', 'partner_promo_removed')->count());
    }

    /**
     * Decaler la fin d'une promotion deja annoncee n'est pas une offre neuve.
     * Notifier a chaque retouche noierait le signal.
     */
    public function test_decaler_la_fin_ne_notifie_pas(): void
    {
        $id = $this->creerVehicule($this->promotion())->json('data.id');
        $avant = AppNotification::where('type', 'partner_promo')->count();

        $this->modifierVehicule($id, $this->promotion([
            'deal_ends_at' => now()->addDays(20)->toIso8601String(),
        ]))->assertSuccessful();

        $this->assertSame($avant, AppNotification::where('type', 'partner_promo')->count());
    }

    /**
     * Un BROUILLON ne notifie pas.
     *
     * La promotion n'est dans la vitrine de personne tant que l'annonce
     * n'est pas publiee. Et sans cette condition, un vendeur qui compose en
     * plusieurs passes — pose, retire, repose — produit une notification a
     * chaque fois, pour chaque membre du personnel.
     */
    public function test_une_promotion_sur_un_brouillon_ne_notifie_pas(): void
    {
        $this->creerVehicule($this->promotion())->assertCreated();

        $this->assertSame(0, AppNotification::where('type', 'partner_promo')->count());
    }

    /** Le personnel n'a pas a se notifier de ce qu'il fait sur son stock. */
    public function test_le_stock_du_personnel_ne_notifie_pas(): void
    {
        Sanctum::actingAs(\App\Models\User::factory()->create(['role' => 'manager', 'is_active' => true]));

        $this->postJson('/api/vehicles', $this->vehicule(['sale_price' => 5_000_000]))
            ->assertCreated();

        $this->assertSame(0, AppNotification::where('type', 'partner_promo')->count());
    }

    // ── L'ARGENT ──────────────────────────────────────────────────────────

    /**
     * LE PANIER FACTURE CE QUE LA CARTE ANNONCE.
     *
     * Quand une ligne de commande ne porte pas son propre `unit_price`, le
     * controleur prenait `selling_price` — c'est-a-dire la COLONNE, donc le
     * prix NORMAL. Une piece affichee a 8 000 en promotion se facturait
     * 10 000 : un ecart d'argent entre ce qui est montre et ce qui est
     * encaisse, et c'est l'acheteur qui le decouvre.
     */
    public function test_une_commande_facture_le_prix_du_jour(): void
    {
        $id = $this->publier(
            $this->creerPiece([
                'selling_price' => 10_000,
                'deal_price' => 8_000,
                'deal_starts_at' => now()->subDay()->toIso8601String(),
                'deal_ends_at' => now()->addDays(7)->toIso8601String(),
            ])->json('data.id'),
            Part::class,
        );

        $this->oublierLaSession();
        Sanctum::actingAs(\App\Models\User::factory()->create([
            'role' => 'admin', 'is_active' => true,
        ]));

        $reponse = $this->postJson('/api/part-orders', [
            'ordered_at' => now()->toDateString(),
            'items' => [['part_id' => $id, 'quantity' => 2]],
        ])->assertCreated();

        $this->assertSame(8_000.0, (float) $reponse->json('data.items.0.unit_price'),
            'La carte annonce 8 000 : la commande doit encaisser 8 000.');
    }

    /** Une promotion finie ne remise plus rien, la commande non plus. */
    public function test_une_commande_apres_l_expiration_facture_le_prix_normal(): void
    {
        $id = $this->publier(
            $this->creerPiece([
                'selling_price' => 10_000,
                'deal_price' => 8_000,
                'deal_starts_at' => now()->subDays(10)->toIso8601String(),
                'deal_ends_at' => now()->addDay()->toIso8601String(),
            ])->json('data.id'),
            Part::class,
        );
        Part::findOrFail($id)->forceFill(['deal_ends_at' => now()->subDay()])->save();

        $this->oublierLaSession();
        Sanctum::actingAs(\App\Models\User::factory()->create([
            'role' => 'admin', 'is_active' => true,
        ]));

        $reponse = $this->postJson('/api/part-orders', [
            'ordered_at' => now()->toDateString(),
            'items' => [['part_id' => $id, 'quantity' => 1]],
        ])->assertCreated();

        $this->assertSame(10_000.0, (float) $reponse->json('data.items.0.unit_price'));
    }

    // ── LE PLAFOND A ZERO ─────────────────────────────────────────────────

    /**
     * Un plafond a zero INTERDIT, il n'ouvre pas.
     *
     * Le garde portait `$plafond > 0 &&`, de sorte qu'un reglage a 0 le
     * desarmait au lieu de fermer les promotions. Or le seul moment ou
     * quelqu'un taperait 0 serait justement pour les arreter.
     */
    public function test_un_plafond_a_zero_interdit_toute_remise(): void
    {
        config()->set('partenaires.promotion_remise_max_pourcentage', 0);

        // Une vraie remise, et non un franc : l epsilon du garde absorbe
        // les ecarts infimes, ce qui est voulu.
        $this->creerVehicule($this->promotion(['deal_price' => 4_000_000]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('deal_price');
    }

    // ── LES DEUX CALCULS DOIVENT S'ACCORDER ───────────────────────────────

    /**
     * Le verdict PHP et le verdict SQL portent sur les memes donnees.
     *
     * S'ils divergeaient, la carte afficherait un prix que le filtre ignore —
     * et personne ne le verrait, chacun des deux etant juste de son cote.
     */
    public function test_le_calcul_php_et_le_calcul_sql_s_accordent(): void
    {
        $cas = [
            ['debut' => now()->subDay(), 'fin' => now()->addDay(), 'attendu' => 4_000_000.0],
            ['debut' => now()->addDay(), 'fin' => now()->addDays(5), 'attendu' => 5_000_000.0],
            ['debut' => now()->subDays(5), 'fin' => now()->subDay(), 'attendu' => 5_000_000.0],
        ];

        foreach ($cas as $i => $cible) {
            $id = $this->creerVehicule($this->promotion())->json('data.id');
            $vehicule = Vehicle::findOrFail($id);
            $vehicule->forceFill([
                'deal_starts_at' => $cible['debut'],
                'deal_ends_at' => $cible['fin'],
            ])->save();

            $this->assertSame($cible['attendu'], PrixEffectif::de($vehicule->refresh(), 'sale_price'),
                "Cas $i : le calcul PHP s ecarte de ce qui est attendu.");

            $trouve = Vehicle::whereKey($id)
                ->tap(fn ($q) => PrixEffectif::filtrer($q, 'sale_price', '=', $cible['attendu']))
                ->exists();

            $this->assertTrue($trouve, "Cas $i : le calcul SQL s ecarte du calcul PHP.");
        }
    }

    // ── Le decor ──────────────────────────────────────────────────────────

    /** Publier l'annonce, sans quoi le catalogue public ne la rend pas. */
    private function publier(int $id, string $classe = Vehicle::class): int
    {
        $annonce = $classe::findOrFail($id);
        $champs = ['partner_published_at' => now()];

        // Seuls les vehicules ont `published_at` : une piece est publique
        // des que son compte l'est et qu'elle porte `partner_published_at`.
        if ($annonce->getConnection()->getSchemaBuilder()->hasColumn($annonce->getTable(), 'published_at')) {
            $champs['published_at'] = now();
        }

        $annonce->forceFill($champs)->save();

        return $id;
    }
}
