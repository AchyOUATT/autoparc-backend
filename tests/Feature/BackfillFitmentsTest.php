<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\Accessory;
use App\Models\AccessoryFitment;
use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\User;
use App\Models\VehicleModel;
use App\Services\CompatibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La commande qui repare un catalogue sans compatibilites.
 *
 * Le defaut qu'elle corrige ne produisait aucune erreur : 200 pieces, 161
 * modeles, zero ligne dans `part_fitments`, et une liste de pieces compatibles
 * systematiquement vide. C'est le genre de panne qu'aucun test d'API ne voit,
 * puisque la reponse est un 200 avec un tableau vide — parfaitement valide.
 *
 * Ces tests tiennent donc le resultat metier : apres la commande, un vehicule
 * de garage doit voir des pieces. Et ils tiennent la relance, parce que la
 * commande est faite pour tourner sur la base en ligne, plusieurs fois.
 */
class BackfillFitmentsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Seule la nomenclature des pieces est seedee, pas les modeles.
     *
     * PartFactory a besoin de categories pour ranger ce qu'elle cree, mais les
     * modeles de vehicule sont poses test par test : l'un d'eux verifie
     * justement ce que fait la commande quand il n'y en a aucun.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
        ]);
    }

    private function modele(string $nom, ?int $debut = 2010, ?int $fin = null): VehicleModel
    {
        $marque = Brand::firstOrCreate(
            ['slug' => 'marque-test'],
            ['name' => 'Marque Test', 'is_active' => true],
        );

        return VehicleModel::create([
            'brand_id'         => $marque->id,
            'name'             => $nom,
            'production_start' => $debut,
            'production_end'   => $fin,
            'is_active'        => true,
        ]);
    }

    public function test_la_commande_rattache_les_pieces_orphelines(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');
        Part::factory()->count(5)->create();

        $this->assertSame(0, PartFitment::count(), 'Point de depart : aucune compatibilite.');

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        $this->assertGreaterThan(0, PartFitment::count());
        $this->assertSame(
            0,
            Part::doesntHave('fitments')->count(),
            'Aucune piece ne doit rester sans compatibilite.',
        );
    }

    /**
     * Le vrai critere : ce que voit la personne dans son garage. Compter des
     * lignes en base ne dit rien si la requete de compatibilite ne les trouve
     * pas — c'etait precisement le cas avant, sauf qu'il n'y avait pas de
     * lignes du tout.
     */
    public function test_un_vehicule_de_garage_voit_enfin_des_pieces(): void
    {
        $modele = $this->modele('Corolla', 2013, 2019);
        // Un deuxieme modele, pour que la selection ait un choix a faire.
        $this->modele('Hilux', 2015);

        Part::factory()->count(20)->create();

        $client  = User::factory()->create();
        $voiture = OwnedVehicle::create([
            'user_id'            => $client->id,
            'brand_id'           => $modele->brand_id,
            'vehicle_model_id'   => $modele->id,
            'manufacturing_year' => 2016,
        ]);

        $avant = app(CompatibilityService::class)->partsForOwnedVehicle($voiture)->count();
        $this->assertSame(0, $avant, 'Avant la commande, la liste est vide.');

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        $apres = app(CompatibilityService::class)->partsForOwnedVehicle($voiture->fresh())->count();
        $this->assertGreaterThan(0, $apres);
    }

    public function test_relancer_la_commande_ne_duplique_rien(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');
        Part::factory()->count(5)->create();
        Accessory::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();
        $premier = PartFitment::count();

        // Sans --fresh : les pieces deja traitees sont ignorees.
        $this->artisan('catalog:backfill-fitments')->assertSuccessful();
        $this->assertSame($premier, PartFitment::count());

        // Avec --fresh : tout est recalcule, et le resultat est identique
        // puisque la selection est deterministe. --force saute la question
        // que la commande pose avant de detruire quoi que ce soit.
        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();
        $this->assertSame($premier, PartFitment::count());
    }

    public function test_le_mode_essai_n_ecrit_rien(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments --dry-run')->assertSuccessful();

        $this->assertSame(0, PartFitment::count());
    }

    /**
     * Un catalogue sans modele de vehicule n'est pas une situation normale :
     * ecrire zero ligne en annoncant un succes laisserait croire que le
     * rattrapage a eu lieu.
     */
    public function test_sans_modele_la_commande_echoue_au_lieu_de_ne_rien_dire(): void
    {
        Part::factory()->count(2)->create();

        $this->artisan('catalog:backfill-fitments')->assertFailed();
    }

    public function test_la_commande_refuse_un_perimetre_inconnu(): void
    {
        $this->modele('Alpha');

        $this->artisan('catalog:backfill-fitments --only=pieces')->assertFailed();
    }

    public function test_le_perimetre_limite_ne_touche_que_ce_qu_on_lui_demande(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(3)->create();
        Accessory::factory()->count(3)->create();

        $this->artisan('catalog:backfill-fitments --only=accessories')->assertSuccessful();

        $this->assertSame(0, PartFitment::count());
        $this->assertGreaterThan(0, \App\Models\AccessoryFitment::count());
    }

    /**
     * La recherche par numero OEM etait morte pour la meme raison : la table
     * etait vide. Une piece rattachee doit desormais porter sa reference.
     */
    public function test_chaque_piece_recoit_une_reference_constructeur(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(4)->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        foreach (Part::with('oemNumbers')->get() as $piece) {
            $this->assertCount(1, $piece->oemNumbers, "SKU {$piece->sku}");
        }
    }

    // ── Ce que la commande n'a pas le droit de detruire ──────────────────

    /**
     * Le defaut que cette serie verrouille.
     *
     * `--fresh` faisait `delete()` sur toutes les lignes d'une piece avant de
     * les reecrire. Une compatibilite saisie a la main disparaissait sans un
     * mot, et la checklist de publication demande justement de lancer la
     * commande en production.
     */
    public function test_le_recalcul_epargne_une_compatibilite_saisie(): void
    {
        $modele = $this->modele('Alpha');
        $piece  = Part::factory()->create();

        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'year_from'        => 2012,
            'year_to'          => 2016,
            'position'         => 'avant gauche',
            'source'           => FitmentSource::Declared,
        ]);

        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();

        $restantes = $piece->fitments()->get();

        $this->assertCount(1, $restantes, 'La saisie a ete detruite ou noyee.');
        $this->assertSame(FitmentSource::Declared, $restantes->first()->source);
        $this->assertSame('avant gauche', $restantes->first()->position);
    }

    /**
     * Et l'inverse : la commande reste libre de refaire son propre travail,
     * sans quoi la protection l'aurait simplement paralysee.
     */
    public function test_le_recalcul_remplace_bien_ce_qu_il_a_fabrique(): void
    {
        $this->modele('Alpha');
        $this->modele('Beta');
        $piece = Part::factory()->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        $fabriquees = $piece->fitments()->count();
        $this->assertGreaterThan(0, $fabriquees);
        $this->assertSame(
            $fabriquees,
            $piece->fitments()->where('source', FitmentSource::Generated)->count(),
            'La commande doit signer ses propres lignes.',
        );

        // Une ligne parasite, comme si un ancien passage avait derive.
        $piece->fitments()->create([
            'vehicle_model_id' => VehicleModel::first()->id,
            'source'           => FitmentSource::Generated,
        ]);

        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();

        $this->assertSame($fabriquees, $piece->fitments()->count());
    }

    /**
     * Une piece dont la compatibilite est declaree n'est pas seulement
     * epargnee : elle n'est pas completee non plus. Melanger une ligne
     * verifiee et quinze lignes vraisemblables dans la meme liste reviendrait
     * a rendre la premiere indistinguable des autres.
     */
    public function test_une_piece_declaree_ne_recoit_aucune_ligne_fabriquee(): void
    {
        $modele = $this->modele('Alpha');
        $this->modele('Beta');
        $this->modele('Gamma');

        $declaree = Part::factory()->create();
        $declaree->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'source'           => FitmentSource::Declared,
        ]);

        $libre = Part::factory()->create();

        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();

        $this->assertSame(1, $declaree->fitments()->count());
        $this->assertGreaterThan(0, $libre->fitments()->count(), 'Les autres pieces doivent rester traitees.');
    }

    /** Un recalcul annonce ce qu'il va detruire et s'arrete si on dit non. */
    public function test_le_recalcul_demande_confirmation_avant_de_detruire(): void
    {
        $this->modele('Alpha');
        Part::factory()->count(2)->create();

        $this->artisan('catalog:backfill-fitments')->assertSuccessful();

        // Les identifiants, pas le nombre : la selection etant deterministe,
        // un recalcul qui supprime puis reecrit rend exactement le meme
        // compte. Compter n'aurait donc rien prouve.
        $avant = PartFitment::orderBy('id')->pluck('id')->all();
        $this->assertNotEmpty($avant);

        $this->artisan('catalog:backfill-fitments --fresh')
            ->expectsConfirmation('Continuer ?', 'no')
            ->assertFailed();

        $this->assertSame(
            $avant,
            PartFitment::orderBy('id')->pluck('id')->all(),
            'Un refus doit laisser les lignes elles-memes en place, pas seulement leur nombre.',
        );
    }

    /** La commande signe ce qu'elle fabrique, cote accessoires aussi. */
    public function test_la_commande_signe_les_lignes_d_accessoire_qu_elle_fabrique(): void
    {
        $this->modele('Alpha');
        Accessory::factory()->count(2)->create();

        $this->artisan('catalog:backfill-fitments --only=accessories')->assertSuccessful();

        $total = AccessoryFitment::count();

        $this->assertGreaterThan(0, $total);
        $this->assertSame(
            $total,
            AccessoryFitment::where('source', FitmentSource::Generated)->count(),
            'Une ligne non signee serait epargnee a tort au recalcul suivant.',
        );
    }

    /**
     * La seconde barriere, eprouvee seule.
     *
     * La commande ne peut pas l'atteindre tant que la premiere tient — une
     * piece declaree n'est jamais selectionnee. C'est precisement pourquoi
     * elle se teste ici : le jour ou quelqu'un relache la selection pour
     * completer une piece declaree, c'est ce filtre qui empechera la perte,
     * et rien ne l'aurait signale s'il avait disparu entre-temps.
     */
    public function test_la_portee_des_lignes_fabriquees_exclut_les_saisies(): void
    {
        $modele = $this->modele('Alpha');
        $piece  = Part::factory()->create();

        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'source'           => FitmentSource::Declared,
        ]);
        $piece->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'source'           => FitmentSource::Generated,
        ]);

        PartFitment::where('part_id', $piece->id)->fabriquees()->delete();

        $restantes = $piece->fitments()->get();

        $this->assertCount(1, $restantes);
        $this->assertSame(FitmentSource::Declared, $restantes->first()->source);
    }

    /** Les accessoires suivent la meme regle que les pieces. */
    public function test_le_recalcul_epargne_aussi_un_accessoire_saisi(): void
    {
        $modele     = $this->modele('Alpha');
        $accessoire = Accessory::factory()->create();

        $accessoire->fitments()->create([
            'vehicle_model_id' => $modele->id,
            'notes'            => 'verifie sur le vehicule du client',
            'source'           => FitmentSource::Declared,
        ]);

        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();

        $restantes = $accessoire->fitments()->get();

        $this->assertCount(1, $restantes);
        $this->assertSame('verifie sur le vehicule du client', $restantes->first()->notes);
    }
}
