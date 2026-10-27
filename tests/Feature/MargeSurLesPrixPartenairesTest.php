<?php

namespace Tests\Feature;

use App\Models\Accessory;
use App\Models\Part;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\MargePartenaire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Support\CreeDesAnnoncesPartenaires;
use Tests\TestCase;

/**
 * La marge de la boutique sur les prix proposes par un partenaire.
 *
 * ── CE QUI EST TENU, ET POURQUOI CHAQUE POINT COMPTE ──────────────────────
 *
 * 1. LE PRIX EN COLONNE EST LE PRIX PUBLIC. Toutes les routes qui trient ou
 *    filtrent par prix lisent cette colonne : y laisser le prix propose ferait
 *    repondre « moins de 5 000 000 » avec des annonces affichees a 5 500 000.
 *
 * 2. LE TAUX EST FIGE AVEC L'ANNONCE. Changer le reglage ne reprend pas le
 *    catalogue : un acheteur ne voit jamais un prix bouger parce qu'un reglage
 *    a bouge, et un lien de partage deja envoye reste exact.
 *
 * 3. LA CAUTION N'EST PAS MAJOREE. Elle est rendue au locataire ; en prendre
 *    une marge lui ferait recuperer moins qu'il n'a verse.
 *
 * 4. LE DETAIL NE SORT PAS. Il porte le prix propose, donc la marge de la
 *    boutique, donc sa negociation. Seuls le vendeur et le personnel le lisent.
 *
 * 5. LE PARTENAIRE NE DECLARE PAS SA PROPRE MARGE. Sans garde, il enverrait
 *    `partner_pricing` lui-meme avec le taux qu'il veut.
 */
class MargeSurLesPrixPartenairesTest extends TestCase
{
    use CreeDesAnnoncesPartenaires;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('partenaires.espace_ouvert', true);
        config()->set('partenaires.marge_pourcentage', 10);

        $this->compte = $this->compteApprouve();
    }

    // ── LE CALCUL ─────────────────────────────────────────────────────────

    public function test_le_prix_de_vente_part_majore_en_colonne(): void
    {
        $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated();

        $this->assertSame('4950000.00', Vehicle::firstOrFail()->sale_price);
    }

    public function test_les_trois_tarifs_de_location_sont_majores(): void
    {
        $this->creerVehicule([
            'availability' => 'both',
            'sale_price' => 4_500_000,
            'rental_daily_rate' => 25_000,
            'rental_weekly_rate' => 150_000,
            'rental_monthly_rate' => 450_000,
        ])->assertCreated();

        $vehicule = Vehicle::firstOrFail();
        $this->assertSame('27500.00', $vehicule->rental_daily_rate);
        $this->assertSame('165000.00', $vehicule->rental_weekly_rate);
        $this->assertSame('495000.00', $vehicule->rental_monthly_rate);
    }

    /**
     * LA CAUTION RESTE INTACTE.
     *
     * Elle est remboursee au locataire : la majorer reviendrait a facturer une
     * somme qu'on rend, et il recupererait moins qu'il n'a verse.
     */
    public function test_la_caution_n_est_pas_majoree(): void
    {
        $this->creerVehicule([
            'availability' => 'both',
            'sale_price' => 4_500_000,
            'rental_daily_rate' => 25_000,
            'rental_deposit' => 200_000,
        ])->assertCreated();

        $this->assertSame('200000.00', Vehicle::firstOrFail()->rental_deposit);
    }

    public function test_le_prix_d_une_piece_est_majore(): void
    {
        $this->creerPiece(['selling_price' => 3_000])->assertCreated();

        $this->assertSame('3300.00', Part::firstOrFail()->selling_price);
    }

    public function test_le_prix_d_un_accessoire_est_majore(): void
    {
        $this->creerAccessoire(['selling_price' => 12_500])->assertCreated();

        $this->assertSame('13750.00', Accessory::firstOrFail()->selling_price);
    }

    /** Sans reglage arme, rien ne bouge : une installation neuve n'invente aucun prix. */
    public function test_a_zero_pour_cent_le_prix_ne_bouge_pas(): void
    {
        config()->set('partenaires.marge_pourcentage', 0);

        $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated();

        $this->assertSame('4500000.00', Vehicle::firstOrFail()->sale_price);
    }

    /** Le franc CFA n'a pas de subdivision : on arrondit a l'unite. */
    public function test_le_resultat_est_arrondi_au_franc(): void
    {
        config()->set('partenaires.marge_pourcentage', 7.5);

        $this->creerPiece(['selling_price' => 3_333])->assertCreated();

        // 3333 x 1.075 = 3582.975
        $this->assertSame('3583.00', Part::firstOrFail()->selling_price);
    }

    // ── LE DETAIL, ET POUR QUI ────────────────────────────────────────────

    public function test_le_vendeur_voit_son_prix_le_taux_et_le_total(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->json('data.id');

        $detail = $this->getJson('/api/partner/vehicles/'.$id)
            ->assertOk()->json('data.partner_pricing');

        $this->assertSame(10.0, (float) $detail['taux']);
        $this->assertSame(4_500_000.0, (float) $detail['prix_proposes']['sale_price']);
        $this->assertSame(4_950_000.0, (float) $detail['total']['sale_price']);
    }

    /**
     * LE DETAIL NE SORT PAS AU PUBLIC.
     *
     * Il porte le prix propose, donc la marge de la boutique. Un concurrent
     * qui lit la fiche publique n'a pas a connaitre la negociation.
     */
    public function test_le_public_ne_voit_pas_le_detail(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->json('data.id');
        Vehicle::findOrFail($id)->update(['partner_published_at' => now(), 'published_at' => now()]);

        $this->oublierLaSession();

        $data = $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data');

        $this->assertArrayNotHasKey('partner_pricing', $data);
        $this->assertSame('4950000.00', $data['commercial']['sale_price'],
            'L acheteur doit voir le prix marge, qui est celui sur lequel le '
            .'catalogue trie et filtre.');
    }

    public function test_un_autre_partenaire_ne_voit_pas_le_detail(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->json('data.id');
        Vehicle::findOrFail($id)->update(['partner_published_at' => now(), 'published_at' => now()]);

        $autre = $this->compteApprouve();
        Sanctum::actingAs($autre->user, ['partner']);

        $this->assertArrayNotHasKey(
            'partner_pricing',
            $this->getJson('/api/catalog/vehicles/'.$id)->assertOk()->json('data'),
        );
    }

    /**
     * LE TAUX DU JOUR VOYAGE AVEC LE PROFIL.
     *
     * Sans lui, le formulaire du vendeur ne peut pas montrer le total avant
     * l'enregistrement : il decouvrirait le prix affiche apres coup, sur sa
     * propre annonce. C'est exactement ce qu'on lui a promis de lui epargner.
     */
    public function test_le_profil_porte_le_taux_du_jour(): void
    {
        Sanctum::actingAs($this->compte->user, ['partner']);

        $this->assertSame(
            10.0,
            (float) $this->getJson('/api/partner/me')
                ->assertOk()->json('boutique.marge_pourcentage'),
        );
    }

    // ── CE QUI EST FIGE ───────────────────────────────────────────────────

    /**
     * LE BANC QUI COMPTE LE PLUS.
     *
     * Changer le reglage ne doit reprendre AUCUNE annonce existante. Sinon un
     * acheteur voit un prix bouger sans que personne n'y ait touche, et un
     * lien de partage deja envoye devient faux.
     */
    public function test_changer_le_reglage_ne_reprend_pas_le_catalogue(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->json('data.id');

        config()->set('partenaires.marge_pourcentage', 30);

        $this->assertSame('4950000.00', Vehicle::findOrFail($id)->sale_price);

        $detail = $this->getJson('/api/partner/vehicles/'.$id)
            ->assertOk()->json('data.partner_pricing');

        $this->assertSame(10.0, (float) $detail['taux'],
            'Afficher le taux courant sur une annonce calculee a un autre taux '
            .'serait faux : chaque annonce porte le sien.');
    }

    /** Rouvrir son prix, c'est accepter le taux du jour. */
    public function test_modifier_le_prix_recalcule_au_taux_courant(): void
    {
        $id = $this->creerVehicule(['sale_price' => 4_500_000])->json('data.id');

        config()->set('partenaires.marge_pourcentage', 20);

        $this->modifierVehicule($id, ['sale_price' => 4_000_000])->assertSuccessful();

        $this->assertSame('4800000.00', Vehicle::findOrFail($id)->sale_price);
    }

    /**
     * Une charge SANS aucun prix ne touche pas au detail.
     *
     * Les routes d aujourd hui ne peuvent pas la produire : `sale_price` est
     * exige des que le vehicule est a vendre, et `selling_price` l est
     * toujours pour une piece. Le garde vit donc dans le service, et c est la
     * qu on l eprouve — une route de modification PARTIELLE, demain, tomberait
     * exactement dessus.
     *
     * Sans lui, corriger une faute de frappe dans la description reecrirait le
     * taux au reglage du jour alors que le prix, lui, n aurait pas bouge : le
     * vendeur lirait « marge 30 % » sur un prix calcule a 10 %.
     */
    public function test_une_charge_sans_prix_ne_touche_pas_au_detail(): void
    {
        $donnees = MargePartenaire::appliquer(
            ['description' => 'Toit ouvrant.'],
            MargePartenaire::CHAMPS_VEHICULE,
        );

        $this->assertSame(['description' => 'Toit ouvrant.'], $donnees);
    }

    // ── CE QUE LE PARTENAIRE NE DECLARE PAS ───────────────────────────────

    public function test_un_partenaire_ne_peut_pas_declarer_sa_propre_marge(): void
    {
        $this->creerVehicule([
            'sale_price' => 4_500_000,
            'partner_pricing' => ['taux' => 0, 'prix_proposes' => ['sale_price' => 4_500_000]],
        ])->assertUnprocessable()->assertJsonValidationErrors('partner_pricing');

        $this->assertSame(0, Vehicle::count());
    }

    // ── LE PERSONNEL N'EST PAS CONCERNE ───────────────────────────────────

    /**
     * Le prix du personnel est le prix du personnel.
     *
     * La marge s'applique a ce qu'un TIERS propose. L'appliquer au stock
     * interne majorerait un prix que la boutique a fixe elle-meme.
     */
    public function test_le_prix_du_personnel_n_est_pas_majore(): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => 'manager', 'is_active' => true]));

        $this->postJson('/api/vehicles', $this->vehicule(['sale_price' => 4_500_000]))
            ->assertCreated();

        $vehicule = Vehicle::firstOrFail();
        $this->assertSame('4500000.00', $vehicule->sale_price);
        $this->assertNull($vehicule->partner_pricing);
    }

    // ── Le calcul, unite par unite ────────────────────────────────────────

    /**
     * UN TAUX NEGATIF NE FAIT PAS VENDRE A PERTE.
     *
     * `-10` se tape aussi vite que `10` dans le tableau de bord de Render.
     * Sans plancher, toutes les annonces enregistrees ensuite partiraient SOUS
     * le prix que le partenaire a propose, fige dans chacune, et la boutique
     * paierait la difference sans qu'une ligne ne le dise.
     */
    public function test_un_taux_negatif_ne_baisse_pas_le_prix(): void
    {
        config()->set('partenaires.marge_pourcentage', -10);

        $this->creerVehicule(['sale_price' => 4_500_000])->assertCreated();

        $vehicule = Vehicle::firstOrFail();
        $this->assertSame('4500000.00', $vehicule->sale_price);
        $this->assertSame(0.0, (float) $vehicule->partner_pricing['taux']);
    }

    public function test_la_majoration_arrondit_au_plus_proche(): void
    {
        $this->assertSame(4_950_000.0, MargePartenaire::majorer(4_500_000, 10));
        $this->assertSame(3_583.0, MargePartenaire::majorer(3_333, 7.5));
        $this->assertSame(3_000.0, MargePartenaire::majorer(3_000, 0));
    }

    /** Un prix explicitement nul est une REPONSE, pas un oubli. */
    public function test_un_prix_nul_traverse_sans_majoration(): void
    {
        $donnees = MargePartenaire::appliquer(
            ['sale_price' => null, 'rental_daily_rate' => 25_000],
            MargePartenaire::CHAMPS_VEHICULE,
        );

        $this->assertNull($donnees['sale_price']);
        $this->assertSame(27_500.0, $donnees['rental_daily_rate']);
        $this->assertArrayNotHasKey('sale_price', $donnees['partner_pricing']['prix_proposes']);
    }

}
