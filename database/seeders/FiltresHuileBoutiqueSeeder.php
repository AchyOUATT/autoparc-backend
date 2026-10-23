<?php

namespace Database\Seeders;

use App\Enums\FitmentSource;
use App\Models\Drivetrain;
use App\Models\Manufacturer;
use App\Models\OemNumber;
use App\Models\Part;
use App\Models\PartCategory;
use App\Models\VehicleModel;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Le catalogue de filtres a huile de la boutique.
 *
 * Premieres vraies pieces du catalogue, apres le vidage des donnees de
 * demonstration. Huit references Toyota, vendues en equivalent adaptable.
 *
 * Sur les compatibilites, ce seeder est beaucoup plus avare que le document
 * d'ou il vient, et c'est voulu.
 *
 * Le premier document fournisseur donnait 98 lignes. La moitie ne trouve aucun
 * modele au referentiel, qui commence vers 2002 quand le document couvre des
 * vehicules des annees 1980. Sur le reste, le commercant a audite son propre
 * document page par page et conclu que deux pages etaient a refaire, deux
 * pleines d'erreurs et trois a remplacer : intervalles de trente ans,
 * motorisations melangees, et surtout des generations a filtre visse confondues
 * avec des generations a cartouche — ce qu'aucun carter n'accepte.
 *
 * N'ont ete retenues que les dix lignes qu'il a pu confirmer sur des sources
 * constructeur. Les autres attendent. Un document corrige, qui cite ses
 * sources, pose d'ailleurs la regle que ces lignes violaient :
 *
 *     Ne jamais confirmer un filtre sur la seule base du modele et de la
 *     cylindree. Verifier au minimum annee + code moteur.
 *
 * D'ou le quatrieme element de chaque ligne : le code moteur. Une
 * compatibilite qui ne le porte pas n'a pas sa place ici.
 *
 * Deuxieme versement, octobre 2026 : le « Catalogue de correspondance --
 * filtres a huile Toyota & Lexus » du 18/10/2026, trois parties par reference,
 * chaque ligne datee et statuee. Il pose la meme regle, en plus net :
 *
 *     Le filtre depend du code moteur, pas du nom du modele : une meme Camry
 *     peut prendre trois filtres differents selon l'annee et la motorisation.
 *
 * Quatre statuts sur cinq ne deviennent donc PAS des compatibilites :
 * « Conditionnel » exige une lecture du VIN ou du montage, « Supersession »
 * n'est pas une application lue, et « Non compatible » est l'inverse d'une
 * compatibilite — ces lignes disent que le vehicule prend une AUTRE
 * reference. Elles ont quand meme servi : quand la reference qu'elles
 * prescrivent est l'une des trois d'ici, elles corroborent la ligne positive
 * tiree du tableau de cette reference-la. Chaque note le dit quand c'est le
 * cas.
 *
 * Deux lignes du nouveau document contredisent l'audit du commercant (la NX
 * AZ10 en 8AR-FTS et en 2AR-FXE, que l'audit avait ecartees de la YZZA1). Elles
 * ne sont pas posees : arbitrer entre son audit et son fournisseur lui
 * appartient, pas au chargement de donnees.
 *
 * Troisieme versement, 19/10/2026 : le « Catalogue de compatibilite -- filtres
 * a huile Toyota & Lexus » du 18/10/2026, qui range tout le programme en NEUF
 * familles au lieu de proceder reference par reference, et ouvre sur la phrase
 * qui commande sa lecture :
 *
 *     Un filtre physique, plusieurs numeros commerciaux selon le marche.
 *
 * D'ou la regle suivie ici : une piece par REFERENCE DE VENTE — la colonne que
 * l'index des anciens numeros nomme ainsi — et non une par numero. Les numeros
 * qui ne different que par le marche deviennent des `equivalents`, les numeros
 * perimes des `remplace`. Six references manquaient, elles sont posees.
 *
 * Les familles 1, 3, 4, 6 et 7 ont deja des references au rayon et n'en
 * recoivent aucune de plus — mais PAS une chacune, contrairement a ce que
 * disait d'abord cet en-tete : la famille 1 en porte CINQ (90915-YZZN1,
 * YZZN2, YZZE1, et 90915-YZZF2 + 90915-10001 qui sont des ANCIENS numeros de
 * cette famille, en rayon comme articles a part entiere avec zero
 * compatibilite), la famille 3 en porte DEUX (YZZD1 essence, YZZD2 diesel).
 * Les fondre en une reference par famille supprimerait des articles en vente
 * et releve du proprietaire, pas du chargement de donnees.
 *
 * Et la regle « une piece par reference de vente » n'est pas « une piece par
 * famille » : les familles 7, 8 et 9 portent DEUX references de vente chacune,
 * separees par le MOTEUR et non par le marche, et le document interdit de les
 * confondre — « Toyota les distingue, stocker les deux » (famille 7),
 * « proches mais pas identiques, joints du kit » (famille 8), « Toyota ne
 * donne pas la YZZA4 comme remplacante de la 51010 » (famille 9). Les fondre
 * serait l'erreur inverse de celle qu'on cherche a eviter : non pas un article
 * en double, mais un mauvais kit vendu.
 *
 * Ce document recouvre le precedent sans le contredire sur les lignes deja
 * posees, A DEUX EXCEPTIONS, qui sont rapportees au proprietaire et laissees
 * telles quelles : le Prado 120 en 5L-E, que la 90915-YZZD4 porte depuis le
 * premier versement et que celui-ci range en famille 5 (90915-30002), et les
 * 1GR-FE de 2010 et apres, que la YZZD4 porte jusqu'en 2013 et que celui-ci
 * renvoie en famille 8 a partir de 2010. Trancher entre deux documents du meme
 * jour n'appartient pas au chargement de donnees.
 *
 * Les tableaux Lexus de ce document sortent decales a l'extraction `-layout` :
 * le tableau de la famille 6 y donne « RX 350 » en 2.0 turbo, un moteur que ce
 * modele n'a jamais eu. Toutes les lignes Lexus d'ici sont reconstituees sur
 * l'extraction brute, qui rend chaque colonne en liste continue, et validees
 * par trois controles : comptes de lignes identiques colonne par colonne, code
 * chassis coherent avec le modele, et famille coherente avec l'index par code
 * moteur.
 *
 * Les modeles sont designes par leur SLUG, jamais par leur identifiant. Les
 * identifiants different d'une base a l'autre : un seeder qui les figerait
 * serait juste en production et faux partout ailleurs, tests compris. Un modele
 * introuvable est signale, pas devine.
 *
 * Les lignes ecrites portent l'origine `declared` : elles viennent du
 * commercant, pas du remplissage automatique du catalogue, et
 * `catalog:backfill-fitments` ne doit jamais les toucher.
 *
 * Le seeder est rejouable : il retrouve ses pieces par SKU et remplace leurs
 * compatibilites plutot que d'en empiler.
 */
class FiltresHuileBoutiqueSeeder extends Seeder
{
    /**
     * Ce que le commercant facturait au premier versement, en francs CFA.
     *
     * N'est plus pose tel quel : `run()` lit le prix que porte deja le rayon
     * et ne retombe sur cette constante que sur une base ou aucune de ces
     * references n'existe encore. Consigne du proprietaire au troisieme
     * versement, textuellement : « conduis les memes prix et quantite je
     * reajusterai apres » — s'il a bouge son prix hier depuis l'application,
     * les references d'aujourd'hui doivent le suivre et non rouvrir une ligne
     * a l'ancien tarif.
     */
    private const PRIX = 3000;

    /**
     * Le stock pose a la creation d une reference.
     *
     * Meme regle que le prix : `run()` lit d'abord le stock dominant du rayon.
     * Cette valeur ne sert donc qu aux references nouvelles sur une base vide
     * — celles deja en base gardent le leur, voir run().
     */
    private const STOCK_INITIAL = 15;

    /**
     * L'origine declaree par le commercant.
     *
     * « Chine » designe une provenance plutot qu'un equipementier, et se
     * retrouve donc a cote de Bosch et de Denso dans une liste qui ne contient
     * sinon que des fabricants. C'est la seule place structuree disponible, et
     * c'est l'information qui compte pour l'acheteur : elle explique le prix.
     */
    private const ORIGINE = 'Chine';

    private const CATEGORIE = 'Filtre à huile';

    /**
     * Les references en stock, et leurs compatibilites confirmees.
     *
     * Chaque compatibilite est `[slug du modele, annee de debut, annee de fin,
     * code moteur, provenance]`, avec un sixieme element facultatif : le code
     * de motricite (`RWD`, `AWD`) quand c'est lui, et lui seul, qui separe
     * deux references sur un meme modele et un meme moteur. Les annees nulles
     * signifient « toute la generation ».
     *
     * La provenance n'est pas decorative : elle cite le tableau et le statut
     * d'ou la ligne sort, et quand les bornes d'annees ne sont pas celles du
     * document, elle dit pourquoi. Sans elle, une compatibilite est
     * invérifiable des que son auteur a oublie le detail — c'est-a-dire au
     * bout de quelques mois.
     *
     * Sept references n'en portent aucune : leurs lignes attendent une
     * verification moteur par moteur, ou — pour les deux dernieres venues, la
     * 90915-30002 et la 04152-51010 — n'existent tout simplement pas au statut
     * « Confirme » dans le document. Elles restent trouvables par reference et
     * par numero constructeur, ce qui est honnete — le catalogue ne promet rien
     * qu'il ne puisse tenir.
     *
     * @var list<array{sku: string, nom: string, description: string, remplace?: list<string>, equivalents?: list<string>, compatibilites: list<array{0: string, 1: ?int, 2: ?int, 3: string, 4: string, 5?: string}>}>
     */
    private const FILTRES = [
        [
            "sku"           => "90915-YZZF2",
            "nom"           => "Filtre a huile visse 90915-YZZF2",
            "description"   => "Visse, quatre cylindres. Reference ancienne, remplacee par le 90915-YZZN1 sur certains catalogues de service.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZE1",
            "nom"           => "Filtre a huile visse 90915-YZZE1",
            "description"   => "Visse, compact. Remplace par le 90915-YZZN2 sur certains catalogues de service.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZD1",
            "nom"           => "Filtre a huile visse 90915-YZZD1",
            "description"   => "Visse, anciennes applications V6 et certains utilitaires essence. Ne convient pas a tous les gros moteurs Toyota.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "90915-YZZD2",
            "nom"           => "Filtre a huile visse 90915-YZZD2",
            "description"   => "Visse, moteurs diesel 2KD-FTV 2.5, 1KD-FTV 3.0, 2GD-FTV 2.4, 1GD-FTV 2.8 et certains 2TR-FE 2.7.",
            "compatibilites" => [
                ["hilux-an10", 2005, 2015, "1KD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["hilux-an120", 2015, 2020, "2GD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-prado-j120", 2003, 2009, "1KD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-prado-j150", 2009, 2015, "1KD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["fortuner-an50", null, null, "2KD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["fortuner-an160", 2015, 2020, "2GD-FTV", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
            ],
        ],
        [
            "sku"           => "90915-YZZN1",
            "nom"           => "Filtre a huile visse 90915-YZZN1",
            "description"   => "Visse, reference de service. Remplace le 90915-YZZF2 et le 90915-10009 selon le marche.",
            // Ancien numero de service, encore imprime sur des boites : un
            // client qui le cherche doit trouver ce filtre.
            "remplace"      => ["90915-10009"],
            "compatibilites" => [
                // La borne 2020 venait du premier document. Le catalogue de
                // correspondance donne « 2018+ » et la table par code moteur
                // « toutes Toyota/Lexus 2018 et apres » : la borne fermee
                // privait une Camry de 2022 de son filtre.
                ["camry-xv70", 2018, null, "A25A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Camry / hybride 2.5 A25A-FKS / FXS 2018+. Borne haute ouverte d'apres le document, qui remplace le 2020 du premier document."],
                ["camry-xv70", 2018, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Camry / hybride 2.5 A25A-FKS / FXS 2018+. Ligne hybride, absente du premier document."],
                ["rav4-xa50", 2019, null, "A25A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : RAV4 / hybride / Prime 2.5 A25A-FKS / FXS 2019+."],
                ["rav4-xa50", 2019, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : RAV4 / hybride / Prime 2.5 A25A-FKS / FXS 2019+. Ligne hybride, absente du premier document."],

                // Corolla : le document date le passage de la cartouche au
                // filtre visse au millesime 2016, « les deux montages
                // coexistent ce millesime ». Le 2016 est donc exclu des deux
                // cotes — il releve du controle visuel, pas du catalogue.
                ["corolla-e140", 2006, 2008, "1ZZ-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Corolla / Matrix 1.8 1ZZ-FE 1998-2008. Borne basse remontee a 2006, debut de la generation E140 ; les generations E120 et E130 manquent au referentiel."],
                ["corolla-e180", 2017, 2019, "2ZR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme ; 2016 conditionnel » : Corolla 1.8 2ZR-FE / FAE montage visse 2016+. Le 2016 est ecarte, le document y donne les deux montages comme coexistants."],
                ["corolla-e180", 2017, 2019, "2ZR-FAE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme ; 2016 conditionnel » : Corolla 1.8 2ZR-FE / FAE montage visse 2016+. Le 2016 est ecarte, le document y donne les deux montages comme coexistants."],
                ["corolla-e210", 2019, null, "2ZR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Corolla 1.8 2ZR-FE / FAE montage visse 2016+."],
                ["corolla-e210", 2019, null, "2ZR-FAE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Corolla 1.8 2ZR-FE / FAE montage visse 2016+."],
                ["corolla-e210", 2020, null, "2ZR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Corolla hybride 1.8 2ZR-FXE 2020+."],
                ["corolla-e210", 2019, null, "M20A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Corolla berline / Hatchback / Cross 2.0 M20A-FKS 2019+. Rattachee a la seule E210 ; le Corolla Cross est un modele distinct, absent du referentiel."],

                // La Prius XW50 traverse la transition : cartouche jusqu'en
                // 2017, visse a partir de 2018. Seule la seconde moitie est
                // posee ici, et l'audit du commercant avait deja ecarte la
                // cartouche sur cette generation pour la meme raison.
                ["prius-xw50", 2018, 2022, "2ZR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Prius 1.8 2ZR-FXE montage visse 2018-2022."],

                // Le slug a change de code, pas de voiture : la ligne du
                // referentiel portait « XP150 » sur la periode 2011-2020, qui
                // est celle de la XP130. Voir le commentaire de
                // `VehicleModelsSeeder`. La borne basse de 2011 et sa
                // justification restent donc exactes — elles designaient deja
                // le debut de cette generation-la, sous un mauvais nom.
                ["yaris-xp130", 2011, 2019, "1NZ-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Yaris 1.5 1NZ-FE 2006-2019. Borne basse remontee a 2011, debut de la generation XP130 ; la XP90 manque au referentiel."],

                // Les cinq modeles d'importation americaine. Ces lignes sont
                // confirmees trois fois : « Confirme » dans ce tableau-ci,
                // « Non compatible » dans le tableau YZZA1 — qui prescrit donc
                // la YZZN1 pour ces vehicules — et la table par code moteur
                // (A25A -> YZZN1 pour tout 2018 et apres, T24A-FTS -> YZZN1
                // pour le Highlander 2023+).
                ["highlander", 2020, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Highlander hybride 2.5 A25A-FXS 2020+. Le tableau YZZA1 prescrit la meme reference pour ce vehicule, et la table par code moteur la confirme."],
                ["highlander", 2023, null, "T24A-FTS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Highlander / Grand Highlander 2.4 turbo T24A-FTS 2023+. La table par code moteur la confirme (T24A-FTS -> YZZN1, « Highlander 2023+ »). Le Grand Highlander est un modele distinct, absent du referentiel."],
                ["sienna", 2021, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Sienna hybride 2.5 A25A-FXS 2021+. Le tableau YZZA1 prescrit la meme reference pour ce vehicule."],
                // Deplacee du slug nu `venza` vers la seconde generation : la
                // periode 2021-2024 tient entierement dans la XU80, qui est
                // ouverte depuis 2020, donc le rattachement ne perd ni
                // n'invente aucune annee.
                ["venza-xu80", 2021, 2024, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Venza hybride 2.5 A25A-FXS 2021-2024. Le tableau YZZA1 donne la meme ligne en 2021+ ; la borne fermee du tableau YZZN1, plus precise, est retenue. Rattachee a la seconde generation XU80 (2020-), qui couvre toute la periode de la ligne ; la ligne nue `venza` la portait avant que les generations existent."],
                ["avalon", 2019, 2022, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Avalon hybride 2.5 A25A-FXS 2019-2022. Le tableau YZZA1 prescrit la meme reference pour ce vehicule."],

                // Lexus. Le tableau Lexus de cette partie sort decale a
                // l'extraction : les noms de modeles y retardent de deux
                // lignes. Les sept lignes ont ete reconstituees en recoupant
                // l'extraction en colonnes (sept modeles, sept moteurs, sept
                // plages, sept filtres, sept statuts), puis verifiees contre la
                // table par code moteur. Les cinq « Confirme Lexus » suivent.
                ["es-xv70", 2019, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : ES 300h 2.5 A25A-FXS 2019+. Ligne reconstituee par recoupement des deux extractions, et confirmee par la table par code moteur (A25A -> YZZN1, tout 2018 et apres)."],
                ["es-xv70", 2021, null, "A25A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : ES 250 2.5 A25A-FKS 2021+. Ligne reconstituee par recoupement des deux extractions, et confirmee par la table par code moteur."],
                ["nx-az20", 2022, null, "A25A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : NX 250 / NX 350h / NX 450h+ 2.5 A25A-FKS / FXS 2022+. Le tableau YZZA1 Lexus prescrit la meme reference pour ce vehicule (ligne « Non compatible », chassis AAZA / AAZH)."],
                ["nx-az20", 2022, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : NX 250 / NX 350h / NX 450h+ 2.5 A25A-FKS / FXS 2022+. Le tableau YZZA1 Lexus prescrit la meme reference pour ce vehicule (ligne « Non compatible », chassis AAZA / AAZH)."],
                ["rx-al30", 2023, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : RX 350h 2.5 A25A-FXS 2023+. Le tableau YZZA1 Lexus prescrit la meme reference pour ce vehicule (ligne « Non compatible », chassis ALH10)."],
                ["ux-za10", 2019, null, "M20A-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : UX 200 / UX 250h 2.0 M20A-FKS / FXS 2019+."],
                ["ux-za10", 2019, null, "M20A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Lexus, statut « Confirme Lexus » : UX 200 / UX 250h 2.0 M20A-FKS / FXS 2019+."],
            ],
        ],
        [
            "sku"           => "90915-YZZN2",
            "nom"           => "Filtre a huile visse 90915-YZZN2",
            "description"   => "Visse compact, moteurs 1NZ-FE, 2NR-FE/FBE, 1ZZ-FE et 3ZZ-FE.",
            "compatibilites" => [
            ],
        ],
        [
            "sku"           => "04152-YZZA1",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA1",
            "description"   => "Element filtrant, moteurs 2AR-FE, 2AR-FXE, 6AR-FSE/FBS et nombreux 2GR. Ne convient pas aux A25A recents.",
            "compatibilites" => [
                // Les deux lignes du premier document, que le catalogue de
                // correspondance confirme deux fois : au tableau YZZA1 Toyota
                // (« Confirme ») et au tableau YZZN1, qui prescrit justement la
                // YZZA1 pour ces vehicules. Bornes laissees ouvertes : la
                // generation couvre exactement la periode du moteur.
                ["camry-xv50", null, null, "2AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Camry 2.5 2AR-FE 2010-2017 FWD. La generation XV50 (2011-2017) couvre la periode ; le tableau YZZN1 prescrit la YZZA1 pour cette Camry."],
                ["rav4-xa40", null, null, "2AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : RAV4 2.5 2AR-FE 2009-2018. La generation XA40 (2012-2018) couvre la fin de la periode ; le tableau YZZN1 prescrit la YZZA1 pour ce RAV4."],

                ["camry-xv50", 2012, 2017, "2AR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Camry hybride 2.5 2AR-FXE 2012-2017 FWD."],
                ["camry-xv50", 2011, 2017, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Camry 3.5 2GR-FE 2007-2017 FWD. Borne basse remontee a 2011, debut de la generation XV50 ; la XV40 manque au referentiel."],
                ["camry-xv70", 2018, 2024, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Camry 3.5 2GR-FKS 2018-2024 FWD. C'est l'exemple du document : la meme XV70 prend la cartouche en 2GR-FKS et le filtre visse en A25A."],
                ["rav4-xa40", 2016, 2018, "2AR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : RAV4 hybride 2.5 2AR-FXE 2016-2018 AWD."],

                // Les cinq modeles d'importation americaine, poses au
                // referentiel sur decision du proprietaire. Ce sont eux qui
                // concentraient le plus de lignes « Confirme » sans
                // destinataire : quatorze ici, cinq sur la YZZN1.
                //
                // Trois sont toujours designes par un slug NU, sans
                // generation, et c'est volontaire — voir la note de fin de
                // bloc. Le Tacoma et le Venza, eux, ont recu leurs
                // generations et leurs lignes les ont suivies.
                //
                // Le piege est que ces memes modeles portent aussi des lignes
                // « Non compatible » dans ce tableau — Highlander hybride
                // 3.3 3MZ-FE (YZZD1), Highlander 2.5 A25A-FXS, Highlander
                // 2.4 T24A-FTS, Sienna hybride, Venza hybride et Avalon
                // hybride en A25A-FXS (YZZN1). Aucune n'est ici : elles
                // prescrivent une autre reference.
                ["highlander", 2008, 2016, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Highlander 3.5 2GR-FE 2008-2016 FWD / AWD."],
                ["highlander", 2009, 2019, "1AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Highlander 2.7 1AR-FE 2009-2019 FWD. Confirmee par la table par code moteur (1AR-FE -> YZZA1, « Highlander, Sienna, Venza »)."],
                ["highlander", 2011, 2016, "2GR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Highlander hybride 3.5 2GR-FXE 2011-2016 AWD."],
                ["highlander", 2017, 2022, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Highlander 3.5 2GR-FKS 2017-2022 FWD / AWD."],
                ["highlander", 2017, 2019, "2GR-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Highlander hybride 3.5 2GR-FXS 2017-2019 AWD."],
                ["sienna", 2007, 2016, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Sienna 3.5 2GR-FE 2007-2016 FWD / AWD."],
                ["sienna", 2011, 2013, "1AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Sienna 2.7 1AR-FE 2011-2013 FWD."],
                ["sienna", 2017, 2020, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Sienna 3.5 2GR-FKS 2017-2020 FWD / AWD."],
                // Les deux lignes du Venza, deplacees du slug nu vers sa
                // premiere generation. 2009-2016 tient dans l'AV10, dont la
                // production va de 2008 a 2017 : la ligne ne perd aucune
                // annee par le bas, et sa borne haute de 2016 tombe avant la
                // fin de la generation. Elle ne touche pas la XU80, qui ne
                // commence qu'en 2020 — aucun chevauchement, donc aucune
                // decision du proprietaire a prendre ici.
                ["venza-av10", 2009, 2016, "1AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Venza 2.7 1AR-FE 2009-2016 FWD / AWD. Rattachee a la premiere generation AV10 (2008-2017), qui couvre toute la periode de la ligne."],
                ["venza-av10", 2009, 2016, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Venza 3.5 2GR-FE 2009-2016 FWD / AWD. Rattachee a la premiere generation AV10 (2008-2017), qui couvre toute la periode de la ligne."],
                ["avalon", 2005, 2022, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon 3.5 2GR-FE / FKS 2005-2022 FWD."],
                ["avalon", 2016, 2022, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon 3.5 2GR-FE / FKS 2005-2022 FWD. Le document groupe les deux codes sans les dater ; borne basse a 2016, date du passage FE -> FKS donnee par les lignes Tacoma, Highlander et Sienna du meme document."],
                ["avalon", 2013, 2018, "2AR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon hybride 2.5 2AR-FXE 2013-2018 FWD."],
                // La premiere des dix-neuf lignes des cinq importations
                // americaines a avoir quitte son slug nu : 2016-2023 tient
                // entierement dans la troisieme generation du Tacoma
                // (2016-2023), donc le rattachement ne perd ni n'invente
                // aucune annee. Les trois lignes du Venza l'ont suivie, pour
                // la meme raison et avec la meme marge.
                //
                // ── LES QUINZE QUI RESTENT, ET POURQUOI AUCUNE NE BOUGE ─────
                //
                // Highlander sept, Sienna quatre, Avalon quatre. SEPT D'ENTRE
                // ELLES SONT A CHEVAL sur deux generations, et aucune source
                // n'y changera rien — c'est la ligne de compatibilite qui est
                // plus large que la generation :
                //
                //   Highlander 2GR-FE  2008-2016 : XU40 + XU50
                //   Highlander 1AR-FE  2009-2019 : XU40 + XU50 + XU70
                //   Highlander 2GR-FXE 2011-2016 : XU40 + XU50
                //   Highlander 2GR-FKS 2017-2022 : XU50 + XU70
                //   Sienna     2GR-FE  2007-2016 : XL20 + XL30
                //   Avalon     2GR-FE  2005-2022 : XX30 + XX40 + XX50
                //   Avalon     2GR-FKS 2016-2022 : XX40 + XX50
                //
                // Les bornes de production qui donnent ce verdict sont celles
                // de `GenerationsVenzaTest::GENERATIONS_NON_POSEES`, chacune
                // lue dans deux sources nommees. CE TABLEAU N'EST PAS RECOPIE
                // A LA MAIN : ce test le recalcule depuis les lignes
                // ci-dessous et echoue si l'une d'elles change de bornes.
                //
                // Scinder une de ces lignes en deux serait CREER une
                // compatibilite, et la rattacher a l'une des deux generations
                // ferait disparaitre le filtre pour les annees de l'autre —
                // sans que le compte de lignes bouge. Les deux appartiennent
                // au proprietaire.
                //
                // LES HUIT AUTRES NE SONT PAS A CHEVAL, ET POURTANT ELLES NE
                // BOUGENT PAS NON PLUS. C'est le point a ne pas defaire :
                // deplacer, par exemple, « Highlander 2GR-FXS 2017-2019 » sur
                // un `highlander-xu50` tout en laissant « Highlander 2GR-FE
                // 2008-2016 » sur la ligne nue poserait un piege. Les deux
                // lignes resteraient atteignables, mais par DEUX entrees
                // differentes du catalogue : un vendeur qui choisit la
                // generation XU40 pour un Highlander de 2010 ne verrait plus
                // la cartouche, qui pend au slug nu. Une piece qui convient et
                // qu'on ne voit pas est du meme ordre qu'une piece qui ne
                // convient pas et qu'on vend.
                //
                // Ces trois modeles sont donc indivisibles : ou bien leurs
                // sept lignes a cheval sont tranchees et les quinze suivent,
                // ou bien rien ne bouge. Le Venza a pu passer parce qu'il
                // n'avait aucune ligne a cheval, et pour cette seule raison.
                ["tacoma-iii", 2016, 2023, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Tacoma 3.5 2GR-FKS 2016-2023 RWD / 4WD. Rattachee a la troisieme generation, qui couvre 2016-2023 ; la ligne nue `tacoma` la portait avant que les generations existent."],

                // Lexus. Le tableau par modele sort decale a l'extraction
                // « -layout » : des valeurs tombent d'une colonne dans l'autre
                // et les lignes y sont inexploitables. Les douze lignes ont ete
                // reconstituees sur l'extraction en colonnes, qui rend chaque
                // colonne en liste continue : douze modeles, douze codes
                // chassis, douze triplets moteur/annees/transmission, douze
                // filtres, douze statuts — les comptes tombent juste. Chaque
                // ligne a ensuite ete verifiee deux fois : le code chassis doit
                // correspondre au modele, et le filtre a la table par code
                // moteur. Seules les lignes qui passent ces deux controles sont
                // ici.
                //
                // Le decoupage par generation est mecanique : intersection de
                // la plage du document avec la plage de la generation. Quand le
                // document groupe deux codes moteur sur une ligne sans les
                // dater separement (« 2GR-FE / FKS »), le FKS n'est pose que
                // sur les generations qui atteignent 2016 — c'est la date que
                // le document lui-meme donne au passage FE -> FKS sur quatre
                // modeles Toyota (Tacoma 2016, Highlander et Sienna 2017,
                // Camry 2018). Idem pour FXE -> FXS, date a 2017 par la ligne
                // Highlander hybride.
                ["es-xv40", 2007, 2012, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : ES 350, chassis GSV40 / GSV50 / GSV60 / GSV70, 3.5 2GR-FE / FKS 2007-2024 FWD. Ligne reconstituee sur l'extraction en colonnes. Plage bornee a la generation XV40 ; seul le 2GR-FE y est possible, le document datant le FKS de 2016."],
                ["es-xv60", 2012, 2018, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : ES 350 3.5 2GR-FE / FKS 2007-2024 FWD. Plage bornee a la generation XV60."],
                ["es-xv60", 2016, 2018, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : ES 350 3.5 2GR-FE / FKS 2007-2024 FWD. Le document groupe les deux codes sans les dater ; borne basse a 2016, date du passage FE -> FKS donnee par les lignes Toyota du meme document."],
                ["es-xv60", 2013, 2018, "2AR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : ES 300h, chassis AVV60, 2.5 2AR-FXE 2013-2018 FWD. Confirmee par la table par code moteur (2AR-FE / 2AR-FXE -> YZZA1, « Camry, RAV4, ES 300h, NX 300h »)."],
                ["rx-xu30", 2007, 2009, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 350, chassis GSU35 / GGL15 / GGL25, 3.5 2GR-FE / FKS 2007-2022. Plage bornee a la generation XU30, que le chassis GSU35 designe."],
                ["rx-al10", 2009, 2015, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 350 3.5 2GR-FE / FKS 2007-2022. Plage bornee a la generation AL10 (chassis GGL15) ; seul le 2GR-FE y est possible, le document datant le FKS de 2016."],
                ["rx-al20", 2015, 2022, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 350 3.5 2GR-FE / FKS 2007-2022. Plage bornee a la generation AL20 (chassis GGL25)."],
                ["rx-al20", 2016, 2022, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 350 3.5 2GR-FE / FKS 2007-2022. Borne basse a 2016, date du passage FE -> FKS donnee par les lignes Toyota du meme document."],
                ["rx-al10", 2010, 2015, "2GR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 450h, chassis GYL15 / GYL25, 3.5 2GR-FXE / FXS 2010-2022 AWD. Plage bornee a la generation AL10 (chassis GYL15)."],
                ["rx-al20", 2015, 2022, "2GR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 450h 3.5 2GR-FXE / FXS 2010-2022 AWD. Plage bornee a la generation AL20 (chassis GYL25)."],
                ["rx-al20", 2017, 2022, "2GR-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : RX 450h 3.5 2GR-FXE / FXS 2010-2022 AWD. Borne basse a 2017, date du passage FXE -> FXS donnee par la ligne Highlander hybride du meme document."],
                ["is-xe30", 2016, 2021, "8AR-FTS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : IS 300 / RC 300 / IS 200t, chassis ASE30 / ASC10, 2.0 turbo 8AR-FTS 2016-2021 RWD. Confirmee par la table par code moteur (8AR-FTS -> YZZA1, « NX 200t / NX 300, IS 300, RC 300 »)."],
                ["rc-xc10", 2016, 2021, "8AR-FTS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Lexus, statut « Confirme Lexus » : IS 300 / RC 300 / IS 200t, chassis ASE30 / ASC10, 2.0 turbo 8AR-FTS 2016-2021 RWD. Confirmee par la table par code moteur (8AR-FTS -> YZZA1, « NX 200t / NX 300, IS 300, RC 300 »)."],
            ],
        ],
        [
            "sku"           => "04152-YZZA6",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA6",
            "description"   => "Element filtrant court, moteurs 1.8L de la famille 2ZR.",
            "compatibilites" => [
                // Entre 2016 et fin 2017, Toyota abandonne ce boitier sur les
                // ZR au profit du filtre visse YZZN1, modele par modele et
                // parfois au milieu d'un millesime. Ne sont posees ici que les
                // generations entierement du cote cartouche. Celles qui
                // traversent la transition relevent du controle visuel du
                // boitier, pas du catalogue : le document les marque
                // « conditionnel » et l'audit du commercant avait deja ecarte
                // la Prius XW50 pour cette raison exacte.
                ["corolla-e140", 2009, 2013, "2ZR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA6, statut « Confirme ; 2016 conditionnel » : Corolla 1.8 2ZR-FE 2009-2016. Plage bornee a la generation E140, entierement anterieure a la transition."],
                ["corolla-e180", 2013, 2015, "2ZR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA6, statut « Confirme ; 2016 conditionnel » : Corolla 1.8 2ZR-FE 2009-2016. Borne haute a 2015 : le document donne les deux montages comme coexistants au millesime 2016."],
                ["prius-xw30", 2010, 2015, "2ZR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA6, statut « Confirme » : Prius 1.8 2ZR-FXE 2010-2017. Plage bornee a la generation XW30 ; la XW50 traverse la transition et reste ecartee."],
                ["ct-zwa10", 2011, 2017, "2ZR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA6, statut « Confirme Lexus » : Lexus CT 200h 1.8 2ZR-FXE 2011-2017. Le tableau YZZN1 Lexus prescrit la meme reference pour ce vehicule (ligne « Non compatible »)."],
            ],
        ],
        [
            "sku"           => "90915-YZZD4",
            "nom"           => "Filtre a huile visse 90915-YZZD4",
            "description"   => "Visse, gros moteurs essence 1GR-FE 4.0, 2UZ-FE 4.7 et 1FZ-FE 4.5. Ne convient pas aux diesel KD, qui relevent du 90915-YZZD2.",
            "remplace"      => ["90915-20004"],
            "compatibilites" => [
                ["hilux-an10", 2005, 2013, "1GR-FE", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["fortuner-an50", 2005, 2013, "1GR-FE", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-j100", 1998, 2007, "2UZ-FE", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-j200", 2007, 2012, "1GR-FE", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-prado-j120", 2003, 2009, "1GR-FE", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
                ["land-cruiser-prado-j120", 2002, 2009, "5L-E", "Premier document fournisseur, ligne retenue par l'audit du commercant sur source constructeur."],
            ],
        ],
        [
            "sku"           => "90915-10001",
            "nom"           => "Filtre a huile visse 90915-10001",
            "description"   => "Visse, ancienne reference remplacee par le 90915-YZZN1 sur les catalogues nord-americains. Applications anterieures a 2008, anterieures au referentiel.",
            "remplace"      => [],
            "compatibilites" => [
            ],
        ],

        // ─── Second catalogue, 19/10/2026 : les familles sans reference ───
        //
        // Le « Catalogue de compatibilite — filtres a huile Toyota & Lexus »
        // du 18/10/2026 range tout le programme en NEUF familles, et donne
        // pour chacune ses numeros de vente. Rapportee a ce que la boutique
        // porte deja, la lecture tient en une phrase : quatre familles n'y ont
        // aucune reference — la 2 (visse AZ), la 5 (diesel ancien), la 8
        // (cartouche longitudinale) et la 9 (cartouche V8).
        //
        // Les familles 8 et 9 recoivent DEUX pieces chacune, et ce n'est pas
        // un doublon : l'index des anciens numeros du document donne, en
        // colonne « References de vente », « YZZA3 (31020, 31080) / YZZA5
        // (31060, 38010, YZZA2) » pour la 8 et deux lignes distinctes pour la
        // 9. Ces couples ne sont pas des variantes de marche d'un meme filtre
        // — ils se partagent les applications, et le document interdit de les
        // confondre : « YZZA3 et YZZA5 sont proches mais pas identiques
        // (joints du kit) : respecter la prescription » ; « Toyota ne donne
        // pas la YZZA4 comme remplacante de la 51010 [...] Pour les 1VD-FTV,
        // commander et stocker la 51010 ». Les fondre ferait vendre le mauvais
        // kit, ce que six entrees evitent.
        //
        // Les vrais doublons, eux, sont ecartes : les numeros qui ne different
        // que par le marche (YZZE2 pour la YZZF1) ou qui sont perimes passent
        // en `equivalents` / `remplace`, pas en pieces.
        [
            "sku"           => "90915-YZZF1",
            "nom"           => "Filtre a huile visse 90915-YZZF1",
            "description"   => "Visse, meme filetage que le 90915-YZZN1 mais plus haut d'environ 13 mm. Moteurs 1AZ, 2AZ, 2ZZ-GE et 3S-GTE. Vendu sous le numero 90915-YZZE2 en Asie, Australie, Golfe et Europe.",
            // Le document consacre un encadre a ce piege : « La 90915-YZZA1
            // (visse) et la 04152-YZZA1 (cartouche) n'ont rien en commun. »
            // L'ancien numero est quand meme rattache ici, mais la raison
            // n'est pas celle qu'on croit, et elle est mesuree : sans lui, la
            // recherche sur « 90915-YZZA1 » ne rendait RIEN — pas la cartouche.
            // `Part::scopeSearch` compare `normalized_number LIKE '%90915YZZA1%'`,
            // et la cartouche est normalisee « 04152YZZA1 », qui ne contient pas
            // cette chaine. Le rattachement transforme donc zero resultat en le
            // bon. C'est la recherche TRONQUEE qui est ambigue : « YZZA1 » seul
            // rend les DEUX familles ensemble, et c'est la que le piege de
            // l'encadre se joue.
            "equivalents"   => ["90915-YZZE2"],
            "remplace"      => ["90915-10004", "90915-10002", "90915-03004", "90915-TA002", "90915-YZZA1"],
            "compatibilites" => [
                // Seule ligne « Confirme » de la famille dont le modele existe
                // au referentiel. Les Camry ACV30 / ACV40 et les RAV4 ACA2# /
                // ACA3#, qui en portent le gros, relevent de generations que
                // le referentiel n'a pas.
                ["corolla-e140", 2009, 2013, "2AZ-FE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 2 « Filtre visse AZ », statut « Confirmé » : Corolla XRS, Matrix, Matrix XRS 2.4 2AZ-FE 2009-2013. Deux fois corroboree : l'index par code moteur donne 1AZ / 2AZ → famille 2, « YZZF1 / YZZE2 », statut « Confirmé », et le tableau YZZA6 du premier document donnait cette Corolla « Non compatible » en prescrivant justement la YZZF1."],
            ],
        ],
        [
            "sku"           => "90915-30002",
            "nom"           => "Filtre a huile visse 90915-30002",
            "description"   => "Visse, anciens diesels Toyota 2L, 2L-TE, 3L, 5L, 5L-E, 1KZ-TE, 1HZ et 1HD. Distinct des filtres visses des familles 3 et 4. Numero unique : ce filtre n'a pas de variante regionale.",
            "remplace"      => ["90915-03006"],
            "compatibilites" => [
                // Aucune. La famille 5 est la seule du document dont AUCUNE
                // ligne ne porte « Confirme » seul : ses dix applications sont
                // « Confirme Boshoku », « Confirme moteur », « Documente » ou
                // « Probable ». La reference est donc publiee trouvable par
                // numero, et rien de plus.
                //
                // Deux d'entre elles visent des Prado du referentiel et
                // contredisent une ligne deja posee sur la 90915-YZZD4 — le
                // Prado 120 en 5L-E. La contradiction est rapportee au
                // proprietaire ; elle ne se tranche pas ici.
            ],
        ],
        [
            "sku"           => "04152-YZZA3",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA3",
            "description"   => "Element filtrant des V6 GR montes en longitudinal, Lexus a propulsion. A ne pas confondre avec la 04152-YZZA5 : le document les dit « proches mais pas identiques (joints du kit) ».",
            "equivalents"   => ["04152-31080", "04152-31020"],
            "compatibilites" => [
                // La motricite n'est pas decorative sur ces sept lignes :
                // c'est elle, et elle seule, qui separe la YZZA3 de la YZZA5
                // sur un meme modele et un meme code moteur. Le document le
                // fait par le code chassis, que le referentiel ne porte pas ;
                // `drivetrain_id` est la seule colonne qui sache le dire, et
                // `CompatibilityService` la lit.
                ["is-xe20", 2006, 2013, "4GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 propulsion, chassis GSE20 / GSE21 / GSE30 / GSE31, 2.5 4GR-FSE et 3.5 2GR-FSE, 2006-2015. Plage bornee a la generation XE20, que le chassis GSE20 designe.", "RWD"],
                ["is-xe20", 2006, 2013, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 propulsion, chassis GSE20 / GSE21 / GSE30 / GSE31, 2006-2015. Plage bornee a la generation XE20, que le chassis GSE21 designe.", "RWD"],
                ["is-xe30", 2013, 2015, "4GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 propulsion, chassis GSE30, 2006-2015. Plage bornee a l'intersection de la generation XE30 et de la plage du document.", "RWD"],
                ["is-xe30", 2013, 2015, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 propulsion, chassis GSE31, 2006-2015. Plage bornee a l'intersection de la generation XE30 et de la plage du document.", "RWD"],
                ["gs-s190", 2006, 2012, "3GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus GS 300 / GS 350 / GS 450h propulsion, chassis GRS190 / GRS191 / GRL10 / GRL11 / GWL10, 3.0 3GR-FSE et 3.5 2GR-FSE, 2006-2015. Plage bornee a la generation S190, que le chassis GRS190 designe.", "RWD"],
                ["gs-s190", 2006, 2012, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus GS 300 / GS 350 / GS 450h propulsion, 2006-2015. Plage bornee a la generation S190, que le chassis GRS191 designe.", "RWD"],
                ["gs-l10", 2012, 2015, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus GS 350 propulsion, chassis GRL10 / GRL11, 2006-2015. Plage bornee a l'intersection de la generation L10 et de la plage du document.", "RWD"],
                // Le millesime 2017 est coupe : le tableau de la famille 6
                // donne le meme chassis GSC10 en YZZA1 « apres 11/2017 ». La
                // transition tombe en cours d'annee, elle releve du controle
                // avant vente et pas du catalogue. Le 2GR-FKS longitudinal
                // part pour une autre raison : l'index par code moteur le
                // donne « Conditionnel » sur 2016-2020.
                ["rc-xc10", 2015, 2016, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA3, statut « Confirmé Lexus » : Lexus RC 350 propulsion, chassis GSC10, 3.5 2GR-FSE / FKS, « 2015 – production 11/2017 ». Borne haute ramenee a 2016, le document donnant le meme chassis en YZZA1 a partir de 11/2017.", "RWD"],
            ],
        ],
        [
            "sku"           => "04152-YZZA5",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA5",
            "description"   => "Element filtrant des GR longitudinaux a quatre roues motrices, des V8 UR-FSE et des 1GR-FE a boitier cartouche (2010 et apres). A ne pas confondre avec la 04152-YZZA3.",
            "equivalents"   => ["04152-38010", "04152-31060", "04152-YZZA2"],
            "compatibilites" => [
                ["is-xe20", 2006, 2013, "4GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 a quatre roues motrices, chassis GSE25 / GSE35 / GSE36, 4GR-FSE et 2GR-FSE / FKS, 2006-2020. Plage bornee a la generation XE20, que le chassis GSE25 designe.", "AWD"],
                ["is-xe20", 2006, 2013, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 a quatre roues motrices, 2006-2020. Plage bornee a la generation XE20.", "AWD"],
                ["is-xe30", 2013, 2020, "4GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 a quatre roues motrices, chassis GSE35, 2006-2020. Plage bornee a l'intersection de la generation XE30 et de la plage du document.", "AWD"],
                ["is-xe30", 2013, 2020, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus IS 250 / IS 350 a quatre roues motrices, 2006-2020. Plage bornee a l'intersection de la generation XE30 et de la plage du document. Le 2GR-FKS est ecarte : l'index par code moteur le donne « Conditionnel » sur 2016-2020.", "AWD"],
                ["gs-s190", 2007, 2012, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus GS 350 a quatre roues motrices, chassis GRS196 / GRL15 / GRL16, 2GR-FSE / FKS, 2007-2020. Plage bornee a la generation S190, que le chassis GRS196 designe.", "AWD"],
                ["gs-l10", 2012, 2020, "2GR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus » : Lexus GS 350 a quatre roues motrices, chassis GRL15 / GRL16, 2007-2020. Plage bornee a la generation L10. Le 2GR-FKS est ecarte : l'index par code moteur le donne « Conditionnel » sur 2016-2020.", "AWD"],
                // Les trois lignes V8 n'ont pas de motricite : ce n'est pas la
                // transmission qui les range ici, c'est le moteur. Le titre de
                // la famille le dit — « 4RM, V8 UR-FSE, 1GR-FE 2010+ » — et la
                // GS 460 est une propulsion.
                ["gs-s190", 2007, 2012, "1UR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus, Boshoku » : Lexus GS 460 / LS 460 / LS 600h, chassis URS190 / USF40 / UVF45, 4.6 1UR-FSE et 5.0 2UR-FSE, 2007-2017. Le chassis URS190 designe la GS 460 ; plage bornee a la generation S190. L'index par code moteur confirme : 1UR-FSE / 2UR-FSE → famille 8, « 04152-38010 / YZZA5 »."],
                ["ls-xf40", 2007, 2017, "1UR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus, Boshoku » : Lexus GS 460 / LS 460 / LS 600h, chassis URS190 / USF40 / UVF45, 2007-2017. Le chassis USF40 designe la LS 460 de generation XF40. L'index par code moteur confirme."],
                ["ls-xf40", 2007, 2017, "2UR-FSE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 8, applications YZZA5, statut « Confirmé Lexus, Boshoku » : Lexus GS 460 / LS 460 / LS 600h, chassis URS190 / USF40 / UVF45, 2007-2017. Le chassis UVF45 designe la LS 600h de generation XF40. L'index par code moteur confirme."],
            ],
        ],
        [
            "sku"           => "04152-YZZA4",
            "nom"           => "Cartouche de filtre a huile 04152-YZZA4",
            "description"   => "Element filtrant des V8 essence UR : 4.6 1UR-FE et 5.7 3UR-FE. Ne convient pas au V8 diesel 1VD-FTV, qui releve de la 04152-51010.",
            "equivalents"   => ["04152-38020"],
            "compatibilites" => [
                // Le seul vehicule du referentiel que la famille 9 atteigne,
                // et il est triplement etabli : « Confirme » au tableau de la
                // famille, « Confirme » a l'index par code moteur, et designe
                // nommement par la ligne « Non compatible » du tableau YZZA1
                // du premier document — celle qui prescrit cette
                // reference-ci. Le Tundra, le Sequoia et le LX 570 portent les
                // autres lignes ; aucun n'est au referentiel, sauf le LX 570
                // dont le statut est « Documente ».
                ["land-cruiser-j200", 2008, 2021, "3UR-FE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 9, applications YZZA4, statut « Confirmé » : Land Cruiser 200, chassis URJ200, 5.7 3UR-FE et 4.6 1UR-FE, 2008-2021. L'index par code moteur confirme : 1UR-FE / 3UR-FE / FBE → famille 9, « 04152-38020 / YZZA4 », statut « Confirmé »."],
                ["land-cruiser-j200", 2008, 2021, "1UR-FE", "Catalogue de compatibilite du 18/10/2026 (neuf familles), famille 9, applications YZZA4, statut « Confirmé » : Land Cruiser 200, chassis URJ200, 5.7 3UR-FE et 4.6 1UR-FE, 2008-2021. L'index par code moteur confirme."],
            ],
        ],
        [
            "sku"           => "04152-51010",
            "nom"           => "Cartouche de filtre a huile 04152-51010",
            "description"   => "Element filtrant du V8 diesel 1VD-FTV : Land Cruiser 200 et 70 V8, Lexus LX 450d. Le document refuse l'equivalence avec la 04152-YZZA4 et demande de stocker ce numero a part.",
            "compatibilites" => [
                // Aucune. Les trois applications du document sont
                // « Documente » ou « Probable », et l'index par code moteur
                // range le 1VD-FTV en « Documente — equivalence YZZA4
                // equipementier ». Le Land Cruiser 200 diesel est pourtant du
                // parc local : il est rapporte, pas devine.
            ],
        ],
    ];

    public function run(): void
    {
        $categorie = PartCategory::where('name', self::CATEGORIE)->first();

        if ($categorie === null) {
            $this->command?->error(
                sprintf('Categorie « %s » introuvable : aucun filtre ecrit.', self::CATEGORIE)
            );

            return;
        }

        $origine = Manufacturer::firstOrCreate(['name' => self::ORIGINE]);

        // Le prix et le stock que porte deja le rayon, lus une fois avant la
        // boucle. C'est la consigne du proprietaire sur le troisieme
        // versement : « conduis les memes prix et quantite je reajusterai
        // apres ». La lire
        // en base plutot que de recopier self::PRIX fait que si le prix a
        // bouge depuis l'application, les references nouvelles arrivent au
        // prix du jour au lieu de rouvrir une ligne a 3 000.
        //
        // Lu avant la boucle, et pas dans la boucle : sinon la premiere piece
        // creee se mettrait a peser sur le prix des suivantes du meme passage.
        // Le troisieme argument n'est pas une symetrie manquee : un stock
        // dominant de 0 est une information VRAIE — le rayon est vide — et doit
        // etre suivi, tandis qu'un prix dominant de 0 n'est pas « le prix du
        // jour » mais l'absence de prix. Voir `valeurDominante()`.
        $prixCourant  = $this->valeurDominante('selling_price', self::PRIX, strictementPositif: true);
        $stockCourant = (int) $this->valeurDominante('stock_quantity', self::STOCK_INITIAL);

        // Les motricites qui separent la YZZA3 de la YZZA5. `firstOrCreate` et
        // non une lecture simple : `DrivetrainsSeeder` ne tourne pas dans tous
        // les contextes ou celui-ci tourne, et une ligne qui perdrait sa
        // motricite rendrait les deux cartouches indiscernables.
        $motricites = [
            'RWD' => Drivetrain::firstOrCreate(['code' => 'RWD'], ['label' => 'Propulsion arrière'])->id,
            'AWD' => Drivetrain::firstOrCreate(['code' => 'AWD'], ['label' => 'Transmission intégrale permanente'])->id,
        ];

        $ecrits = 0;
        $lignes = 0;
        $absents = [];
        $retirees = [];

        foreach (self::FILTRES as $filtre) {
            DB::transaction(function () use ($filtre, $categorie, $origine, $prixCourant, $stockCourant, $motricites, &$ecrits, &$lignes, &$absents, &$retirees) {
                // Ce que le depot decrit, et qu'il a donc le droit de
                // reecrire a chaque publication.
                $description = [
                    'name'                   => $filtre['nom'],
                    'description'            => $filtre['description'],
                    'part_category_id'       => $categorie->id,
                    'manufacturer_id'        => $origine->id,
                    'manufacturer_reference' => $filtre['sku'],
                    'type'                   => 'aftermarket',
                    'condition'              => 'new',
                ];

                // Ce que la boutique gere, et que le depot ne pose qu'une fois.
                //
                // Le seeder faisait un `updateOrCreate` sur tout : chaque
                // republication aurait remis le prix a 3000 et le stock a la
                // valeur du fichier, effacant un reajustement fait depuis
                // l'application. Le commercant aurait corrige son stock le
                // matin et l'aurait retrouve faux apres le deploiement du
                // soir, sans comprendre.
                //
                // Le depot pose donc ces valeurs a la creation et n'y touche
                // plus. Pour les changer en masse, il faut les changer ici ET
                // vider la colonne concernee — un geste explicite, pas un
                // effet de bord.
                //
                // La valeur posee est celle du rayon, pas celle du fichier :
                // voir `valeurDominante()`. Sur une base vide, les deux se
                // confondent.
                $commerce = [
                    'selling_price'  => $prixCourant,
                    'stock_quantity' => $filtre['stock'] ?? $stockCourant,
                    'is_active'      => true,
                ];

                // `withTrashed()`, et ce n'est pas une precaution de style.
                // `Part` est en suppression douce et `sku` est unique EN BASE,
                // corbeille comprise. La lecture ordinaire ne voyait pas une
                // reference mise a la corbeille depuis l'application : le
                // seeder la croyait absente, tentait de la creer, et la base
                // refusait sur la contrainte d'unicite. L'exception remontait
                // hors de la boucle, privant de leur mise a jour toutes les
                // references SITUEES APRES celle mise a la corbeille dans
                // `self::FILTRES` — jusqu'a quinze sur seize si c'est la
                // premiere, une seule si c'est l'avant-derniere — et la
                // migration de deploiement n'affichait qu'un avertissement.
                $piece = Part::withTrashed()->where('sku', $filtre['sku'])->first();

                // Une reference a la corbeille y reste. La ressusciter
                // remettrait en vente ce que le commercant a retire, au
                // prochain deploiement et sans qu'il le demande.
                if ($piece !== null && $piece->trashed()) {
                    $retirees[] = $filtre['sku'];

                    return;
                }

                if ($piece === null) {
                    $piece = Part::create(
                        array_merge(['sku' => $filtre['sku']], $description, $commerce)
                    );
                } else {
                    $piece->update($description);
                }

                $this->rattacherNumeros($piece, $filtre);

                // Remplacement plutot qu'empilement : le seeder se rejoue.
                $piece->fitments()->delete();

                foreach ($filtre['compatibilites'] as $ligne) {
                    [$slug, $de, $a, $moteur, $provenance] = $ligne;

                    // Sixieme element facultatif, lu ainsi et non par
                    // destructuration : les soixante-quatorze lignes du
                    // premier versement n'en ont pas, et une destructuration
                    // a six leur ferait lever un avertissement chacune.
                    $motricite = $ligne[5] ?? null;

                    $modele = VehicleModel::where('slug', $slug)->first();

                    if ($modele === null) {
                        $absents[] = $filtre['sku'].' → '.$slug;

                        continue;
                    }

                    $piece->fitments()->create([
                        'vehicle_model_id' => $modele->id,
                        'year_from'        => $de,
                        'year_to'          => $a,
                        'engine_code'      => $moteur,
                        // Renseignee sur les seules lignes ou la motricite
                        // fait la difference entre deux references. Laissee
                        // nulle ailleurs, ou elle vaudrait « toutes » —
                        // `CompatibilityService` lit une ligne sans motricite
                        // comme valable pour toutes.
                        //
                        // LE BORD, et c'est le seul endroit du catalogue ou
                        // renseigner un champ fait DISPARAITRE la piece : le
                        // filtre est `drivetrain_id IS NULL OR = X`, une egalite
                        // exacte. Le referentiel porte QUATRE motricites et ces
                        // lignes n'en citent que deux, donc un vehicule saisi en
                        // FWD ou en 4WD ne voit AUCUNE cartouche. Mesure sur une
                        // IS XE20 de 2010 en 4GR-FSE : sans motricite les deux
                        // cartouches, en RWD la YZZA3, en AWD la YZZA5, en 4WD
                        // et en FWD rien du tout.
                        //
                        // Ce n'est pas une regression — ces modeles n'avaient
                        // aucun filtre avant ce versement — et poser aussi les
                        // lignes en 4WD serait PIRE : cela rendrait les deux
                        // cartouches a un 4x4 debrayable qui n'existe sur aucune
                        // de ces Lexus. Le risque reel est la saisie : ces
                        // generations n'ont aucune finition au referentiel, donc
                        // la motricite ne vient d'aucun defaut, seulement de ce
                        // qu'un vendeur a coche — et « integrale permanente »
                        // contre « 4x4 debrayable » n'est pas evident sur une
                        // Lexus.
                        'drivetrain_id'    => $motricite === null ? null : $motricites[$motricite],
                        // `source` ne peut pas porter la provenance : c'est une
                        // colonne de dix caracteres dont l'enum ne connait que
                        // `declared` et `generated`, et dont la seule question
                        // est « le recalcul du catalogue a-t-il le droit
                        // d'effacer cette ligne ». Le d'ou-vient-elle tient
                        // donc dans `notes`, en clair.
                        'notes'            => $provenance,
                        'source'           => FitmentSource::Declared,
                    ]);

                    $lignes++;
                }

                $ecrits++;
            });
        }

        // Le prix annonce est celui qui a reellement ete pose, pas la
        // constante du fichier : sur une base ou le proprietaire a deja
        // ajuste, les deux different, et c'est le premier qui renseigne.
        $this->command?->info(sprintf(
            '%d filtre(s), %d compatibilite(s) confirmee(s), %s F CFA piece.',
            $ecrits,
            $lignes,
            rtrim(rtrim(number_format((float) $prixCourant, 2, '.', ''), '0'), '.'),
        ));

        // Un modele absent est une compatibilite perdue en silence : on le dit.
        foreach (array_unique($absents) as $manquant) {
            $this->command?->warn("  modele introuvable, compatibilite ignoree : {$manquant}");
        }

        // Une reference a la corbeille n'est ni une erreur ni un silence : le
        // commercant l'a retiree, le depot le constate et passe.
        foreach (array_unique($retirees) as $retiree) {
            $this->command?->warn("  reference a la corbeille, laissee telle quelle : {$retiree}");
        }
    }

    /**
     * Attache a la piece son numero constructeur, et les anciens qu'il remplace.
     *
     * Un client arrive souvent avec le numero imprime sur sa vieille boite, qui
     * n'est plus celui du catalogue. Sans la chaine de remplacement, sa
     * recherche ne rend rien alors que la piece est en rayon.
     *
     * Deux sortes de numeros secondaires, et la distinction n'est pas
     * cosmetique. `remplace` designe un numero PERIME : il part en
     * `is_superseded` et pointe sur le numero actuel, ce qui fait dire a
     * l'application « cette reference a ete remplacee par celle-ci ».
     * `equivalents` designe un numero TOUJOURS EN VIGUEUR, simplement sur un
     * autre marche — le 90915-YZZE2 asiatique du 90915-YZZF1, les numeros
     * d'origine 04152-31080 et 31020 de la YZZA3. Les marquer perimes
     * afficherait une supersession que Toyota n'a jamais prononcee.
     *
     * @param  array{sku: string, nom: string, remplace?: list<string>, equivalents?: list<string>}  $filtre
     */
    private function rattacherNumeros(Part $piece, array $filtre): void
    {
        $actuel = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize($filtre['sku']), 'brand_id' => null],
            ['number' => $filtre['sku'], 'label' => $filtre['nom'], 'is_superseded' => false],
        );

        $piece->oemNumbers()->syncWithoutDetaching([$actuel->id => ['is_primary' => true]]);

        foreach ($filtre['equivalents'] ?? [] as $equivalent) {
            $autre = OemNumber::firstOrCreate(
                ['normalized_number' => OemNumber::normalize($equivalent), 'brand_id' => null],
                ['number' => $equivalent, 'label' => $filtre['nom'], 'is_superseded' => false],
            );

            // Le `is_superseded => false` du `firstOrCreate` ne s'applique qu'a
            // la CREATION : il fallait donc le forcer ici, comme la branche
            // `remplace` force l'etat inverse. Sans cela, un numero deja marque
            // perime par un autre chemin — `PartController::syncOemNumbers`,
            // `OemNumberController::store`, `BackfillFitments` — le restait pour
            // toujours, alors que ce seeder est declare seul auteur de ces liens.
            //
            // Ce que cela produisait, mesure : la 04152-38010 marquee perime vers
            // la 04152-YZZA1 survivait a la relance, et l'application annoncait
            // qu'une cartouche LONGITUDINALE avait ete remplacee par une
            // cartouche TRANSVERSALE — la confusion de familles que le document
            // interdit nommement (2GR-FE transversal en famille 6 contre
            // 2GR-FSE longitudinal en famille 8).
            $autre->update(['is_superseded' => false, 'superseded_by_id' => null]);

            $piece->oemNumbers()->syncWithoutDetaching([$autre->id => ['is_primary' => false]]);
        }

        foreach ($filtre['remplace'] ?? [] as $ancien) {
            $perime = OemNumber::firstOrCreate(
                ['normalized_number' => OemNumber::normalize($ancien), 'brand_id' => null],
                ['number' => $ancien, 'label' => $filtre['nom']],
            );

            $perime->update(['is_superseded' => true, 'superseded_by_id' => $actuel->id]);

            $piece->oemNumbers()->syncWithoutDetaching([$perime->id => ['is_primary' => false]]);
        }
    }

    /**
     * La valeur que porte deja le gros du rayon, ou le defaut sur base vide.
     *
     * La plus repandue, et non la premiere trouvee ni la moyenne : les dix
     * references du premier versement sont toutes au meme prix, et le jour ou
     * le proprietaire en ajuste une seule, c'est le prix des neuf autres qui
     * reste le prix de la boutique. Egalite departagee par la plus basse, pour
     * que le resultat ne depende pas de l'ordre de lecture — un seeder qui
     * ecrirait tantot 3 000 tantot 4 500 selon l'ordre des lignes serait
     * ingerable.
     *
     * Ne lit que les references de ce fichier : le prix d'un amortisseur n'a
     * rien a dire sur celui d'un filtre.
     *
     * ── ZERO N'EST PAS UN PRIX, ET C'EST TOUT L'OBJET DU PLANCHER ───────────
     *
     * Le meme appel sert deux colonnes dont la semantique differe. Un stock
     * dominant de 0 est une information vraie et doit etre suivi. Un prix
     * dominant de 0 est l'ABSENCE de prix, et mettre a zero une ligne en
     * rupture est un geste de boutique banal : rien n'empeche ces lignes
     * d'etre majoritaires parmi les seize.
     *
     * Mesure qui a motive le plancher, sur base jetable : neuf des seize
     * references a 0, une reference recreee ensuite naissait a 0,00 avec pour
     * seule trace l'avertissement « 0 F CFA piece ». La cartouche V8 du Land
     * Cruiser 200 — la seule ligne du parc local qu'apporte ce versement —
     * serait partie en rayon a zero franc. La page publique s'en garde,
     * `ArticlePartage::prixFormate` rendant « Prix sur demande » des que
     * l'arrondi est <= 0 ; `PartResource` rend le zero brut au catalogue de
     * l'application, qui le formate sans garde.
     */
    private function valeurDominante(string $colonne, int|float $defaut, bool $strictementPositif = false): int|float
    {
        $valeurs = Part::query()
            ->whereIn('sku', array_column(self::FILTRES, 'sku'))
            ->pluck($colonne)
            ->filter(fn ($v) => $v !== null && (! $strictementPositif || (float) $v > 0))
            ->map(fn ($v) => (float) $v)
            ->all();

        if ($valeurs === []) {
            return $defaut;
        }

        $comptes = array_count_values(array_map('strval', $valeurs));

        // Tri sur le couple (frequence decroissante, valeur croissante).
        uksort($comptes, function (string $a, string $b) use ($comptes) {
            return [$comptes[$b], (float) $a] <=> [$comptes[$a], (float) $b];
        });

        return (float) array_key_first($comptes);
    }
}
