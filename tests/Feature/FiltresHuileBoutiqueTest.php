<?php

namespace Tests\Feature;

use App\Enums\FitmentSource;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartFitment;
use App\Models\VehicleModel;
use Database\Seeders\FiltresHuileBoutiqueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le catalogue de filtres a huile de la boutique.
 *
 * Ce sont les premieres vraies pieces, et elles partent en production. Ce qui
 * se defend mal tout seul est verrouille ici.
 *
 * *Les compatibilites sont declarees.* Si elles etaient ecrites en `generated`,
 * `catalog:backfill-fitments --fresh` les effacerait au premier recalcul du
 * catalogue — et personne ne s'en apercevrait avant qu'un client ne trouve plus
 * rien.
 *
 * *Les modeles sont designes par leur slug.* Un seeder qui figerait les
 * identifiants de production serait faux partout ailleurs. Le test tourne sur
 * une base fraiche, avec ses propres identifiants : il echoue si quelqu'un
 * revient a des identifiants en dur.
 *
 * *Un carter ne prend pas deux familles de filtre.* Un filtre visse et une
 * cartouche s'excluent sur un meme moteur. Le document du commercant se
 * contredisait sur quatre lignes ; un test refuse qu'elles reviennent.
 *
 * *Le seeder se rejoue.* Il s'execute a chaque deploiement sur une base deja
 * remplie : il doit retrouver ses pieces et remplacer leurs compatibilites,
 * jamais les empiler.
 */
class FiltresHuileBoutiqueTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCES = [
        '90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZD2',
        '90915-YZZN1', '90915-YZZN2', '04152-YZZA1', '04152-YZZA6',
        '90915-YZZD4', '90915-10001',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed([
            \Database\Seeders\PartCategoriesSeeder::class,
            \Database\Seeders\ManufacturersSeeder::class,
            \Database\Seeders\BrandsSeeder::class,
            \Database\Seeders\VehicleModelsSeeder::class,
        ]);
    }

    private function semer(): void
    {
        $this->seed(FiltresHuileBoutiqueSeeder::class);
    }

    public function test_les_huit_filtres_sont_ecrits(): void
    {
        $this->semer();

        foreach (self::REFERENCES as $sku) {
            $piece = Part::where('sku', $sku)->first();

            $this->assertNotNull($piece, "Le filtre {$sku} manque.");
            $this->assertSame(3000.0, (float) $piece->selling_price, $sku);
            $this->assertSame('aftermarket', $piece->type->value ?? $piece->type, $sku);
            $this->assertTrue((bool) $piece->is_active, $sku);
        }
    }

    public function test_les_filtres_sont_ranges_dans_la_bonne_categorie(): void
    {
        $this->semer();

        foreach (Part::whereIn('sku', self::REFERENCES)->with('category')->get() as $piece) {
            $this->assertSame('Filtre à huile', $piece->category->name, $piece->sku);
        }
    }

    /**
     * Le test le plus important du fichier.
     *
     * En `generated`, le premier `catalog:backfill-fitments --fresh` effacerait
     * tout le travail d'arbitrage du commercant.
     */
    public function test_toutes_les_compatibilites_sont_declarees(): void
    {
        $this->semer();

        $this->assertGreaterThan(0, PartFitment::count());
        $this->assertSame(
            0,
            PartFitment::where('source', FitmentSource::Generated)->count(),
            'Une compatibilite fabriquee serait effacee au prochain recalcul.',
        );
    }

    /** Et la preuve par l'usage : le recalcul ne touche pas les dix lignes. */
    public function test_le_recalcul_du_catalogue_epargne_les_lignes_confirmees(): void
    {
        $this->semer();

        $avant = PartFitment::orderBy('id')->pluck('id')->all();

        $this->artisan('catalog:backfill-fitments --fresh --force')->assertSuccessful();

        $apres = PartFitment::whereIn('id', $avant)->orderBy('id')->pluck('id')->all();

        $this->assertSame($avant, $apres,
            'Le recalcul a detruit des compatibilites confirmees par le commercant.');
    }

    /**
     * Le revers de la medaille, et il faut le dire.
     *
     * Cinq references sont publiees sans aucune compatibilite, le temps de les
     * verifier moteur par moteur. Or `catalog:backfill-fitments` selectionne
     * justement les pieces qui n'en ont aucune : lance aujourd'hui, il
     * fabriquerait pour elles exactement les compatibilites approximatives
     * qu'on vient de refuser de publier.
     *
     * Ce test ne protege rien : il constate. Il existe pour que le jour ou
     * quelqu'un lance la commande sur la base de production, personne ne puisse
     * dire que c'etait imprevisible — et pour qu'il se mette a echouer si le
     * comportement de la commande change.
     */
    public function test_le_recalcul_remplit_les_references_laissees_vides(): void
    {
        $this->semer();

        $vides = ['90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZN2', '04152-YZZA6'];

        foreach ($vides as $sku) {
            $this->assertCount(0, Part::where('sku', $sku)->firstOrFail()->fitments);
        }

        $this->artisan('catalog:backfill-fitments --force')->assertSuccessful();

        $remplies = 0;

        foreach ($vides as $sku) {
            $piece = Part::where('sku', $sku)->with('fitments')->firstOrFail();

            if ($piece->fitments->isNotEmpty()) {
                $remplies++;
                $this->assertSame(
                    FitmentSource::Generated,
                    $piece->fitments->first()->source,
                    'Au moins ces lignes-la s\'annoncent comme fabriquees.',
                );
            }
        }

        $this->assertGreaterThan(0, $remplies,
            'Si la commande ne les remplit plus, cette mise en garde est perimee : retirer ce test.');
    }

    /**
     * Les slugs se resolvent sur une base fraiche, dont les identifiants n'ont
     * rien a voir avec ceux de la production.
     */
    public function test_chaque_compatibilite_pointe_sur_un_modele_reel(): void
    {
        $this->semer();

        $lignes = PartFitment::with('vehicleModel')->get();

        $this->assertCount(16, $lignes,
            "Des compatibilites ont ete perdues : un slug ne se resout plus.");

        foreach ($lignes as $ligne) {
            $this->assertNotNull($ligne->vehicleModel, "Compatibilite orpheline : {$ligne->id}");
        }
    }

    /**
     * Un carter prend un filtre visse OU une cartouche.
     *
     * On compare les periodes : une meme generation peut recevoir les deux si
     * elle traverse le changement de moteur — la Corolla E140 est vissee
     * jusqu'en 2008 et a cartouche ensuite. Ce qui est interdit, c'est le
     * recouvrement.
     */
    public function test_aucun_modele_ne_recoit_un_visse_et_une_cartouche_la_meme_annee(): void
    {
        $this->semer();

        $lignes = PartFitment::with(['part', 'vehicleModel'])->get()
            ->groupBy('vehicle_model_id');

        $this->assertNotEmpty($lignes, 'Sans compatibilites, ce test ne verifie rien.');

        $conflits = [];

        foreach ($lignes as $modeleId => $duModele) {
            $visses = $duModele->filter(fn ($f) => str_starts_with($f->part->sku, '90915'));
            $cartouches = $duModele->filter(fn ($f) => str_starts_with($f->part->sku, '04152'));

            foreach ($visses as $v) {
                foreach ($cartouches as $c) {
                    if ($this->seChevauchent($v, $c)) {
                        $conflits[] = sprintf(
                            'Le modele %s recoit a la fois %s (visse) et %s (cartouche) sur une periode commune.',
                            $v->vehicleModel?->slug ?? $modeleId,
                            $v->part->sku,
                            $c->part->sku,
                        );
                    }
                }
            }
        }

        $this->assertSame([], $conflits, implode(PHP_EOL, $conflits));
    }

    /**
     * Le detecteur de chevauchement fonctionne.
     *
     * Le test precedent ne croise aujourd'hui aucun modele portant les deux
     * familles : ses boucles ne s'executent pas, et il passerait meme si la
     * regle etait fausse. Ce qui suit eprouve donc l'outil lui-meme, pour que
     * la garde serve encore le jour ou les compatibilites en attente seront
     * publiees.
     */
    public function test_le_detecteur_de_chevauchement_detecte(): void
    {
        $chevauchent = fn (?int $d1, ?int $f1, ?int $d2, ?int $f2) => $this->seChevauchent(
            new PartFitment(['year_from' => $d1, 'year_to' => $f1]),
            new PartFitment(['year_from' => $d2, 'year_to' => $f2]),
        );

        // Periodes disjointes : la Corolla E140, vissee jusqu'en 2008 puis a
        // cartouche. C'est le cas legitime, il ne doit pas alerter.
        $this->assertFalse($chevauchent(2006, 2008, 2009, 2013));

        // Periodes communes : le cas qu'on refuse de publier.
        $this->assertTrue($chevauchent(2006, 2013, 2009, 2017));
        $this->assertTrue($chevauchent(2018, 2020, 2018, 2020));

        // Bornes ouvertes : « toute la generation » recouvre tout.
        $this->assertTrue($chevauchent(null, null, 2015, 2016));
        $this->assertTrue($chevauchent(2019, null, null, 2020));

        // Annees jointives : 2008 et 2009 ne se touchent pas.
        $this->assertFalse($chevauchent(2000, 2008, 2009, 2015));
    }

    private function seChevauchent(PartFitment $a, PartFitment $b): bool
    {
        $debutA = $a->year_from ?? 1900;
        $finA   = $a->year_to   ?? 2100;
        $debutB = $b->year_from ?? 1900;
        $finB   = $b->year_to   ?? 2100;

        return $debutA <= $finB && $debutB <= $finA;
    }

    /** L'ancien numero de service doit mener au filtre actuel. */
    public function test_le_numero_remplace_pointe_sur_le_filtre_actuel(): void
    {
        $this->semer();

        $ancien = OemNumber::where('number', '90915-10009')->first();

        $this->assertNotNull($ancien, 'Le numero remplace n\'a pas ete enregistre.');
        $this->assertTrue((bool) $ancien->is_superseded);

        $actuel = OemNumber::find($ancien->superseded_by_id);

        $this->assertNotNull($actuel);
        $this->assertSame('90915-YZZN1', $actuel->number);
    }

    public function test_chaque_filtre_porte_son_numero_constructeur(): void
    {
        $this->semer();

        foreach (Part::whereIn('sku', self::REFERENCES)->with('oemNumbers')->get() as $piece) {
            $numeros = $piece->oemNumbers->pluck('number')->all();

            $this->assertContains($piece->sku, $numeros, $piece->sku);
        }
    }

    /** Rejoue a chaque deploiement : rien ne doit s'empiler ni se perdre. */
    public function test_rejouer_le_seeder_ne_change_rien(): void
    {
        $this->semer();

        $pieces = Part::count();
        $lignes = PartFitment::count();
        $numeros = OemNumber::count();

        $this->semer();

        $this->assertSame($pieces, Part::count());
        $this->assertSame($lignes, PartFitment::count());
        $this->assertSame($numeros, OemNumber::count());
    }

    /**
     * Une categorie absente n'ecrit rien plutot que de ranger les filtres
     * n'importe ou.
     */
    public function test_sans_la_categorie_le_seeder_n_ecrit_rien(): void
    {
        DB::table('part_categories')->where('name', 'Filtre à huile')->delete();

        $this->semer();

        $this->assertSame(0, Part::whereIn('sku', self::REFERENCES)->count());
    }

    /** Les compatibilites designent des modeles Toyota et Lexus, pas d'autres. */
    public function test_les_modeles_rattaches_sont_ceux_attendus(): void
    {
        $this->semer();

        $marques = PartFitment::with('vehicleModel.brand')->get()
            ->map(fn ($f) => $f->vehicleModel?->brand?->name)
            ->unique()
            ->filter()
            ->values()
            ->all();

        sort($marques);

        $this->assertSame(['Toyota'], $marques,
            'Les dix lignes confirmees ne concernent que des Toyota : les Lexus du document attendent une verification.');
    }

    /**
     * Ce que l'audit du commercant a retire ne doit pas revenir.
     *
     * Sept lignes du document sont fausses, sources constructeur a l'appui :
     * le GX 470 est en 2UZ-FE 4.7 et releve d'une autre reference, le Fortuner
     * diesel appartient au YZZD2 et non au YZZN2, l'ES 300h XV70 est en A25A
     * donc a filtre visse, la Prius de quatrieme generation n'est pas uniforme,
     * et une Corolla de 2019 ne prend pas une reference remplacee depuis.
     *
     * Elles ont ete retirees une fois ; ce test est ce qui les empeche de
     * rentrer par une reecriture distraite du bloc de donnees.
     */
    public function test_les_lignes_ecartees_par_l_audit_ne_reviennent_pas(): void
    {
        $this->semer();

        $interdits = [
            '90915-YZZD2' => ['gx-j120'],
            '90915-YZZN2' => ['fortuner-an50', 'fortuner-an160'],
            '04152-YZZA1' => ['nx-az10', 'es-xv70'],
            '04152-YZZA6' => ['prius-xw50'],
            '90915-YZZF2' => ['corolla-e210'],
        ];

        foreach ($interdits as $sku => $slugs) {
            $piece = Part::where('sku', $sku)->with('fitments.vehicleModel')->firstOrFail();
            $portes = $piece->fitments->pluck('vehicleModel.slug')->all();

            foreach ($slugs as $slug) {
                $this->assertNotContains($slug, $portes,
                    "L'audit avait ecarte {$slug} de {$sku}.");
            }
        }
    }

    /**
     * Une compatibilite sans code moteur n'a pas sa place au catalogue.
     *
     * C'est la regle que pose le document corrige du fournisseur, et c'est
     * elle qui a fait ecarter vingt-cinq lignes : « ne jamais confirmer un
     * filtre sur la seule base du modele et de la cylindree, verifier au
     * minimum annee + code moteur ». Une ligne qui reviendrait sans code
     * moteur serait exactement celle qu'on a refusee de publier.
     */
    public function test_chaque_compatibilite_porte_son_code_moteur(): void
    {
        $this->semer();

        foreach (PartFitment::with(['part', 'vehicleModel'])->get() as $ligne) {
            $this->assertNotEmpty(
                $ligne->engine_code,
                sprintf(
                    '%s / %s est publiee sans code moteur.',
                    $ligne->part->sku,
                    $ligne->vehicleModel->slug,
                ),
            );
        }
    }

    /**
     * Les references dont les compatibilites attendent une verification n'en
     * portent aucune, plutot que des approximations.
     *
     * Elles restent trouvables par reference et par numero constructeur : le
     * catalogue ne promet rien qu'il ne puisse tenir.
     */
    public function test_les_references_en_attente_ne_portent_aucune_compatibilite(): void
    {
        $this->semer();

        $enAttente = ['90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZN2', '04152-YZZA6', '90915-10001'];

        foreach ($enAttente as $sku) {
            $piece = Part::where('sku', $sku)->with('fitments')->firstOrFail();

            $this->assertCount(0, $piece->fitments,
                "{$sku} attend une verification moteur par moteur : aucune compatibilite ne doit y figurer.");
        }
    }

    /**
     * Le depot decrit, la boutique commerce.
     *
     * Le seeder reecrivait tout a chaque passage. Le commercant corrigeait son
     * stock le matin depuis l'application et le retrouvait faux apres le
     * deploiement du soir, sans comprendre pourquoi — la republication avait
     * remis la valeur du fichier.
     *
     * Prix et stock sont donc poses a la creation, puis laisses tranquilles.
     * Le reste — libelle, description, categorie, compatibilites — reste du
     * ressort du depot et se rafraichit a chaque publication.
     */
    public function test_rejouer_le_seeder_ne_touche_ni_au_prix_ni_au_stock(): void
    {
        $this->semer();

        $piece = Part::where('sku', '90915-YZZD2')->firstOrFail();

        // Ce que ferait le commercant depuis l'application.
        $piece->update([
            'selling_price'  => 4500,
            'stock_quantity' => 12,
            'name'           => 'Libelle modifie a la main',
        ]);

        $this->semer();

        $apres = Part::where('sku', '90915-YZZD2')->firstOrFail();

        $this->assertSame(4500.0, (float) $apres->selling_price,
            'Le prix ajuste en boutique a ete ecrase par la republication.');
        $this->assertSame(12, $apres->stock_quantity,
            'Le stock ajuste en boutique a ete ecrase par la republication.');

        // Le libelle, lui, appartient au depot : il revient a sa valeur.
        $this->assertNotSame('Libelle modifie a la main', $apres->name);
    }

    /** Un modele introuvable doit se voir, pas se perdre. */
    public function test_un_slug_devenu_introuvable_est_signale(): void
    {
        VehicleModel::where('slug', 'hilux-an10')->delete();

        $this->artisan('db:seed', ['--class' => FiltresHuileBoutiqueSeeder::class])
            ->expectsOutputToContain('modele introuvable')
            ->assertSuccessful();
    }
}
