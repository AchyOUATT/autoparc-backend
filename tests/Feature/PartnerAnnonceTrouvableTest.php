<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\EngineType;
use App\Models\PartnerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Une annonce partenaire est-elle TROUVABLE par le catalogue public ?
 *
 * ── LE DEFAUT, ET POURQUOI IL ETAIT INVISIBLE ──────────────────────────────
 *
 * Le formulaire partenaire de l'application ne posait ni le type de vehicule,
 * ni la situation administrative, ni la carrosserie, ni le kilometrage. Rien ne
 * s'en plaignait : le serveur accepte ces champs en `nullable`, donc la
 * creation rendait 201 et l'annonce existait.
 *
 * Mais `vehicle_type` et `registration_status` portent une VALEUR PAR DEFAUT
 * en base — `passenger` et `unregistered`. Une annonce qui ne les envoie pas
 * n'est donc pas « non classee » : elle est classee en silence, et
 * possiblement a tort. Une camionnette immatriculee se rangeait en voiture
 * particuliere non immatriculee.
 *
 * Et le catalogue public filtre sur les deux, plus `body_style`, `condition` et
 * `mileage_max`. Resultat : l'annonce etait absente des filtres qui comptent,
 * ou presente sous une fausse etiquette — ce qui est pire, parce qu'un acheteur
 * cherchant un utilitaire ne la voyait pas, et qu'un acheteur cherchant une
 * voiture particuliere la voyait a tort.
 *
 * ── CE QUE CE BANC EPROUVE, ET DANS QUEL ORDRE ────────────────────────────
 *
 * Le premier test prouve que les reponses du formulaire ARRIVENT jusqu'aux
 * filtres : c'est la correction. Le second documente le comportement par
 * defaut, pour que personne ne retire les questions du formulaire en croyant
 * qu'elles sont decoratives.
 *
 * Verifier le 201 ne prouverait rien : le serveur rendait deja 201 avant. Ce
 * qui compte est ce que le CATALOGUE rend ensuite.
 */
class PartnerAnnonceTrouvableTest extends TestCase
{
    use RefreshDatabase;

    private PartnerAccount $compte;

    private array $identite;

    protected function setUp(): void
    {
        parent::setUp();

        $marque = Brand::create(['name' => 'Toyota']);
        $modele = VehicleModel::create(['brand_id' => $marque->id, 'name' => 'Hiace']);
        $moteur = EngineType::create(['code' => 'diesel', 'label' => 'Diesel']);

        $this->identite = [
            'brand_id' => $marque->id,
            'vehicle_model_id' => $modele->id,
            'engine_type_id' => $moteur->id,
            'manufacturing_year' => 2018,
            'availability' => 'sale',
            'sale_price' => 9_500_000,
        ];

        $this->compte = PartnerAccount::create([
            'user_id' => User::factory()->create(['role' => 'partner', 'is_active' => true])->id,
            'company_name' => 'Garage Kossodo',
            'contact_name' => 'Responsable',
            'phone' => '+22670000000',
            'status' => 'approved',
        ]);
    }

    /**
     * Cree une annonce en partenaire et la publie.
     *
     * La publication n'est pas un detail : `visibleToPublic()` exige
     * `partner_published_at` non nul pour une annonce appartenant a un
     * partenaire. Sans cet appel, tous les comptages ci-dessous rendraient zero
     * et le banc passerait au vert sans rien mesurer.
     */
    private function publier(array $champs): int
    {
        Sanctum::actingAs($this->compte->user);

        $id = (int) $this->postJson('/api/partner/vehicles', $this->identite + $champs)
            ->assertCreated()
            ->json('data.id');

        $this->postJson("/api/partner/vehicles/{$id}/publish")->assertOk();

        return $id;
    }

    /** Le nombre d'annonces que le catalogue public rend pour ces filtres. */
    private function trouvees(string $filtres): int
    {
        return (int) $this->getJson('/api/catalog/vehicles?'.$filtres)
            ->assertOk()
            ->json('meta.total');
    }

    public function test_une_annonce_classee_est_trouvee_par_chaque_filtre(): void
    {
        $id = $this->publier([
            'vehicle_type' => 'utility',
            'registration_status' => 'registered',
            'body_style' => 'van',
            'condition' => 'used',
            'registration' => ['mileage_km' => 120_000],
        ]);

        // D'abord : l'annonce est bien publique. Sans quoi tout ce qui suit
        // mesurerait zero contre zero.
        $this->assertSame(1, $this->trouvees(''), 'L annonce n est pas publique.');

        $this->assertSame(1, $this->trouvees('vehicle_type=utility'));
        $this->assertSame(1, $this->trouvees('registration_status=registered'));
        $this->assertSame(1, $this->trouvees('body_style=van'));
        $this->assertSame(1, $this->trouvees('condition=used'));
        $this->assertSame(1, $this->trouvees('mileage_max=150000'));

        // Et elle ne repond pas aux filtres qui ne la decrivent pas : un
        // comptage qui rendrait 1 partout ne prouverait rien.
        $this->assertSame(0, $this->trouvees('vehicle_type=passenger'));
        $this->assertSame(0, $this->trouvees('registration_status=unregistered'));
        $this->assertSame(0, $this->trouvees('body_style=sedan'));
        $this->assertSame(0, $this->trouvees('mileage_max=100000'));

        // Le kilometrage est alle dans le bloc qui correspond au statut.
        $this->assertDatabaseHas('vehicle_registration_details', [
            'vehicle_id' => $id,
            'mileage_km' => 120_000,
        ]);
    }

    public function test_le_kilometrage_d_un_import_va_dans_le_bloc_import(): void
    {
        $id = $this->publier([
            'vehicle_type' => 'passenger',
            'registration_status' => 'unregistered',
            'condition' => 'used',
            'import' => ['odometer_at_import_km' => 80_000],
        ]);

        $this->assertDatabaseHas('vehicle_import_details', [
            'vehicle_id' => $id,
            'odometer_at_import_km' => 80_000,
        ]);
    }

    /**
     * LE BANC QUI EXPLIQUE POURQUOI LES QUESTIONS DU FORMULAIRE NE SONT PAS
     * DECORATIVES.
     *
     * Il ne constate pas un bug a corriger : il fixe le comportement par
     * defaut de la base, pour que retirer ces champs du formulaire fasse
     * tomber quelque chose.
     */
    public function test_sans_reponse_l_annonce_est_classee_en_silence(): void
    {
        $id = $this->publier(['condition' => 'used']);

        $vehicule = Vehicle::findOrFail($id);
        $this->assertSame('passenger', $vehicule->vehicle_type,
            'La colonne a un defaut : l annonce est classee, pas laissee vide.');
        $this->assertSame('unregistered', $vehicule->registration_status->value);
        $this->assertNull($vehicule->body_style);

        // D'ou le tort : une camionnette declaree sans type se presente comme
        // une voiture particuliere, et reste introuvable pour qui cherche un
        // utilitaire.
        $this->assertSame(1, $this->trouvees('vehicle_type=passenger'));
        $this->assertSame(0, $this->trouvees('vehicle_type=utility'));

        // Et aucune carrosserie ne la rend : `body_style` nul ne repond a
        // aucun filtre, contrairement aux deux colonnes a defaut.
        $this->assertSame(0, $this->trouvees('body_style=van'));
        $this->assertSame(0, $this->trouvees('mileage_max=1000000'),
            'Sans bloc de kilometrage, le filtre ne peut pas la joindre.');
    }

    /**
     * CE QUE LE SERVEUR DETRUIT, ET POURQUOI LE CLIENT DOIT LE SAVOIR.
     *
     * `syncTypeSpecificDetails` supprime le carnet d'immatriculation des qu'il
     * ecrit un bloc import :
     *
     *   if (Unregistered && ! empty($data['import'])) {
     *       $vehicle->importDetail()->updateOrCreate(...);
     *       $vehicle->registrationDetail()?->delete();
     *   }
     *
     * La condition `! empty` est tout : un bloc import VIDE ne detruit rien.
     * C'est sur elle que repose la correction cote application — le formulaire
     * partenaire vide le kilometrage quand la situation s'ecarte de celle qui
     * est enregistree, precisement pour ne pas envoyer ce bloc par accident.
     * Le kilometrage y etant prerempli a l'edition, basculer la situation
     * suffisait sinon a declencher la suppression sans que le vendeur ait rien
     * tape.
     *
     * Ce banc fixe donc le couplage. Si la condition changeait ici, la
     * correction cote client deviendrait soit inutile, soit insuffisante — et
     * rien ne le dirait.
     */
    public function test_un_bloc_import_non_vide_supprime_le_carnet_d_immatriculation(): void
    {
        $id = $this->publier([
            'vehicle_type' => 'passenger',
            'registration_status' => 'registered',
            'condition' => 'used',
            'registration' => ['mileage_km' => 250_000],
        ]);

        $this->assertDatabaseHas('vehicle_registration_details', ['vehicle_id' => $id]);

        // Bascule vers l'import AVEC un compteur : le carnet part.
        $this->patchJson("/api/partner/vehicles/{$id}", $this->identite + [
            'vehicle_type' => 'passenger',
            'registration_status' => 'unregistered',
            'condition' => 'used',
            'import' => ['odometer_at_import_km' => 95_000],
        ])->assertOk();

        $this->assertDatabaseMissing('vehicle_registration_details', ['vehicle_id' => $id]);
        $this->assertDatabaseHas('vehicle_import_details', [
            'vehicle_id' => $id,
            'odometer_at_import_km' => 95_000,
        ]);
    }

    /** La meme bascule SANS bloc import ne detruit rien : c'est la porte de sortie. */
    public function test_une_bascule_sans_bloc_import_ne_supprime_rien(): void
    {
        $id = $this->publier([
            'vehicle_type' => 'passenger',
            'registration_status' => 'registered',
            'condition' => 'used',
            'registration' => ['mileage_km' => 250_000],
        ]);

        $this->patchJson("/api/partner/vehicles/{$id}", $this->identite + [
            'vehicle_type' => 'passenger',
            'registration_status' => 'unregistered',
            'condition' => 'used',
        ])->assertOk();

        // C est la porte sur laquelle repose la correction du formulaire.
        $this->assertDatabaseHas('vehicle_registration_details', [
            'vehicle_id' => $id,
            'mileage_km' => 250_000,
        ]);
    }

    /**
     * Les quatre champs restent REFUSES a personne : ce sont des reponses du
     * vendeur, pas des decisions de la boutique. Ce banc les tient ouverts,
     * pour qu'un elargissement du garde `missing` ne les ferme pas par
     * ricochet — ce serait une regression, pas un durcissement.
     */
    public function test_ces_champs_restent_ouverts_au_partenaire(): void
    {
        Sanctum::actingAs($this->compte->user);

        foreach ([
            'vehicle_type' => 'heavy',
            'registration_status' => 'registered',
            'body_style' => 'truck',
            'condition' => 'damaged',
        ] as $champ => $valeur) {
            $this->postJson('/api/partner/vehicles', $this->identite + [$champ => $valeur])
                ->assertCreated();
        }
    }
}
