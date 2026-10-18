<?php

namespace Database\Seeders;

use App\Enums\FitmentSource;
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
    /** Ce que le commercant facture, en francs CFA. */
    private const PRIX = 3000;

    /**
     * Le stock pose a la creation d une reference.
     *
     * Uniforme au demarrage : le commercant ajustera depuis l application,
     * arrivage par arrivage. Cette valeur ne sert donc qu aux references
     * nouvelles — celles deja en base gardent le leur, voir run().
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
     * Les huit references en stock, et leurs compatibilites confirmees.
     *
     * Chaque compatibilite est `[slug du modele, annee de debut, annee de fin,
     * code moteur, provenance]`. Les annees nulles signifient « toute la
     * generation ».
     *
     * La provenance n'est pas decorative : elle cite le tableau et le statut
     * d'ou la ligne sort, et quand les bornes d'annees ne sont pas celles du
     * document, elle dit pourquoi. Sans elle, une compatibilite est
     * invérifiable des que son auteur a oublie le detail — c'est-a-dire au
     * bout de quelques mois.
     *
     * Cinq references n'en portent aucune : leurs lignes attendent une
     * verification moteur par moteur. Elles restent trouvables par reference et
     * par numero constructeur, ce qui est honnete — le catalogue ne promet rien
     * qu'il ne puisse tenir.
     *
     * @var list<array{sku: string, nom: string, description: string, remplace?: list<string>, compatibilites: list<array{0: string, 1: ?int, 2: ?int, 3: string, 4: string}>}>
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

                ["yaris-xp150", 2011, 2019, "1NZ-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Yaris 1.5 1NZ-FE 2006-2019. Borne basse remontee a 2011, debut de la generation XP150 ; la XP90 manque au referentiel."],

                // Les cinq modeles d'importation americaine. Ces lignes sont
                // confirmees trois fois : « Confirme » dans ce tableau-ci,
                // « Non compatible » dans le tableau YZZA1 — qui prescrit donc
                // la YZZN1 pour ces vehicules — et la table par code moteur
                // (A25A -> YZZN1 pour tout 2018 et apres, T24A-FTS -> YZZN1
                // pour le Highlander 2023+).
                ["highlander", 2020, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Highlander hybride 2.5 A25A-FXS 2020+. Le tableau YZZA1 prescrit la meme reference pour ce vehicule, et la table par code moteur la confirme."],
                ["highlander", 2023, null, "T24A-FTS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Highlander / Grand Highlander 2.4 turbo T24A-FTS 2023+. La table par code moteur la confirme (T24A-FTS -> YZZN1, « Highlander 2023+ »). Le Grand Highlander est un modele distinct, absent du referentiel."],
                ["sienna", 2021, null, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Sienna hybride 2.5 A25A-FXS 2021+. Le tableau YZZA1 prescrit la meme reference pour ce vehicule."],
                ["venza", 2021, 2024, "A25A-FXS", "Catalogue de correspondance du 18/10/2026, tableau YZZN1 Toyota, statut « Confirme » : Venza hybride 2.5 A25A-FXS 2021-2024. Le tableau YZZA1 donne la meme ligne en 2021+ ; la borne fermee du tableau YZZN1, plus precise, est retenue."],
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
                ["venza", 2009, 2016, "1AR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Venza 2.7 1AR-FE 2009-2016 FWD / AWD."],
                ["venza", 2009, 2016, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Venza 3.5 2GR-FE 2009-2016 FWD / AWD."],
                ["avalon", 2005, 2022, "2GR-FE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon 3.5 2GR-FE / FKS 2005-2022 FWD."],
                ["avalon", 2016, 2022, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon 3.5 2GR-FE / FKS 2005-2022 FWD. Le document groupe les deux codes sans les dater ; borne basse a 2016, date du passage FE -> FKS donnee par les lignes Tacoma, Highlander et Sienna du meme document."],
                ["avalon", 2013, 2018, "2AR-FXE", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Avalon hybride 2.5 2AR-FXE 2013-2018 FWD."],
                ["tacoma", 2016, 2023, "2GR-FKS", "Catalogue de correspondance du 18/10/2026, tableau YZZA1 Toyota, statut « Confirme » : Tacoma 3.5 2GR-FKS 2016-2023 RWD / 4WD."],

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

        $ecrits = 0;
        $lignes = 0;
        $absents = [];

        foreach (self::FILTRES as $filtre) {
            DB::transaction(function () use ($filtre, $categorie, $origine, &$ecrits, &$lignes, &$absents) {
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
                $commerce = [
                    'selling_price'  => self::PRIX,
                    'stock_quantity' => $filtre['stock'] ?? self::STOCK_INITIAL,
                    'is_active'      => true,
                ];

                $piece = Part::where('sku', $filtre['sku'])->first();

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

                foreach ($filtre['compatibilites'] as [$slug, $de, $a, $moteur, $provenance]) {
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

        $this->command?->info(sprintf(
            '%d filtre(s), %d compatibilite(s) confirmee(s), %d F CFA piece.',
            $ecrits,
            $lignes,
            self::PRIX,
        ));

        // Un modele absent est une compatibilite perdue en silence : on le dit.
        foreach (array_unique($absents) as $manquant) {
            $this->command?->warn("  modele introuvable, compatibilite ignoree : {$manquant}");
        }
    }

    /**
     * Attache a la piece son numero constructeur, et les anciens qu'il remplace.
     *
     * Un client arrive souvent avec le numero imprime sur sa vieille boite, qui
     * n'est plus celui du catalogue. Sans la chaine de remplacement, sa
     * recherche ne rend rien alors que la piece est en rayon.
     *
     * @param  array{sku: string, nom: string, remplace?: list<string>}  $filtre
     */
    private function rattacherNumeros(Part $piece, array $filtre): void
    {
        $actuel = OemNumber::firstOrCreate(
            ['normalized_number' => OemNumber::normalize($filtre['sku']), 'brand_id' => null],
            ['number' => $filtre['sku'], 'label' => $filtre['nom'], 'is_superseded' => false],
        );

        $piece->oemNumbers()->syncWithoutDetaching([$actuel->id => ['is_primary' => true]]);

        foreach ($filtre['remplace'] ?? [] as $ancien) {
            $perime = OemNumber::firstOrCreate(
                ['normalized_number' => OemNumber::normalize($ancien), 'brand_id' => null],
                ['number' => $ancien, 'label' => $filtre['nom']],
            );

            $perime->update(['is_superseded' => true, 'superseded_by_id' => $actuel->id]);

            $piece->oemNumbers()->syncWithoutDetaching([$perime->id => ['is_primary' => false]]);
        }
    }
}
