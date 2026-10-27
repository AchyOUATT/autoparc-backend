<?php

namespace Tests\Feature;

use App\Models\CustomerNeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * De quoi RAPPELER la personne qui depose un besoin.
 *
 * ── LE DEFAUT, ET CE N'ETAIT PAS « PAS OBLIGATOIRE » ──────────────────────
 *
 * `customer_needs` portait deja `contact_phone`, `contact_name` et
 * `contact_email`, tous trois dans `$fillable`. Le modele Flutter les LISAIT,
 * la liste du personnel les AFFICHAIT. Mais `StoreCustomerNeedRequest` n'en
 * validait AUCUN, et le controleur fait
 * `CustomerNeed::create($request->validated())` : un numero envoye par le
 * client etait donc jete en silence, et la colonne restait nulle.
 *
 * Et `submit_need_page.dart` ne l'envoyait meme pas. Sa charge se limitait a
 * `type`, `description`, `budget_max` et le `firebase_uid` quand il y en avait
 * un.
 *
 * QUI EST INJOIGNABLE, EXACTEMENT. Pas « un visiteur anonyme » : l'ecran de
 * depot exige une connexion, son garde etant `!auth.isAuthenticated`. Mais ce
 * garde laisse passer le PERSONNEL autant que les clients, et le
 * `firebase_uid` ne part que `if (auth.isClient)`. Un membre du personnel qui
 * saisit la demande de quelqu'un au comptoir n'envoyait donc AUCUN contact, et
 * c'est le cas le plus courant. S'y ajoute l'appel direct a l'API, cette route
 * n'ayant aucun middleware d'authentification.
 *
 * Un `firebase_uid` donne d'ailleurs un compte, pas un numero — et ici on
 * rappelle au telephone.
 *
 * ── CE QUE CE BANC TIENT ──────────────────────────────────────────────────
 *
 * Que les numeros arrivent, qu'il puisse y en avoir plusieurs, que le premier
 * reste dans l'ancienne colonne pour l'application deja publiee, et que
 * l'exigence s'arme par un drapeau et non d'un coup — exiger aujourd'hui ce
 * que le binaire en circulation ne sait pas envoyer ferait repondre 422 a
 * chaque depot, et un besoin est une demande d'acheteur.
 */
class JoindreUnBesoinTest extends TestCase
{
    use RefreshDatabase;

    /** @param  array<string, mixed>  $champs */
    private function deposer(array $champs = []): TestResponse
    {
        return $this->postJson('/api/needs', array_merge([
            'type' => 'vehicle',
            'description' => 'Cherche une berline diesel, boite automatique, moins de 150 000 km.',
        ], $champs));
    }

    // ── Les numeros arrivent, et le premier reste la ou l'app publiee le lit ─

    public function test_les_numeros_sont_enregistres(): void
    {
        $this->deposer(['contact_phones' => ['+22670000001', '+22675000002']])
            ->assertCreated();

        $besoin = CustomerNeed::firstOrFail();

        $this->assertSame(['+22670000001', '+22675000002'], $besoin->contact_phones);
    }

    /**
     * L'application PUBLIEE lit `contact_phone` et ignore `contact_phones`.
     * Cesser d'ecrire l'ancienne colonne ferait disparaitre le numero de
     * l'ecran du personnel aujourd'hui installe — celui qui sert a rappeler.
     */
    public function test_le_premier_numero_reste_dans_l_ancienne_colonne(): void
    {
        $this->deposer(['contact_phones' => ['+22670000001', '+22675000002']])
            ->assertCreated();

        $this->assertSame('+22670000001', CustomerNeed::firstOrFail()->contact_phone);
    }

    public function test_un_seul_numero_suffit(): void
    {
        $this->deposer(['contact_phones' => ['+22670000001']])->assertCreated();

        $this->assertSame(['+22670000001'], CustomerNeed::firstOrFail()->contact_phones);
    }

    /**
     * `contact_name` et `contact_email` etaient dans `$fillable` et affiches
     * par l'application, mais jamais valides : ils etaient jetes en silence.
     */
    public function test_le_nom_et_le_courriel_arrivent_aussi(): void
    {
        $this->deposer([
            'contact_phones' => ['+22670000001'],
            'contact_name' => 'Awa Traore',
            'contact_email' => 'awa@example.test',
        ])->assertCreated();

        $besoin = CustomerNeed::firstOrFail();
        $this->assertSame('Awa Traore', $besoin->contact_name);
        $this->assertSame('awa@example.test', $besoin->contact_email);
    }

    // ── Ce que la validation refuse ────────────────────────────────────────

    public function test_plus_de_trois_numeros_est_refuse(): void
    {
        $this->deposer(['contact_phones' => [
            '+22670000001', '+22675000002', '+22676000003', '+22677000004',
        ]])->assertUnprocessable()->assertJsonValidationErrors('contact_phones');

        $this->assertSame(0, CustomerNeed::count());
    }

    public function test_un_numero_vide_ou_trop_court_est_refuse(): void
    {
        foreach ([[''], ['12345'], ['+22670000001', '']] as $numeros) {
            $this->deposer(['contact_phones' => $numeros])->assertUnprocessable();
        }

        $this->assertSame(0, CustomerNeed::count());
    }

    public function test_une_liste_vide_est_refusee(): void
    {
        $this->deposer(['contact_phones' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_phones');
    }

    public function test_un_courriel_mal_forme_est_refuse(): void
    {
        $this->deposer([
            'contact_phones' => ['+22670000001'],
            'contact_email' => 'pas-une-adresse',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_email');
    }

    // ── L'EXIGENCE S'ARME, ELLE NE TOMBE PAS D'UN COUP ────────────────────

    /**
     * LE BANC QUI PROTEGE L'APPLICATION PUBLIEE.
     *
     * Son `submit_need_page.dart` n'envoie aucun contact. Drapeau ferme, son
     * depot doit continuer de passer : sinon chaque demande d'acheteur venant
     * d'un telephone installe serait perdue jusqu'a ce que son proprietaire
     * mette a jour.
     */
    public function test_drapeau_ferme_un_besoin_sans_numero_passe(): void
    {
        config()->set('boutique.besoin_telephone_obligatoire', false);

        $this->deposer()->assertCreated();

        $besoin = CustomerNeed::firstOrFail();
        $this->assertNull($besoin->contact_phones);
        $this->assertNull($besoin->contact_phone);
    }

    public function test_drapeau_arme_un_besoin_sans_numero_est_refuse(): void
    {
        config()->set('boutique.besoin_telephone_obligatoire', true);

        $this->deposer()
            ->assertUnprocessable()
            ->assertJsonValidationErrors('contact_phones');

        $this->assertSame(0, CustomerNeed::count());
    }

    public function test_drapeau_arme_un_besoin_avec_numero_passe(): void
    {
        config()->set('boutique.besoin_telephone_obligatoire', true);

        $this->deposer(['contact_phones' => ['+22670000001']])->assertCreated();

        $this->assertSame('+22670000001', CustomerNeed::firstOrFail()->contact_phone);
    }

    /** Le defaut du reglage est FERME : une installation neuve ne casse rien. */
    public function test_le_defaut_du_reglage_est_ferme(): void
    {
        $this->assertFalse(
            (bool) config('boutique.besoin_telephone_obligatoire'),
            'Arme par defaut, ce reglage casserait le depot de besoin depuis '
            .'l application publiee.',
        );
    }
}
