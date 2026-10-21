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
        // Troisieme versement : les six references de vente que le catalogue
        // des neuf familles reclamait et que la boutique n'avait pas.
        '90915-YZZF1', '90915-30002', '04152-YZZA3', '04152-YZZA5',
        '04152-YZZA4', '04152-51010',
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

    public function test_toutes_les_references_sont_ecrites(): void
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

    /**
     * Les six nouvelles references arrivent au prix du rayon, pas a celui du
     * fichier.
     *
     * Consigne du proprietaire sur ce versement, textuellement : « conduis les
     * memes prix et quantite je reajusterai apres ». Prise au pied de la
     * lettre, elle interdit de recopier 3 000 en dur : s'il a monte son prix
     * depuis l'application, les references nouvelles doivent arriver au prix
     * du jour. Le test le met en scene — dix references deja en base a 5 000,
     * puis le versement.
     *
     * Le pendant de ce test est `test_rejouer_le_seeder_ne_touche_ni_au_prix
     * _ni_au_stock` : lire le prix du rayon ne doit pas devenir le reecrire.
     */
    public function test_les_references_nouvelles_prennent_le_prix_du_rayon(): void
    {
        $this->semer();

        $anciennes = ['90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZD2',
            '90915-YZZN1', '90915-YZZN2', '04152-YZZA1', '04152-YZZA6',
            '90915-YZZD4', '90915-10001'];

        // Ce que ferait le proprietaire : il ajuste son rayon, puis on
        // supprime les six nouvelles pour les faire renaitre au prix ajuste.
        Part::whereIn('sku', $anciennes)->update(['selling_price' => 5000, 'stock_quantity' => 40]);

        $nouvelles = array_values(array_diff(self::REFERENCES, $anciennes));

        // `forceDelete` : `sku` est unique en base, corbeille comprise. Une
        // suppression douce ne rendrait pas la place et le seeder refuserait
        // de recreer — c'est le comportement que verifie
        // `test_une_reference_mise_a_la_corbeille_y_reste`.
        foreach (Part::whereIn('sku', $nouvelles)->get() as $piece) {
            $piece->fitments()->delete();
            $piece->oemNumbers()->detach();
            $piece->forceDelete();
        }

        $this->semer();

        foreach ($nouvelles as $sku) {
            $piece = Part::where('sku', $sku)->firstOrFail();

            $this->assertSame(5000.0, (float) $piece->selling_price,
                "{$sku} est revenue au prix du fichier au lieu de celui du rayon.");
            $this->assertSame(40, $piece->stock_quantity,
                "{$sku} est revenue au stock du fichier au lieu de celui du rayon.");
        }

        // Et une seule reference ajustee a la main ne doit pas entrainer tout
        // le rayon : c'est la valeur dominante qui fait foi, pas la premiere
        // lue.
        Part::where('sku', '90915-YZZD2')->update(['selling_price' => 99000]);

        $piece = Part::where('sku', '04152-YZZA4')->firstOrFail();
        $piece->fitments()->delete();
        $piece->oemNumbers()->detach();
        $piece->forceDelete();

        $this->semer();

        $this->assertSame(5000.0, (float) Part::where('sku', '04152-YZZA4')->firstOrFail()->selling_price,
            'Une seule reference ajustee a la main a entraine le prix des autres.');
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

        // La YZZA6 a quitte cette liste : le catalogue de correspondance lui a
        // donne quatre lignes confirmees.
        $vides = ['90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZN2'];

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

        // 16 lignes du premier versement, 58 du catalogue de correspondance —
        // dont 19 portees par les cinq modeles d'importation americaine — et
        // 20 du catalogue des neuf familles, reparties sur quatre des six
        // references nouvelles.
        $this->assertCount(94, $lignes,
            'Des compatibilites ont ete perdues : un slug ne se resout plus.');

        foreach ($lignes as $ligne) {
            $this->assertNotNull($ligne->vehicleModel, "Compatibilite orpheline : {$ligne->id}");
        }
    }

    /**
     * Un carter prend un filtre visse OU une cartouche.
     *
     * Le carter, c'est le code moteur — pas le modele, et pas la periode. La
     * garde comparait les seules periodes, ce qui revenait a supposer qu'une
     * generation n'offre qu'un moteur. C'est faux, et le catalogue de
     * correspondance en fait sa phrase d'ouverture :
     *
     *     Le filtre depend du code moteur, pas du nom du modele : une meme
     *     Camry peut prendre trois filtres differents selon l'annee et la
     *     motorisation.
     *
     * La Camry XV70 en est l'illustration : de 2018 a 2024 elle prend la
     * cartouche YZZA1 en 3.5 2GR-FKS et le filtre visse YZZN1 en 2.5 A25A, sur
     * les memes millesimes. Deux familles de filtre, deux carters differents,
     * aucune contradiction — et l'ancienne garde aurait refuse de publier la
     * ligne correcte.
     *
     * Ce qui reste interdit, et que la garde cherche vraiment, c'est un MEME
     * code moteur qui recevrait les deux familles sur une periode commune.
     */
    public function test_aucun_carter_ne_recoit_un_visse_et_une_cartouche_la_meme_annee(): void
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
                    if ($this->enConflit($v, $c)) {
                        $conflits[] = sprintf(
                            'Le modele %s recoit a la fois %s (visse) et %s (cartouche) en %s sur une periode commune.',
                            $v->vehicleModel?->slug ?? $modeleId,
                            $v->part->sku,
                            $c->part->sku,
                            $v->engine_code ?? 'code moteur absent',
                        );
                    }
                }
            }
        }

        $this->assertSame([], $conflits, implode(PHP_EOL, $conflits));
    }

    /**
     * La garde croise bien deux familles sur un meme modele.
     *
     * Sans ce constat, le test precedent pourrait passer parce que ses boucles
     * ne s'executent jamais. La Camry XV70 porte les deux familles : les
     * boucles tournent, et c'est le code moteur qui tranche.
     */
    public function test_la_camry_xv70_porte_bien_les_deux_familles(): void
    {
        $this->semer();

        $codes = PartFitment::with(['part', 'vehicleModel'])->get()
            ->filter(fn ($f) => $f->vehicleModel?->slug === 'camry-xv70')
            ->mapWithKeys(fn ($f) => [$f->part->sku.' / '.$f->engine_code => true])
            ->keys()
            ->sort()
            ->values()
            ->all();

        $this->assertSame([
            '04152-YZZA1 / 2GR-FKS',
            '90915-YZZN1 / A25A-FKS',
            '90915-YZZN1 / A25A-FXS',
        ], $codes, 'La XV70 est l\'exemple du document : cartouche en 2GR, visse en A25A.');
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

    /**
     * Et le code moteur est bien ce qui tranche.
     *
     * C'est la partie neuve de la garde, celle qui autorise la Camry XV70 sans
     * autoriser l'erreur qu'on cherche. Elle est eprouvee ici sur des objets
     * fabriques, pour qu'elle tienne encore quand les donnees changeront.
     */
    public function test_le_detecteur_de_conflit_juge_par_code_moteur(): void
    {
        $conflit = fn (string $m1, ?int $d1, ?int $f1, string $m2, ?int $d2, ?int $f2) => $this->enConflit(
            new PartFitment(['engine_code' => $m1, 'year_from' => $d1, 'year_to' => $f1]),
            new PartFitment(['engine_code' => $m2, 'year_from' => $d2, 'year_to' => $f2]),
        );

        // Le cas qu'on refuse : un meme carter, les deux familles, en meme temps.
        $this->assertTrue($conflit('2AR-FE', 2012, 2017, '2AR-FE', 2010, 2015));

        // Le cas de la Camry XV70 : deux moteurs distincts sur les memes
        // millesimes, deux filtres differents. Legitime.
        $this->assertFalse($conflit('A25A-FKS', 2018, null, '2GR-FKS', 2018, 2024));

        // Un meme carter dont les periodes sont disjointes : la transition
        // cartouche -> visse de la Corolla E180. Legitime.
        $this->assertFalse($conflit('2ZR-FE', 2017, 2019, '2ZR-FE', 2013, 2015));

        // Un code moteur manquant ne doit pas servir de laissez-passer : deux
        // lignes sans code sur une periode commune restent un conflit.
        $this->assertTrue($conflit('', 2018, 2020, '', 2019, 2021));
    }

    /**
     * Deux familles de filtre ne se disputent que le meme carter.
     *
     * Le code moteur est l'identite du carter : c'est la regle du document, et
     * c'est la seule lecture qui laisse passer la Camry XV70 tout en refusant
     * la contradiction physique.
     */
    private function enConflit(PartFitment $visse, PartFitment $cartouche): bool
    {
        if ($visse->engine_code !== $cartouche->engine_code) {
            return false;
        }

        return $this->seChevauchent($visse, $cartouche);
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

    /**
     * Rejoue a chaque deploiement : rien ne doit s'empiler ni se perdre.
     *
     * Les compteurs ne suffisent pas — ils resteraient identiques si une ligne
     * disparaissait pendant qu'une autre se dupliquait. On compare donc le
     * contenu : reference, modele, code moteur, bornes d'annees et provenance.
     */
    public function test_rejouer_le_seeder_ne_change_rien(): void
    {
        $this->semer();

        $empreinte = fn () => PartFitment::with(['part', 'vehicleModel'])->get()
            ->map(fn ($f) => implode('|', [
                $f->part->sku,
                $f->vehicleModel?->slug,
                $f->engine_code,
                $f->year_from ?? '-',
                $f->year_to ?? '-',
                $f->notes,
            ]))
            ->sort()
            ->values()
            ->all();

        $pieces  = Part::count();
        $numeros = OemNumber::count();
        $avant   = $empreinte();

        $this->semer();

        $this->assertSame($pieces, Part::count());
        $this->assertSame($numeros, OemNumber::count());
        $this->assertSame($avant, $empreinte(),
            'La republication a modifie, perdu ou duplique des compatibilites.');
    }

    /**
     * Chaque compatibilite dit d'ou elle vient.
     *
     * Une ligne sans provenance est invérifiable des que son auteur a oublie le
     * detail. Le document, le tableau et le statut doivent donc rester
     * attaches a la ligne, en base — pas seulement dans le fichier du seeder.
     */
    public function test_chaque_compatibilite_porte_sa_provenance(): void
    {
        $this->semer();

        foreach (PartFitment::with(['part', 'vehicleModel'])->get() as $ligne) {
            $this->assertNotEmpty($ligne->notes, sprintf(
                '%s / %s est publiee sans provenance.',
                $ligne->part->sku,
                $ligne->vehicleModel?->slug,
            ));
        }
    }

    /**
     * Les trois references du catalogue de correspondance portent bien leur
     * compte.
     *
     * Reparti par reference, pour qu'une perte se localise au lieu de se
     * diluer dans un total.
     */
    public function test_le_compte_par_reference_est_celui_du_versement(): void
    {
        $this->semer();

        $attendus = [
            '04152-YZZA1' => 33,
            '04152-YZZA6' => 4,
            '90915-YZZN1' => 25,
            // Catalogue des neuf familles. Les comptes sont bas et c'est le
            // sujet : sur les six references nouvelles, deux n'ont aucune
            // ligne au statut « Confirme » dans le document, et les quatre
            // autres n'atteignent qu'une poignee de modeles du referentiel.
            '90915-YZZF1' => 1,
            '90915-30002' => 0,
            '04152-YZZA3' => 8,
            '04152-YZZA5' => 9,
            '04152-YZZA4' => 2,
            '04152-51010' => 0,
        ];

        foreach ($attendus as $sku => $compte) {
            $this->assertCount(
                $compte,
                Part::where('sku', $sku)->firstOrFail()->fitments,
                "Le compte de compatibilites de la {$sku} a change.",
            );
        }
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

        $this->assertSame(['Lexus', 'Toyota'], $marques,
            'Le catalogue de correspondance couvre les deux marques, et rien d\'autre : les lignes Scion, Pontiac et Lotus du document n\'ont aucune marque au referentiel.');
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
     * Une ligne « Non compatible » n'est pas une compatibilite : c'est son
     * inverse.
     *
     * Le catalogue de correspondance porte, dans le tableau de chaque
     * reference, des lignes qui disent que le vehicule prend une AUTRE
     * reference — nommee dans la colonne Filtre. Lues de travers, elles
     * fabriquent exactement la compatibilite que le document refuse : une
     * cartouche sur un carter a filtre visse, ou le filtre d'un 4 cylindres
     * sur un V6.
     *
     * Chaque paire ci-dessous est une ligne « Non compatible » du document dont
     * le modele existe au referentiel — donc une erreur qu'un chargement
     * distrait aurait pu commettre. Le couple (modele, code moteur) est la
     * forme exacte de l'interdit : la Camry XV70 a le droit de porter la YZZN1,
     * mais pas en 2GR-FKS.
     */
    public function test_aucune_ligne_non_compatible_n_est_devenue_une_compatibilite(): void
    {
        $this->semer();

        $interdits = [
            // Le tableau YZZA1 renvoie ces vehicules vers la YZZF1, la YZZA4
            // ou la YZZN1.
            '04152-YZZA1' => [
                ['camry-xv50', '2AZ-FE'], ['rav4-xa40', '2AZ-FE'],
                ['camry-xv70', 'A25A-FKS'], ['camry-xv70', 'A25A-FXS'],
                ['rav4-xa50', 'A25A-FKS'], ['rav4-xa50', 'A25A-FXS'],
                ['es-xv70', 'A25A-FKS'], ['es-xv70', 'A25A-FXS'],
                ['nx-az20', 'A25A-FKS'], ['nx-az20', 'A25A-FXS'],
                ['rx-al30', 'A25A-FXS'], ['land-cruiser-j200', '3UR-FE'],
                // Les cinq modeles d'importation americaine portent chacun des
                // lignes confirmees ET des lignes « Non compatible » dans le
                // meme tableau. C'est le piege de ce versement : les memes
                // Highlander, Sienna, Venza et Avalon prennent la cartouche en
                // 2GR ou 1AR, et le filtre visse en A25A ou T24A.
                ['highlander', '3MZ-FE'], ['highlander', 'A25A-FXS'],
                ['highlander', 'T24A-FTS'], ['sienna', 'A25A-FXS'],
                ['venza', 'A25A-FXS'], ['avalon', 'A25A-FXS'],
            ],
            // Le tableau YZZN1 renvoie ces vehicules vers la YZZA1 ou la
            // YZZA6. Les V6 s'y ajoutent : « aucune application V6 ou V8 n'est
            // repertoriee pour la YZZN1 dans le catalogue 2023 ».
            '90915-YZZN1' => [
                ['camry-xv50', '2AR-FE'], ['camry-xv50', '2AR-FXE'],
                ['camry-xv50', '2GR-FE'], ['camry-xv70', '2GR-FKS'],
                ['rav4-xa40', '2AR-FE'], ['rav4-xa40', '2AR-FXE'],
                ['es-xv60', '2AR-FXE'], ['rx-al20', '2GR-FE'],
                ['prius-xw30', '2ZR-FXE'], ['corolla-e140', '2ZR-FE'],
                ['ct-zwa10', '2ZR-FXE'],
                // Le revers du piege : sur ces modeles, les V6 et le 2.7 1AR
                // relevent de la cartouche. Le document est formel, « aucune
                // application V6 ou V8 n'est repertoriee pour la YZZN1 ».
                ['highlander', '2GR-FE'], ['highlander', '2GR-FKS'],
                ['highlander', '1AR-FE'], ['sienna', '2GR-FE'],
                ['venza', '1AR-FE'], ['avalon', '2AR-FXE'],
                ['tacoma', '2GR-FKS'], ['tacoma', '2TR-FE'],
            ],
            // Le tableau YZZA6 renvoie ces vehicules vers la YZZN1 ou la
            // YZZF1.
            '04152-YZZA6' => [
                ['corolla-e210', '2ZR-FAE'], ['corolla-e210', 'M20A-FKS'],
                ['corolla-e140', '2AZ-FE'], ['prius-xw50', '2ZR-FXE'],
            ],
        ];

        foreach ($interdits as $sku => $paires) {
            $piece = Part::where('sku', $sku)->with('fitments.vehicleModel')->firstOrFail();

            $portees = $piece->fitments
                ->map(fn ($f) => $f->vehicleModel?->slug.' / '.$f->engine_code)
                ->all();

            foreach ($paires as [$slug, $moteur]) {
                // Sans ce controle, un slug mal orthographie rendrait
                // l'interdiction vide : elle passerait en ne verifiant rien.
                $this->assertNotNull(
                    VehicleModel::where('slug', $slug)->first(),
                    "Le modele {$slug} n'existe plus : cette interdiction ne verifie plus rien.",
                );

                $this->assertNotContains($slug.' / '.$moteur, $portees, sprintf(
                    'Le document donne %s en %s comme « Non compatible » avec la %s : '
                    .'cette ligne prescrit une autre reference, elle ne doit pas devenir une compatibilite.',
                    $slug,
                    $moteur,
                    $sku,
                ));
            }
        }
    }

    /**
     * Les cinq modeles d'importation americaine, et ce qu'ils ont rapporte.
     *
     * Le proprietaire a leve l'absence de ces cinq modeles sur une estimation
     * du nombre de compatibilites qu'ils debloqueraient. Ce test fixe le compte
     * reel, modele par modele, pour que la decision reste verifiable.
     */
    public function test_les_modeles_d_importation_americaine_portent_leurs_lignes(): void
    {
        $this->semer();

        $attendus = [
            'highlander' => ['04152-YZZA1' => 5, '90915-YZZN1' => 2],
            'sienna'     => ['04152-YZZA1' => 3, '90915-YZZN1' => 1],
            'venza'      => ['04152-YZZA1' => 2, '90915-YZZN1' => 1],
            'avalon'     => ['04152-YZZA1' => 3, '90915-YZZN1' => 1],
            'tacoma'     => ['04152-YZZA1' => 1],
        ];

        $total = 0;

        foreach ($attendus as $slug => $parReference) {
            $modele = VehicleModel::where('slug', $slug)->first();

            $this->assertNotNull($modele, "Le modele {$slug} n'a pas ete cree.");
            $this->assertTrue((bool) $modele->is_active, "Le modele {$slug} est inactif.");
            $this->assertSame('Toyota', $modele->brand->name, $slug);

            $lignes = PartFitment::with('part')
                ->where('vehicle_model_id', $modele->id)
                ->get()
                ->groupBy(fn ($f) => $f->part->sku)
                ->map->count()
                ->all();

            $this->assertSame($parReference, $lignes, "Le compte de {$slug} a change.");

            $total += array_sum($parReference);
        }

        $this->assertSame(19, $total,
            'Les cinq modeles crees devaient debloquer dix-neuf compatibilites confirmees.');
    }

    /**
     * Aucun des cinq n'a ete cree en double.
     *
     * Le referentiel porte deja « Land Cruiser » en trois generations et
     * « Corolla » en trois : un doublon y passerait inapercu, et serait pire
     * que l'absence — deux lignes pour un meme vehicule, dont une seule porte
     * les compatibilites.
     */
    public function test_les_modeles_d_importation_americaine_ne_sont_pas_doubles(): void
    {
        $this->semer();

        foreach (['Highlander', 'Sienna', 'Venza', 'Avalon', 'Tacoma'] as $nom) {
            $this->assertCount(
                1,
                VehicleModel::where('name', $nom)->get(),
                "Le modele {$nom} existe en plusieurs exemplaires.",
            );
        }

        // Et les noms de marche de ces memes vehicules ne doivent pas avoir
        // cree une seconde entree : le Highlander se vend « Kluger » hors
        // Amerique du Nord, et le document le signale lui-meme.
        foreach (['Kluger', 'Harrier', 'Previa', 'Estima'] as $autreNom) {
            $this->assertCount(
                0,
                VehicleModel::where('name', $autreNom)->get(),
                "Le modele {$autreNom} doublerait un des cinq modeles crees.",
            );
        }
    }

    /**
     * Un cas lu de bout en bout, par l'API publique.
     *
     * La Camry 2.5 2AR-FE de 2015, c'est-a-dire la generation XV50 : le
     * document la donne « Confirme » sur la cartouche YZZA1. Le reste du
     * fichier verifie la base ; celui-ci verifie que le client la voit.
     */
    public function test_l_api_rend_la_cartouche_pour_la_camry_2_5_2ar_fe(): void
    {
        $this->semer();

        $xv50 = VehicleModel::where('slug', 'camry-xv50')->firstOrFail();

        $reponse = $this->getJson('/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $xv50->id,
            'year'             => 2015,
            'engine_code'      => '2AR-FE',
        ]))->assertOk();

        $references = collect($reponse->json('data'))->pluck('sku')->all();

        $this->assertContains('04152-YZZA1', $references,
            'Un client avec une Camry XV50 de 2015 ne trouve pas sa cartouche.');

        // Et l'inverse, qui compte autant : la XV50 ne prend aucun filtre
        // visse, le document l'y donne « Non compatible ».
        $this->assertNotContains('90915-YZZN1', $references,
            'La Camry XV50 se voit proposer un filtre visse, que le document lui refuse.');
    }

    /**
     * Le code moteur tranche bien, la ou il est branche.
     *
     * Le service sait filtrer sur le code moteur ; c'est la cle du document, et
     * la Camry XV70 est le cas ou elle est indispensable — meme modele, memes
     * millesimes, deux filtres. Ce test fixe ce comportement.
     *
     * La route publique l'expose DESORMAIS — voir le test suivant, qui refait
     * la meme mesure par HTTP. Celui-ci garde le niveau service : si les deux
     * divergeaient un jour, c'est le branchement qu'il faudrait regarder, pas
     * le filtrage.
     */
    public function test_le_code_moteur_separe_les_deux_filtres_de_la_camry_xv70(): void
    {
        $this->semer();

        $xv70 = VehicleModel::where('slug', 'camry-xv70')->firstOrFail();
        $service = app(\App\Services\CompatibilityService::class);

        $pour = fn (string $moteur) => $service
            ->partsForCriteria($xv70->id, 2019, null, null, null, $moteur)
            ->pluck('sku')
            ->all();

        $this->assertContains('90915-YZZN1', $pour('A25A-FKS'));
        $this->assertNotContains('04152-YZZA1', $pour('A25A-FKS'),
            'Le 2.5 A25A se voit proposer la cartouche du V6.');

        $this->assertContains('04152-YZZA1', $pour('2GR-FKS'));
        $this->assertNotContains('90915-YZZN1', $pour('2GR-FKS'),
            'Le 3.5 2GR-FKS se voit proposer le filtre visse du 4 cylindres.');
    }

    /**
     * La ROUTE tranche aussi, et pas seulement le service.
     *
     * Le service savait filtrer par code moteur depuis le debut ; le
     * controleur ne lui passait rien, et la requete validee n'acceptait meme
     * pas le parametre. Mesure avant correction sur une Camry XV70 de 2019 :
     * la route rendait LES DEUX filtres, et c'etait au vendeur de lire le code
     * moteur sous le capot — alors que le document fourni par le proprietaire
     * ouvre precisement la-dessus.
     *
     * Les trois cas comptent autant l'un que l'autre :
     *   — avec le code, un seul filtre ;
     *   — en minuscules, le meme resultat, parce que la comparaison est une
     *     egalite exacte en base et que « 2ar-fe » ne rendrait sinon que les
     *     lignes sans code moteur ;
     *   — sans le code, aucune confirmation ; avec include_candidates=1,
     *     les deux restent proposées avec leur état à vérifier. Les anciens
     *     clients qui badgent les IDs retournés ne doivent pas les confirmer.
     */
    public function test_la_route_publique_filtre_par_code_moteur(): void
    {
        $this->semer();

        $xv70 = VehicleModel::where('slug', 'camry-xv70')->firstOrFail();

        $skus = function (array $criteres) use ($xv70) {
            $reponse = $this->getJson('/api/catalog/compatible-parts?'.http_build_query(
                ['vehicle_model_id' => $xv70->id, 'year' => 2019] + $criteres,
            ))->assertOk();

            return collect($reponse->json('data'))->pluck('sku')->all();
        };

        $v6 = $skus(['engine_code' => '2GR-FKS']);
        $this->assertContains('04152-YZZA1', $v6);
        $this->assertNotContains('90915-YZZN1', $v6,
            'La route propose au V6 le filtre visse du 4 cylindres.');

        $quatre = $skus(['engine_code' => 'A25A-FKS']);
        $this->assertContains('90915-YZZN1', $quatre);
        $this->assertNotContains('04152-YZZA1', $quatre,
            'La route propose au 2.5 A25A la cartouche du V6.');

        $this->assertSame($v6, $skus(['engine_code' => '  2gr-fks  ']),
            'Un code saisi en minuscules ou entoure d\'espaces ne rend pas le '
            .'meme resultat : la normalisation de la requete a saute, et le '
            .'vendeur conclura qu\'il n\'y a aucune piece.');

        $sansCode = $skus(['include_candidates' => 1]);
        $this->assertContains('04152-YZZA1', $sansCode);
        $this->assertContains('90915-YZZN1', $sansCode,
            'La recherche explicite de candidats doit garder les deux moteurs possibles.');
        $this->assertSame([], $skus([]),
            'Une liste confirmée ne doit pas donner aux anciens clients deux moteurs comme certains.');
    }

    /**
     * Un code moteur inconnu ne rend pas 422, il rend ce qui vaut pour tout le
     * modele.
     *
     * `engine_code` n'est pas une clef etrangere : c'est une chaine libre sur
     * `part_fitments`. Le refuser par `exists:` aurait fait echouer la
     * recherche d'un vendeur dont le moteur n'est pas encore au catalogue —
     * alors que les compatibilites SANS code moteur, celles qui valent pour
     * tout le modele, restent la bonne reponse a lui donner.
     */
    public function test_un_code_moteur_inconnu_ne_fait_pas_echouer_la_recherche(): void
    {
        $this->semer();

        $xv70 = VehicleModel::where('slug', 'camry-xv70')->firstOrFail();

        $this->getJson('/api/catalog/compatible-parts?'.http_build_query([
            'vehicle_model_id' => $xv70->id,
            'year'             => 2019,
            'engine_code'      => 'MOTEUR-QUI-N-EXISTE-PAS',
        ]))->assertOk();
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

        // La YZZA6 n'attend plus : le catalogue de correspondance du 18/10/2026
        // lui donne quatre generations entierement du cote cartouche.
        //
        // La 90915-30002 et la 04152-51010 rejoignent la liste, pour une
        // raison differente des cinq autres : elles n'attendent pas une
        // verification, le catalogue des neuf familles ne leur donne AUCUNE
        // ligne au statut « Confirme ». La famille 5 n'a que du « Confirme
        // Boshoku », du « Confirme moteur », du « Documente » et du
        // « Probable » ; la 51010 n'a que du « Documente » et du
        // « Probable », index par code moteur compris.
        $enAttente = ['90915-YZZF2', '90915-YZZE1', '90915-YZZD1', '90915-YZZN2', '90915-10001',
            '90915-30002', '04152-51010'];

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

    /**
     * Les deux cartouches longitudinales ne se confondent pas.
     *
     * C'est le seul endroit du catalogue ou deux references se disputent le
     * MEME modele et le MEME code moteur. Le document les separe par le code
     * chassis — GSE20 contre GSE25 pour une IS 250 — que le referentiel ne
     * porte pas ; la motricite est la seule colonne qui sache le dire, et elle
     * dit la meme chose : la YZZA3 est la propulsion, la YZZA5 les quatre
     * roues motrices.
     *
     * Sans elle, une IS 250 de 2010 rendrait deux cartouches dont une seule
     * convient, et le document previent que les joints du kit different.
     */
    public function test_la_motricite_separe_les_deux_cartouches_longitudinales(): void
    {
        $this->semer();

        $motriciteDe = function (string $sku, string $slug, string $moteur): ?string {
            $piece = Part::where('sku', $sku)->with('fitments.vehicleModel')->firstOrFail();

            $ligne = $piece->fitments->first(
                fn ($f) => $f->vehicleModel?->slug === $slug && $f->engine_code === $moteur
            );

            $this->assertNotNull($ligne, "{$sku} ne porte pas {$slug} en {$moteur}.");

            return \App\Models\Drivetrain::find($ligne->drivetrain_id)?->code;
        };

        $this->assertSame('RWD', $motriciteDe('04152-YZZA3', 'is-xe20', '4GR-FSE'));
        $this->assertSame('AWD', $motriciteDe('04152-YZZA5', 'is-xe20', '4GR-FSE'));
        $this->assertSame('RWD', $motriciteDe('04152-YZZA3', 'gs-s190', '2GR-FSE'));
        $this->assertSame('AWD', $motriciteDe('04152-YZZA5', 'gs-s190', '2GR-FSE'));

        // Les trois lignes V8 de la YZZA5 n'en portent pas : ce n'est pas la
        // transmission qui les range dans cette famille, c'est le moteur — et
        // la GS 460 du document est une propulsion. Une motricite posee la
        // ferait disparaitre d'une recherche faite en propulsion.
        $this->assertNull($motriciteDe('04152-YZZA5', 'ls-xf40', '1UR-FSE'));
        $this->assertNull($motriciteDe('04152-YZZA5', 'gs-s190', '1UR-FSE'));

        // Et la preuve par la recherche : une IS 250 propulsion de 2010 ne
        // doit voir qu'une cartouche.
        $xe20 = VehicleModel::where('slug', 'is-xe20')->firstOrFail();
        $rwd  = \App\Models\Drivetrain::where('code', 'RWD')->firstOrFail();

        $trouvees = app(\App\Services\CompatibilityService::class)
            ->partsForCriteria($xe20->id, 2010, null, null, $rwd->id, '4GR-FSE')
            ->pluck('sku')
            ->all();

        $this->assertContains('04152-YZZA3', $trouvees);
        $this->assertNotContains('04152-YZZA5', $trouvees,
            'Une IS 250 propulsion se voit proposer la cartouche des quatre roues motrices.');

        // LE BORD, verrouille ici parce qu'il est contre-intuitif : le filtre
        // est une EGALITE EXACTE, `drivetrain_id IS NULL OR = X`. Le referentiel
        // porte quatre motricites et ces lignes n'en citent que deux, donc un
        // vehicule saisi en 4WD ne voit plus AUCUNE cartouche, la ou il en
        // voyait deux sans motricite. C'est le seul endroit du catalogue ou
        // renseigner un champ fait disparaitre la piece.
        //
        // Cette assertion n'est pas la pour celebrer le vide : elle est la pour
        // que le jour ou quelqu'un elargit le filtre a « 4WD vaut AWD », le test
        // en parle plutot que de laisser passer. Elargir serait un choix
        // defendable ; poser les lignes en 4WD dans les donnees ne le serait
        // pas, car aucune de ces Lexus n'a de 4x4 debrayable.
        $quatreRoues = \App\Models\Drivetrain::where('code', '4WD')->first();

        if ($quatreRoues !== null) {
            $en4wd = app(\App\Services\CompatibilityService::class)
                ->partsForCriteria($xe20->id, 2010, null, null, $quatreRoues->id, '4GR-FSE')
                ->pluck('sku')
                ->all();

            $this->assertSame([], $en4wd,
                'Le filtre de motricite s\'est elargi : une IS XE20 saisie en 4x4 debrayable '
                .'voit maintenant des cartouches. Si c\'est voulu, dire laquelle est juste.');
        }
    }

    /**
     * Un numero de marche n'est pas un numero perime.
     *
     * Le 90915-YZZE2 est le numero asiatique du 90915-YZZF1 : il est VENDU
     * aujourd'hui, au meme titre. L'ecrire en `is_superseded` afficherait au
     * client « cette reference a ete remplacee », ce que Toyota n'a jamais
     * prononce — et le document ouvre justement sur « un filtre physique,
     * plusieurs numeros commerciaux selon le marche ».
     *
     * Les vrais anciens numeros, eux, doivent rester perimes et pointer sur
     * le numero actuel.
     */
    public function test_les_numeros_de_marche_ne_sont_pas_marques_perimes(): void
    {
        $this->semer();

        foreach (['90915-YZZE2', '04152-31080', '04152-38010', '04152-38020'] as $numero) {
            $oem = OemNumber::where('number', $numero)->first();

            $this->assertNotNull($oem, "Le numero de marche {$numero} n'a pas ete rattache.");
            $this->assertFalse((bool) $oem->is_superseded,
                "{$numero} est annonce comme remplace alors qu'il est toujours au catalogue.");
        }

        $perime = OemNumber::where('number', '90915-03006')->first();

        $this->assertNotNull($perime);
        $this->assertTrue((bool) $perime->is_superseded,
            'Le 90915-03006 est bien un ancien numero : il doit mener au 90915-30002.');
        $this->assertSame('90915-30002', OemNumber::find($perime->superseded_by_id)?->number);

        // CE QUI PRECEDE NE COUVRAIT QUE LA CREATION. Le `is_superseded => false`
        // d'un `firstOrCreate` ne s'applique pas a une ligne qui existe deja :
        // un numero marque perime par un AUTRE chemin — `syncOemNumbers`,
        // `OemNumberController`, `BackfillFitments` — y restait pour toujours,
        // alors que ce seeder est declare seul auteur de ces liens.
        //
        // La mise en scene vaut mieux qu'une explication : la 04152-38010 est un
        // numero d'origine TOUJOURS VENDU de la cartouche longitudinale YZZA5. Le
        // faire pointer sur la YZZA1 annonce au client qu'une cartouche
        // longitudinale a ete remplacee par une cartouche TRANSVERSALE — la
        // confusion de familles que le document interdit nommement.
        $toujoursVendu = OemNumber::where('number', '04152-38010')->firstOrFail();
        $transversale  = OemNumber::where('number', '04152-YZZA1')->firstOrFail();

        $toujoursVendu->update([
            'is_superseded'    => true,
            'superseded_by_id' => $transversale->id,
        ]);

        $this->semer();

        $toujoursVendu->refresh();

        $this->assertFalse((bool) $toujoursVendu->is_superseded,
            'Le seeder ne sait pas defaire une supersession fausse : la 04152-38010 reste '
            .'annoncee comme remplacee alors qu\'elle est toujours au catalogue.');
        $this->assertNull($toujoursVendu->superseded_by_id,
            'La 04152-38010 pointe encore sur une cartouche transversale.');
    }

    /**
     * Un cas du parc local, lu de bout en bout par l'API, sur une famille
     * nouvelle.
     *
     * Le Land Cruiser 200 est le seul vehicule du referentiel que la famille 9
     * atteigne, et la ligne est triplement etablie : « Confirme » au tableau
     * de la famille, « Confirme » a l'index par code moteur, et designee
     * nommement par la ligne « Non compatible » du tableau YZZA1 du premier
     * document — celle qui prescrit cette reference-ci.
     *
     * Le revers compte autant : le meme J200 porte deja la 90915-YZZD4 en
     * 1GR-FE depuis le premier versement. Un V8 3UR-FE ne doit pas se voir
     * proposer le filtre visse du V6, et reciproquement. C'est le code moteur
     * qui tranche, et c'est tout l'objet du document.
     */
    public function test_l_api_rend_la_cartouche_v8_pour_le_land_cruiser_200(): void
    {
        $this->semer();

        $j200 = VehicleModel::where('slug', 'land-cruiser-j200')->firstOrFail();

        $skus = function (array $criteres) use ($j200) {
            $reponse = $this->getJson('/api/catalog/compatible-parts?'.http_build_query(
                ['vehicle_model_id' => $j200->id, 'year' => 2012] + $criteres,
            ))->assertOk();

            return collect($reponse->json('data'))->pluck('sku')->all();
        };

        $v8 = $skus(['engine_code' => '3UR-FE']);

        $this->assertContains('04152-YZZA4', $v8,
            'Un client avec un Land Cruiser 200 V8 5.7 ne trouve pas sa cartouche.');
        $this->assertNotContains('90915-YZZD4', $v8,
            'Le V8 3UR-FE se voit proposer le filtre visse du V6 4.0.');

        $v6 = $skus(['engine_code' => '1GR-FE']);

        $this->assertContains('90915-YZZD4', $v6);
        $this->assertNotContains('04152-YZZA4', $v6,
            'Le V6 1GR-FE se voit proposer la cartouche du V8.');
    }

    /**
     * Une reference retiree de la vente ne revient pas, et n'emporte pas les
     * autres.
     *
     * `Part` est en suppression douce, et `sku` est unique EN BASE, corbeille
     * comprise. Le seeder lisait ses pieces sans `withTrashed()` : une
     * reference mise a la corbeille depuis l'application lui semblait
     * absente, il tentait de la recreer, et la base refusait sur l'unicite.
     * L'exception sortait de la boucle — une seule reference supprimee
     * privait donc les quinze autres de leur mise a jour, et la migration de
     * deploiement n'en disait qu'un avertissement.
     *
     * Deux choses sont verrouillees ici : le passage se termine quand meme,
     * et la reference retiree n'est pas ressuscitee.
     */
    public function test_une_reference_mise_a_la_corbeille_y_reste(): void
    {
        $this->semer();

        Part::where('sku', '04152-YZZA4')->firstOrFail()->delete();

        // DEUX temoins, et LEQUEL compte n'est pas indifferent. L'exception
        // sortait de la boucle : elle ne privait de mise a jour que les
        // references situees APRES celle mise a la corbeille. Dans l'ordre de
        // `self::FILTRES`, la 04152-YZZA4 est la 15e sur 16 — la 04152-YZZA3
        // (13e) la PRECEDE et serait donc repassee meme avec le bogue present,
        // tandis que la 04152-51010 (16e) est la seule qui le suive.
        //
        // Le temoin d'avant reste quand meme : il distingue « la boucle s'est
        // arretee a la corbeille » de « la boucle n'a jamais tourne ».
        Part::where('sku', '04152-YZZA3')->firstOrFail()->update(['name' => 'Libelle modifie a la main']);
        Part::where('sku', '04152-51010')->firstOrFail()->update(['name' => 'Libelle modifie a la main']);

        $this->artisan('db:seed', ['--class' => FiltresHuileBoutiqueSeeder::class])
            ->expectsOutputToContain('reference a la corbeille')
            ->assertSuccessful();

        $this->assertNull(Part::where('sku', '04152-YZZA4')->first(),
            'Une reference retiree de la vente est revenue au prochain deploiement.');

        $this->assertNotSame(
            'Libelle modifie a la main',
            Part::where('sku', '04152-YZZA3')->firstOrFail()->name,
            'La boucle n\'a pas tourne du tout : meme les references posees AVANT la corbeille '
            .'ont garde leur libelle modifie.',
        );

        // L'assertion qui porte vraiment le nom du test.
        $this->assertNotSame(
            'Libelle modifie a la main',
            Part::where('sku', '04152-51010')->firstOrFail()->name,
            'La reference a la corbeille a interrompu le passage : la 04152-51010, qui la suit '
            .'dans self::FILTRES, n\'a pas ete mise a jour.',
        );
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
