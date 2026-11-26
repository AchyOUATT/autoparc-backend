# Applications du filtre 90915-YZZD1 (PR #40 et #41)

Revue du 26 novembre 2026, faite après la relecture du code de la PR #41 par
le propriétaire. Ce document décrit la convention d'années, les sources, les
chevauchements, le comportement de l'API et les exclusions.

## Convention : années de fabrication

`part_fitments.year_from` et `year_to` sont des **années civiles de
fabrication**. C'est ce que l'API compare : `CompatibilityService` et
`PartCompatibilityAssessment` confrontent ces bornes à
`manufacturing_year`, le champ « Année de fabrication » de l'application.
`owned_vehicles.model_year` est enregistré mais n'entre pas dans
l'appariement.

Les bornes viennent des **dates de production de l'EPC Toyota** pour le
châssis et le moteur concernés, et elles restent dans les bornes de
production (`vehicle_models.production_start/end`) de la génération. Le
catalogue PDF Toyota donne des années-modèle américaines : il fixe le
moteur, pas les bornes. Par exemple, le millésime 2003 de la Sienna XL10 a
été fabriqué en 2002 : la ligne s'arrête en 2002, comme la génération.

Deux générations déjà présentes ont des bornes plus étroites que l'EPC :
`tacoma-ii` (2005, EPC 09/2004) et la ligne nue `highlander` (2008, EPC
08/2007 pour le MHU48). Ces bornes n'ont pas été modifiées. La ligne s'arrête
donc à la borne de la génération, et la note précise les mois à contrôler
au VIN.

## Châssis et marché

- **Châssis** : la colonne `part_fitments.chassis_code` reçoit les codes de
  l'EPC (par exemple `TRN280/TRN285`). Elle est affichée, mais **pas
  comparée**, car un véhicule enregistré n'a pas de champ châssis.
- **Marché** : aucune colonne n'existe pour le marché. Il est écrit dans la
  note de chaque ligne (USA, plus Général quand l'EPC Général le confirme).
  `owned_vehicles.specification_market` n'entre pas dans l'appariement.
- Une ligne n'est posée que si l'EPC montre le filtre (90915-20001 ou
  90915-20003, remplacés par 90915-YZZD1) **sur le châssis concerné**,
  jamais d'après le seul code moteur.

## Sources (consultées le 26/11/2026)

| Rôle | Source |
| --- | --- |
| Moteur (Toyota, années-modèle US) | [Catalogue Toyota des filtres 2025-2026](https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf) |
| Périodes de production par châssis | [EPC Toyota par catalogue](https://toyota-epc.aftermarketcatalog.com), par exemple [4Runner 671490](https://toyota-epc.aftermarketcatalog.com/vehicle/US/671490/info) |
| Filtre par châssis (schéma 15-02) | EPC, recherche inverse [90915-20003 US](https://www.toyodiy.com/parts/xref?mU=on&s=90915-20003), [90915-20001 US](https://www.toyodiy.com/parts/xref?mU=on&s=90915-20001), [90915-20003 Général](https://www.toyodiy.com/parts/xref?mG=on&s=90915-20003), et les schémas cités ligne par ligne |
| Remplacement 20001 / 20003 → YZZD1 | [Catalogue concessionnaire Toyota](https://www.toyotapartsdeal.com/oem-toyota-4runner-oil_filter.html) |
| Recoupement des générations | Wikipedia (source secondaire) |

### Niveau de preuve des remplacements

Les schémas EPC des châssis anciens portent 90915-20001 ou 90915-20003, pas
90915-YZZD1. Chaque remplacement est qualifié dans la table unique
`oem_number_relations` (PR #42, alimentée par l'import de la PR #40) : nature
du lien (`kind`), niveau de preuve (`evidence_level`), URL consultées
(`sources`), date (`verified_on`). Le drapeau historique `oem_numbers`
(`is_superseded`, `superseded_by_id`) reste posé, sans niveau de preuve.
L'API les expose dans `replaced_by` / `replaces` :

| Ancien numéro | `kind` / `evidence_level` | Sources |
| --- | --- | --- |
| 90915-YZZB1, 90915-YZZB9, 90915-YZZG2 | `manufacturer_supersession` / `manufacturer_official` | [fiche officielle Toyota](https://autoparts.toyota.com/products/product/filter-oil-90915yzzd1) (« Previous Version(s) »), parts.lexus.com |
| 90915-20001, 90915-20003 | `distributor_replacement` / `dealer_catalog` | [ToyotaPartsDeal 20001](https://www.toyotapartsdeal.com/oem/toyota~filter~sub~assy~oil~90915-20001.html), [ToyotaPartsDeal 20003](https://www.toyotapartsdeal.com/parts-list/2006-toyota-highlander-jpp_hybrid/engine_fuel_tool/oil_filter.html), [ToyotaPartsNow](https://www.toyotapartsnow.com/oem/toyota~filter-oil~90915-yzzd1), [Frontier Toyota](https://parts.frontiertoyota.com/oem-parts/toyota-oil-filter-90915yzzd1), [Nucar Toyota](https://toyotaparts.nucar.com/parts/toyota-oil-filter-90915yzzd1) |

Cette preuve de REMPLACEMENT est distincte de la preuve de COMPATIBILITÉ
(`evidence_level` / `evidence_sources` des lignes de compatibilité, et
`notes`).

Le second remplacement a été accepté par le propriétaire le 26/11/2026.
Rattacher un ancien numéro ne transfère aucune compatibilité : un numéro
remplacé (drapeau ou lien qualifié) ne transmet jamais ses lignes à la pièce
qui le porte, sauf s'il en est le numéro principal ; chaque ligne reste
justifiée par le schéma EPC de son châssis.

Les revendeurs utilisés auparavant pour les Lexus (autohausaz, mugen-24) ne
servent plus de preuve : chaque ligne Lexus cite désormais un schéma EPC.

## 4Runner N280 et 2TR-FE

Le « 2010- » du PDF ne vaut pas pour toute la génération. L'EPC américain
(catalogue 671490) donne pour ce moteur :

- **TRN280L-GKPGKA** (propulsion) : 11/2009 à 08/2011 ;
- **TRN285L-GKPGKA** (4x4) : 08/2010 à 08/2011.

Le schéma 15-02 de ces deux châssis porte 90915-20003 dans le groupe
« (0911- ) 2TRFE ». L'EPC Général et l'EPC des années 2012 et suivantes ne
listent que les GRN280 et GRN285 (V6 1GR-FE, cartouche 04152-YZZA5).

La ligne est donc **2009–2011, marché USA uniquement**. Elle remplace
2010–2024. Les sources secondaires indiquent que Toyota a retiré le 2TR-FE
de la gamme américaine après le millésime 2010.

## Chevauchements

Bornes de production de chaque génération concernée (années de fabrication) :

| Modèle | Générations | Année partagée |
| --- | --- | --- |
| Avalon | XX10 1994–1999, XX20 1999–2004, `avalon` 2005– | 1999 (réelle : MCX10 jusqu'en 08/1999, MCX20 dès 08/1999) |
| Lexus ES | XV20 1996–2001, XV30 2001–2006, XV40 2006–2012 | 2001 et 2006 (réelles) |
| Lexus IS | XE10 1999–2005, XE20 2005–2013 | 2005 (réelle) |
| Lexus RX | XU10 1998–2003, XU30 2003–2009 | 2003 (réelle) |
| Lexus GS | S160 1997–2004, S190 2005–2012 | aucune |
| Solara | XV20 1998–2002, XV30 2003–2008 | aucune (EPC : 11/2002 puis 07/2003 ; la fin XV20 passe de 2003 à 2002) |
| Highlander | XU20 2000–2007, `highlander` 2008– | aucune en base ; en réalité, le XU40 est fabriqué dès 05/2007 |
| Sienna | XL10 1997–2002, `sienna` 2007– | aucune (XL20 absente, voir la proposition Sienna) |

Les années partagées sont **réelles** et ne sont pas rognées. La règle
d'invariant existante (`fin <= début suivant`) les tolère.

L'application lève l'ambiguïté de quatre façons :

- **Le choix explicite.** Le propriétaire choisit la génération
  (`vehicle_model_id`) à l'enregistrement. Les lignes étant posées par
  génération, une année partagée ne mélange rien.
- **Le décodage VIN.** Quand deux générations couvrent l'année, il propose la
  plus récente (`VinDecodeService::generationPour`) et l'utilisateur corrige.
- **L'avertissement de l'application mobile.** Il s'affiche quand la
  génération choisie ne couvre pas l'année saisie (`couvreAnnee`).
- **Le code moteur.** Il départage les années de passage d'un moteur à
  l'autre (Highlander 2003, Camry XV10 1993).

Seul le Highlander XU40 de 2007 tombe hors des bornes de la ligne nue
`highlander`. Ce cas est signalé et n'est pas corrigé ici, car la ligne nue
existe déjà.

## Comportement de l'API (testé)

Les tests `Toyota90915Yzzd1CompatibiliteApiTest` (PR #40) et
`Toyota90915Yzzd1AnciensModelesApiTest` (PR #41) couvrent les cas suivants :

| Cas | Liste publique | Avec `include_candidates=1` | Garage |
| --- | --- | --- | --- |
| Bon modèle + bon moteur + année dans les bornes (y compris aux bornes) | présent | `confirmed` | `confirmed`, `compatible: true` |
| Autre moteur connu (5S-FE, 1GR-FE, 2AZ-FE, 3UZ-FE, 1G-FE) | absent | absent | `unknown` / `no_matching_fitment`, `compatible: false` |
| Année hors bornes (4Runner 2TR-FE 2012, Sienna XL10 2003, GS 2005) | absent | absent | `unknown` |
| Moteur inconnu | absent | `candidate`, manque `engine_code` | `candidate`, « à vérifier » |
| Année inconnue | absent | `candidate`, manque `year` | — |
| Finition inconnue sur une ligne par finition (RX XU30) | absent | `candidate`, manque `trim_id` | — |
| Autre finition connue (RX 350) | absent | absent | — |
| Châssis inconnu, marché différent (`specification_market=EU`) | sans effet | sans effet | `confirmed` si le moteur et l'année concordent |

Un critère inconnu ne produit jamais `confirmed`. Le châssis et le marché
documentent la ligne, mais ne la filtrent pas.

## Exclusions

- **Moteurs** : les 4 cylindres 5S-FE (YZZN1), 1AZ-FE et 2AZ-FE (YZZF1) ;
  le 1GR-FE et le 2UZ-FE (YZZD3) ; le 2GR (cartouches) ; le diesel 1KZ-TE ;
  le 2MZ-FE japonais ; le 1G-FE (IS 200) ; les V8 du GS.
- **Marchés non couverts** :
  - l'Avalon australienne MCX10 (2000–2005) ;
  - la Camry MCV36 (EPC Général, schéma non relu) ;
  - les Supra du marché japonais (JZA80, 1993–2002) ;
  - les Camry 3VZ-FE hors Amérique du Nord.
- **Sienna XL20 (2003–2009)** : non créée. Voir la proposition de
  restructuration du référentiel Sienna (branche
  `docs/proposition-referentiel-sienna`).
