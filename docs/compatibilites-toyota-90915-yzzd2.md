# Filtre 90915-YZZD2 : preuves Toyota Boshoku et EPC (26/11/2026)

Migration `2026_11_26_230000_qualifier_les_preuves_boshoku_du_filtre_90915_yzzd2`,
classe `App\Support\Toyota90915Yzzd2PreuvesBoshoku`, rejouée à la fin de
`FiltresHuileBoutiqueSeeder`.

## 1. En bref

- Les **quatre** lignes Hilux / Fortuner déjà posées reçoivent le niveau
  `aftermarket_crossref` et leurs sources. Elles **restent candidates**.
- **Huit** couples génération × moteur sont ajoutés, au même niveau, donc
  candidats. Ils sont documentés à la fois par Toyota Boshoku et par l'EPC
  (miroir non officiel). Ils incluent l'**essence 2TR-FE**.
- Les deux lignes **Prado** (J120, J150) reçoivent le niveau
  `aftermarket_crossref`, avec comme sources des **revendeurs de pièces
  d'origine** australiens et britannique (pas Toyota, pas Boshoku). Elles
  **restent candidates**, avec la note « à vérifier par moteur ». Les
  périodes des sources divergent : les bornes ne sont pas touchées.
- Le désaccord de Toyota Indus sur la J150 (cartouche 04152-YZZA5) est décrit
  comme une **probable confusion de moteur**, pas comme une contradiction.
- **Fortuner AN50 2KD-FTV** : l'année de début passe de vide à **2005**
  (Boshoku, Toyota Indus et EPC concordent). La fin reste ouverte.
- La **description** ne limite plus la YZZD2 aux diesels : « Hilux, Fortuner
  et Prado hors États-Unis, surtout diesel », avec l'essence 2TR-FE.
- Ce lot ne pose aucun marché, mois ni châssis.
- Il ne pose aucun niveau confirmable.
- Il ne crée aucune relation entre numéros d'origine.
- Il n'hérite d'aucune compatibilité de la 90915-YZZD1.

## 2. Sources (consultées le 26/11/2026)

| # | Source | Nature | Niveau retenu |
|---|---|---|---|
| S1 | [Toyota Boshoku, lineup « TBAS » des filtres à huile, mis à jour en 12/2023](https://www.tb-filter.toyota-boshoku.com/en/wp-content/uploads/2023/12/231227-TBAS-lineup-table_OIL.pdf), filtre OS-004 = « 90915-TB001 / 90915-YZZD2 » | Catalogue de l'équipementier d'origine, vendu sous sa propre marque. Années = **années d'immatriculation**. | `aftermarket_crossref` (voir la [note de conception](proposition-niveau-preuve-oem-supplier-catalog.md)) |
| S2 | [Toyota Boshoku, table de compatibilité Vietnam, mise à jour en 09/2026](https://tb-filter.toyota-boshoku.com/compatibility-table-search/pdf/compatibility-vn.pdf) : « Hilux 90915-YZZD2 → OS-004 » et « Hilux 90915-20005 → OS-004 » | Même éditeur ; ne donne ni moteur ni période. | Information seulement |
| S3 | EPC Toyota, groupe 15601, **miroir non officiel** : `https://toyota-epc.aftermarketcatalog.com/vehicle/<marché>/<catalogue>/g/15601`, et [toyodiy](https://www.toyodiy.com/parts/xref?mG=on&s=90915-TB001) | **Non officiel** : le contenu de l'EPC Toyota n'a pas pu être vérifié sur un accès officiel. | Recoupement seulement ; jamais `manufacturer_epc` |
| S4 | [Toyota Indus, Pakistan](https://direct.toyota-indus.com/oil-filter-90915-yzzd2.html) et [Toyota Walton](https://store.toyotawalton.com/product/oil-filter-fortuner-hilux-2005-onwards-90915-yzzd2/) : « Fortuner & Hilux (2005 Onwards) » | Distributeur et concessionnaire Toyota au Pakistan. La liste de Toyota Indus est approximative : elle donne un « 2.7 DIESEL », un « 2.8 GASOLINE » et un « 3.0 GASOLINE », qui n'existent pas sur ces modèles. | `aftermarket_crossref` en source de la ligne Fortuner AN50 (début 2005) ; pas de niveau `dealer_catalog` (voir § 10) |
| S5 | Revendeurs : [mdparts](https://mdparts4x4.com/us/spd/90915-YZZD2/FILTER-OIL-90915-YZZD2), [N-Parts](https://n-parts.pl/en/794-original-toyota-oil-filter-90915-yzzd2.html) | Revendeurs | Caractéristiques physiques seulement (§ 9) |
| S6 | Prado, revendeurs de pièces d'origine (**pas Toyota**) : [All Four x 4 Spares, fiche du filtre](https://www.allfourx4.com.au/Genuine-Oil-Filter-suitable-for-Landcruiser-70-Series-Prado-Hilux-90915-YZZD2/), [All Four x 4 Spares, kit KDJ150](https://www.allfourx4.com.au/Genuine-Filter-Kit-suitable-for-Toyota-Landcruiser-Prado-150-Series-KDJ150/), [Nunawading Toyota Parts](https://www.nunawadingtoyotaparts.com.au/brand-new-genuine-toyota-oil-filter-90915yzzd2) (Australie), [Milner Off Road](https://www.milneroffroad.com/product/engine-oil-filter-genuine/) (Royaume-Uni ; relevé dans un extrait de résultat de recherche, page non ouverte : 403 au téléchargement) | Revendeurs. Nunawading est la boutique de pièces d'un concessionnaire Toyota, pas un catalogue constructeur. | `aftermarket_crossref` en sources des deux lignes Prado |
| S7 | [Toyota Indus, 04152-YZZA5](https://direct.toyota-indus.com/oil-filter-04152-yzza5.html) ; [Toyota US, 04152-YZZA5](https://autoparts.toyota.com/products/product/replaceable-element-oil-filter-04152yzza5) | Distributeur Toyota au Pakistan ; boutique Toyota US | Information seulement (note J150) |

## 3. Tableau exhaustif des compatibilités identifiées

Les verdicts sont ceux de l'application après cette PR, pour un véhicule qui
déclare le modèle, l'année et le moteur.
« Candidate » veut dire visible avec `include_candidates`, jamais
« confirmée ». Les codes châssis relevés dans l'EPC (miroir) restent
**informatifs** et ne sont pas posés.

### 3.1 Lignes de la base (14)

| # | Modèle (Boshoku) | Génération (réf.) | Châssis relevés dans l'EPC (miroir, informatif) | Moteur | Marché de la source | Période en base | Période des sources | Source | Niveau | Verdict | Statut de la ligne |
|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Hilux Vigo | hilux-an10 | KUN15/16/25/26/35/36 (famille KD) | 1KD-FTV | Asie (Boshoku) ; général (EPC 672370, 672380) | 2005–2015 (inchangée) | S1 2004~2015 ; EPC 2004-08 → 2016-10 | S1, S3 | aftermarket_crossref | candidate | existante 319, enrichie |
| 2 | Hilux Vigo | hilux-an10 | idem | 2KD-FTV | idem | 2004–2015 | S1 2004~2015 ; EPC 2004-08 → 2016-10 | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 3 | Hilux Vigo | hilux-an10 | TGN16/26/36 | 2TR-FE (essence) | idem | 2005–2015 | S1 2004~2015 ; EPC 2005-01 → ouvert (672380) | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 4 | Hilux Revo | hilux-an120 | GUN12x/13x/112 | 2GD-FTV | Asie ; général (672390, 6723A0…) | 2015–2020 (inchangée) | S1 2015~ ; EPC 2015-05 → ouvert | S1, S3 | aftermarket_crossref | candidate | existante 320, enrichie |
| 5 | Hilux Revo | hilux-an120 | GUN12x/13x | 1GD-FTV | idem | 2015–ouverte | S1 2015~ ; EPC 2015-05 → ouvert | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 6 | Hilux Revo | hilux-an120 | TGN126 | 2TR-FE (essence) | idem | 2015–ouverte | S1 2015~2018 ; EPC 2015-05 → ouvert | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 7 | Fortuner | fortuner-an50 | KUN50/51/60/61 | 2KD-FTV | Asie ; général (671380, 6713A0) | **2005**–ouverte (début posé ; avant : sans borne) | S1 2005~2015 ; S4 « 2005 onwards » ; EPC 2005-01 → 2016-11 | S1, S3, S4 | aftermarket_crossref | candidate (exclue avant 2005) | existante 323, enrichie |
| 8 | Fortuner | fortuner-an50 | KUN50/51/60/61 | 1KD-FTV | idem | 2005–2015 | S1 2005~2015 ; EPC 2005-01 → 2016-11 | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 9 | Fortuner | fortuner-an50 | TGN51/61 | 2TR-FE (essence) | idem | 2005–2015 | S1 2005~2015 ; EPC 2005-01 → 2017-03 | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 10 | Fortuner | fortuner-an160 | GUN155/156/165/166 | 2GD-FTV | Asie ; général (6713B0, 6713C0…) | 2015–2020 (inchangée) | S1 2015~ ; EPC 2015-07 → ouvert | S1, S3 | aftermarket_crossref | candidate | existante 324, enrichie |
| 11 | Fortuner | fortuner-an160 | GUN155/156/165/166 | 1GD-FTV | idem | 2015–ouverte | S1 2015~ ; EPC 2015-07 → ouvert | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 12 | Fortuner | fortuner-an160 | TGN156/166 | 2TR-FE (essence) | idem | 2015–ouverte | S1 2015~2019 ; EPC 2015-07 → ouvert | S1, S3 | aftermarket_crossref | candidate | **ajoutée** |
| 13 | — (absent de Boshoku) | land-cruiser-prado-j120 | KDJ12# | 1KD-FTV | — | 2003–2009 (inchangée) | S6 : KDJ120 de 09/2002 à 07/2006 (AU) ou dès 08/2006 (UK) ; EPC : 90915-30002 puis 90915-20003, jamais la YZZD2 | S6 | aftermarket_crossref | candidate | existante 321, sources de revendeurs, à vérifier par moteur (note) |
| 14 | — (absent de Boshoku) | land-cruiser-prado-j150 | KDJ15# | 1KD-FTV | — | 2009–2015 (inchangée) | S6 : KDJ150/155 de 08/2009 à 08/2015 ou 12/2023 selon la page ; EPC : 90915-20003 | S6 | aftermarket_crossref | candidate | existante 322, sources de revendeurs, à vérifier par moteur (note) |

Remarques :

- **Bornes 2020 des lignes 4 et 10.** Elles sont fermées, alors que Boshoku
  et l'EPC (miroir) sont ouverts. Elles ne sont **pas modifiées** : la
  consigne interdit de toucher les bornes sans preuve, et l'élargissement
  serait une décision métier. Un Hilux AN120 2GD-FTV de 2021 ou plus reste
  donc exclu par l'année. Voir le § 10.
- **Fins Boshoku 2018 et 2019 en 2TR-FE (lignes 6 et 12).** Elles ne sont pas
  reprises, car l'EPC (miroir) continue en 90915-TB001 au-delà. Ces fins sont
  écrites dans la note de chaque ligne.
- **Débuts de période.** Ce sont les débuts de production de l'EPC, ramenés
  aux bornes de la génération du référentiel. Boshoku ne donne que des années
  d'immatriculation.

### 3.2 Couples documentés mais non posés

| Modèle | Moteur | Marché | Période | Source | Niveau possible | Motif |
|---|---|---|---|---|---|---|
| Hiace (H200 puis H300) | 2KD-FTV, 1KD-FTV, 2TR-FE ; puis 1GD-FTV | Asie | 2004~2019 ; 2019~ | S1 | aftermarket_crossref | **Génération absente du référentiel** (suite à donner) |
| Innova (AN40 puis AN140) | 1TR-FE, 2KD-FTV ; puis 1GD-FTV | Asie | 2004~2023, 2004~2015 ; 2015~2023 | S1 | aftermarket_crossref | **Génération absente du référentiel** |
| Alphard (H10) | 1MZ-FE (essence V6) | Asie | 2002~2008 | S1 | aftermarket_crossref | **Génération absente du référentiel** |
| Hilux AN10 | 1TR-FE | général | EPC 2006-09 → 2011-08 (672370), puis 672380 | S3 seul | aucun | Absent de Boshoku ; EPC non officiel seul |
| Hilux AN120 | 1TR-FE | général | EPC 6723A0 et suivants | S3 seul | aucun | idem |
| Hilux AN120 | 1KD-FTV, 2KD-FTV | général | EPC 2015-05 → 2020-06 (672390) | S3 seul | aucun | Absent de Boshoku ; châssis KUN12x non vérifié |
| Fortuner AN160 | 1KD-FTV, 2KD-FTV | général | EPC 2015-12 → 2017-09 (6713B0, KUN156/165) | S3 seul ; Toyota Indus cite un « 3.0 DIESEL » de 2015 à 2022 | aucun | idem |
| Land Cruiser (J70 / J200…) 1FZ-FE, 2UZ-FE | — | — | — | S1 | — | **Autre filtre** (90915-20004 / YZZD4) : aucune ligne YZZD2 |

## 4. Générations, châssis et périodes (point 3)

- Les générations du référentiel recouvrent les catalogues de l'EPC (miroir) :
  - hilux-an10 (2004–2015) ↔ 672370 (2004-08 → 2012-04) et 672380
    (2011-07 → 2021-10, ancienne génération maintenue sur certains marchés) ;
  - hilux-an120 (2015–) ↔ 672390 (2015-05 → 2021-05), puis 6723A0 à 6723E0
    et 6733B0 à 6733E0 ;
  - fortuner-an50 (2005–2015) ↔ 671380 (2005-01 → 2012-04) et 6713A0 ;
  - fortuner-an160 (2015–) ↔ 6713B0 (2015-07 → 2021-04), puis 6713C0 à 6713G0.
- Les **codes châssis** du § 3 ont été relevés dans les libellés
  d'application de l'EPC (miroir), qui sont parfois tronqués. Ils n'ont pas
  été déduits du nom commercial ni du moteur. Aucun n'est posé, car
  `chassis_code` est éliminatoire quand il est restreint. Il faudra les
  vérifier sur un EPC officiel avant tout usage.
- **Non vérifiable** : sans accès officiel à l'EPC Toyota, les périodes et
  les codes châssis ci-dessus restent ceux d'un miroir tiers.

## 5. Land Cruiser Prado (point 5)

Les deux lignes restent **candidates** : `aftermarket_crossref` ne confirme
jamais. Le niveau et les sources viennent de revendeurs, pas de Toyota ni de
Boshoku. Châssis, marché et mois sont **seulement dans les notes**, jamais dans
les champs éliminatoires.

- **J120 (KDJ12#, 1KD-FTV)**, à vérifier par moteur et par date de fabrication :
  - Boshoku ne cite aucun Prado.
  - Revendeurs (S6) : All Four x 4 Spares et Nunawading Toyota (Australie)
    donnent YZZD2 ↔ KDJ120 de 09/2002 à 07/2006 seulement ; Milner Off Road
    (Royaume-Uni) dès 08/2006. Les périodes sont **opposées** : les bornes
    2003–2009 ne sont pas modifiées.
  - EPC, miroir non officiel : Europe (781550), 90915-30002 de 2002-09 à
    2006-08 puis 90915-20003 ; Japon (781150), 90915-30002 de 2002-11 à
    2007-07. La YZZD2 n'y figure pas.
  - Les revendeurs australiens citent aussi le **3RZ-FE** (RZJ120) : non repris
    (voir § 10).
- **J150 (KDJ15#, 1KD-FTV)**, à vérifier par moteur :
  - Boshoku ne cite aucun Prado.
  - Revendeurs (S6) : YZZD2 ↔ KDJ150/155 1KD-FTV de 08/2009 à 12/2023 (fiche
    du filtre, All Four x 4 Spares) ou à 08/2015 (kit d'entretien, même
    revendeur) ; Milner Off Road : tous les KDJ150. Seule la **fin** diverge :
    les bornes 2009–2015 ne sont ni resserrées ni élargies, aucune source
    Toyota ne tranchant.
  - EPC, miroir non officiel : 90915-20003 sur le KDJ15# 1KD-FTV de 2009-08 à
    2016 (781560). Le catalogue coupe la ligne en 2013-08 par variante, **sans
    changement de filtre** : ce n'est pas une divergence d'applicabilité. Le
    1GD-FTV et le 2TR-FE passent en 90915-20005 à partir de 2020-09.
  - Toyota Indus (S7) range « Prado 2009 onwards » sous la cartouche
    04152-YZZA5, pour des variantes 2.7 et 3.4, jamais 3.0. Cette cartouche
    équipe des moteurs essence aux États-Unis (4Runner 4.0 l, FJ Cruiser,
    Tundra) : c'est une **probable confusion de moteur**, à vérifier par
    moteur. Elle ne prouve ni n'infirme rien sur le 1KD-FTV.
- **Fiche NTK** : un kit NTK existe pour le KDJ150 1KD-FTV de 2009 à 2015
  ([NSK0014, Spark Plugs Direct](https://www.sparkplugsdirect.com.au/products/ntk-filter-service-kit-nsk0014-suit-toyota-landcruiser-kdj150r-1kd-ftv-3-0l-2009-2015)),
  mais il ne cite **pas** le 90915-YZZD2. Il ne relie donc rien à la YZZD2 :
  **non enregistré** comme preuve.
- **Références exactes par marché** : le numéro 90915-YZZD2 n'apparaît dans
  aucun catalogue Prado consulté, Europe, Japon ou général. Les numéros
  trouvés sont 90915-30002, 90915-20003 et 90915-20005, tous sur le miroir
  non officiel. Les catalogues du Golfe et d'Afrique n'ont pas pu être
  consultés.

## 6. 90915-TB001 (point 6)

Ce qui est établi :

- **Boshoku** écrit « 90915-TB001 / 90915-YZZD2 » sur chaque ligne OS-004.
  C'est une équivalence d'équipementier, pas une déclaration Toyota.
- **EPC, miroir non officiel** :
  - le numéro 90915-YZZD2 n'y figure pas ;
  - la position 15601 porte 90915-20003 et/ou 90915-TB001, puis 90915-20005
    selon le marché et la période ;
  - sur un schéma Hilux GUN126, toyodiy montre « 90915-TB001 MARK
    90915-TB001 ».
- **Chaîne de remplacement circulaire** sur aftermarketcatalog (non officiel) :
  TB001 « remplacé par » 20003, et 20003 « remplacé par » TB001. Le sens
  dépend du marché.

Ce qu'on en conclut :

- TB001 est un **numéro de distribution régional** du même filtre que la
  YZZD2, au moins sur les marchés asiatiques. Ce n'est pas un remplacement
  officiel Toyota.
- **Rien n'est enregistré.** Le modèle `OemNumberRelation` ne connaît que
  `manufacturer_supersession`, qui exige une source constructeur, et
  `distributor_replacement`. Aucune des deux ne décrit une équivalence. La
  seule voie possible serait `distributor_replacement` au niveau
  `aftermarket_crossref`. Elle affirmerait un sens de remplacement que les
  sources contredisent.
- Aucune compatibilité n'est transférée.
- **Décision proposée** : rattacher TB001 comme numéro de recherche de la
  YZZD2, sans relation, dans une PR séparée, si le métier le souhaite (§ 10).

## 7. Aucun héritage depuis la 90915-YZZD1 (point 7)

- Aucune relation ni aucune copie de ligne n'est créée.
- Aucune source officielle ne déclare la YZZD1 et la YZZD2 interchangeables :
  - le catalogue Boshoku (S1) ne cite **pas** la YZZD1 ;
  - les concessionnaires américains rattachent 90915-20001, -20003 et -20005
    à la YZZD1 ;
  - Boshoku et l'EPC (miroir) rattachent 90915-TB001, -20003 et -20005 aux
    véhicules de la YZZD2.
- Des revendeurs (mdparts, par exemple) croisent les deux familles. Cela,
  comme la proximité physique (§ 9), ne prouve aucune équivalence.
- Le test `test_aucune_relation_ni_heritage_depuis_la_yzzd1` le vérifie.

## 8. Description et jeu de référence (point 8)

- La description passe :
  - **de** « Visse, moteurs diesel 2KD-FTV 2.5, 1KD-FTV 3.0, 2GD-FTV 2.4,
    1GD-FTV 2.8 et certains 2TR-FE 2.7. »
  - **à** `Toyota90915Yzzd2PreuvesBoshoku::DESCRIPTION_APRES` : « Visse.
    Hilux, Fortuner et Prado hors Etats-Unis, surtout diesel (1KD-FTV,
    2KD-FTV, 1GD-FTV, 2GD-FTV), aussi essence 2TR-FE. » Suivent les sources
    (Boshoku, revendeurs, 3RZ-FE cité par des revendeurs), l'absence du numéro
    dans le catalogue Toyota US et les EPC consultés, et « verifier par VIN et
    par moteur ». Le 3RZ-FE n'est qu'une mention : aucune ligne n'est posée.
- La migration ne la change que si elle vaut encore le texte lu en production.
  Le `down()` ne la rétablit que si elle vaut encore le nouveau texte.
- `FiltresHuileBoutiqueSeeder` :
  - écrit le nouveau texte ;
  - **rejoue** `Toyota90915Yzzd2PreuvesBoshoku::run()` à la fin de son
    `run()`. Son `fitments()->delete()` effacerait sinon les preuves et les
    8 lignes ajoutées, comme pour Sienna.
- Sur une base neuve, les migrations antérieures qui appellent le seeder
  tournent avant la création des colonnes de preuve. L'appel ne fait alors
  rien, et la migration 230000 pose tout ensuite.
- `docs/qualification-preuves/inventaire-143-compatibilites.csv` : les
  6 lignes YZZD2 sont mises à jour. Les 8 lignes nouvelles ne font pas partie
  des 143 lignes inventoriées.

## 9. Caractéristiques physiques et fonctionnelles (point 9)

Aucune valeur n'est écrite en base : aucune source officielle Toyota ou
Boshoku ne publie les cotes de ces numéros.

| Caractéristique | 90915-YZZD2 | 90915-YZZD1 | Sources | Statut |
|---|---|---|---|---|
| Type | Visse (spin-on) | Visse (spin-on) | toutes | concordant |
| Filetage | 3/4"-16 UNF | 3/4"-16 UNF | revendeurs (mdparts, N-Parts ; airoilfilter, Advance Auto, AUTODOC) | non officiel |
| Diamètre | 74–75 mm | 74–79 mm selon le revendeur | idem | non officiel |
| Hauteur | 85 mm | 85–87 mm (OEZ : 3,41 in) | idem | non officiel |
| Joint | Ø 71,4 mm | 64 × 71 mm, ou 60,5 × 69,6 mm | idem | non officiel, divergent |
| Clapet antiretour / by-pass | non publié | anti-drainback oui ; by-pass ≈ 1 bar (équivalents) | revendeurs | non vérifié |
| Fabricant | « made by Denso » selon mdparts | Denso, selon un démontage publié sur un forum | revendeur / forum | non vérifié |

Conclusion : les enveloppes publiées par les revendeurs sont proches. Cela ne
fonde **aucune** équivalence YZZD1 ≡ YZZD2, et aucune relation n'est créée.

## 10. Lacunes et suites proposées

1. **Bornes 2020** des lignes Hilux AN120 2GD-FTV et Fortuner AN160 2GD-FTV :
   faut-il les ouvrir, puisque Boshoku et l'EPC sont ouverts ? Décision métier.
2. **Générations absentes** à créer, dans une PR de référentiel : Hiace (H200,
   H300), Innova (AN40, AN140), Alphard (H10). Les 9 couples Boshoku
   correspondants seraient ensuite posables.
3. **Couples visibles seulement dans l'EPC (miroir)** : Hilux 1TR-FE, et
   Hilux AN120 / Fortuner AN160 en 1KD-FTV ou 2KD-FTV. Ils sont à confirmer
   sur un EPC officiel.
4. **Toyota Indus** (distributeur Toyota au Pakistan) et **Nunawading Toyota
   Parts** (boutique d'un concessionnaire australien) pourraient servir de
   preuve `dealer_catalog`. Les libellés de moteur de Toyota Indus sont faux ;
   Nunawading n'a pas été relu par un humain. Contrôle humain avant tout usage.
   Rien n'est confirmé dans ce lot.
5. **90915-TB001** : faut-il le rattacher comme numéro de recherche, sans
   relation ? 90915-20005 est aussi rattaché à OS-004 par Boshoku (S2).
6. **Prado** : vérification par VIN, ou EPC officiel des marchés du Golfe et
   d'Afrique. Les périodes des revendeurs divergent (J120 : fin 07/2006 ou
   début 08/2006 ; J150 : fin 08/2015 ou 12/2023).
7. **3RZ-FE** (cité par les revendeurs australiens pour le RZJ120 et d'anciens
   Hilux) : **aucune ligne posée**. Il n'est ni dans Boshoku ni dans l'EPC
   consulté, et le référentiel n'a ni l'ancien Hilux (1997–2005) ni le Prado
   J90. À traiter après un EPC officiel et une PR de référentiel.
8. **TRJ150 (2TR-FE)** : citée par des revendeurs, absente de Boshoku : aucune
   ligne Prado essence n'est posée.
9. **Fortuner AN50, fin** : Boshoku donne 2015 et l'EPC 2016-11. La fin reste
   ouverte pour la ligne 2KD-FTV, alors que les lignes ajoutées (1KD-FTV,
   2TR-FE) sont bornées à 2015. À harmoniser par décision métier.
10. **Niveau `oem_supplier_catalog`** : voir la
   [note de conception](proposition-niveau-preuve-oem-supplier-catalog.md).

## 11. Vérifications (26/11/2026)

### Copie de la production

- Export lecture seule (SELECT), chargé dans PostgreSQL 18.6 local, schéma
  migré avec `main` 54f7b41.
- Empreinte `part_fitments` identique à la production
  (`6c72b9c6fc31b7298f0703856e968d1e`, 177 lignes, 130 migrations), 0 clé
  étrangère orpheline.

### Migration

| Contrôle | Résultat |
|---|---|
| `up()` | 4 lignes qualifiées, 8 ajoutées, 2 lignes Prado qualifiées (sources et note), description corrigée. Seules `part_fitments` et `parts` changent (empreintes). |
| `up()` rejoué | 0 écriture ; empreintes identiques, `updated_at` compris. |
| `down()` | Contenu identique à l'état d'avant, horodatages exclus (empreintes). |
| `down()` rejoué | 0 écriture. |
| `up()` après `down()` | Même contenu qu'après le premier `up()` ; seuls les identifiants des 8 lignes recréées changent. |

### Verdicts avant / après

Instantanés `instantane-verdicts` étendus, sur les mêmes échantillons :
- les 29 véhicules du parc et du garage ;
- 503 recherches catalogue ;
- pour chacune des 14 lignes YZZD2 : 6 marchés (JP, USA, AFR, EU, GCC,
  inconnu) × années (borne − 1, bornes, milieu, borne + 1, 2021) × moteur
  (celui de la ligne, aucun, 1GR-FE).

**333 changements, 0 non attendu, tous sur la 90915-YZZD2.** La production a été relue avant ce rejeu : empreinte identique, 177 lignes, 130 migrations.

| Portée | Changement | Règle | Nombre |
|---|---|---|---|
| parc / garage | — | — | **0** (aucun Hilux, Fortuner ni Prado) |
| catalogue | exclu → candidat | nouvelles_lignes_yzzd2 | 13 |
| catalogue | candidat → candidat (détail) | detail_seul | 2 |
| Fortuner AN50 2KD-FTV, années avant 2005 | candidat → exclu | debut_2005_fortuner_an50 | 24 |
| échantillons marchés | exclu → candidat | nouvelles_lignes_yzzd2 | 294 (49 par marché) |
| Prado (tous marchés), 1GR-FE, autres pièces | — | — | **0** : les lignes Prado restent candidates |

- Aucun verdict ne devient « confirmé ».
- Aucun verdict ne dépend du marché : les 6 marchés donnent le même résultat.
- Les « exclu → candidat » sont :
  - les couples ajoutés (2KD-FTV, 1GD-FTV, 2TR-FE…) ;
  - les années ouvertes des générations AN120 et AN160 (2021 et au-delà), sans
    code moteur ou avec le moteur d'une ligne ajoutée.

### Suites de tests

Limites PHP de la production : `memory_limit=192M`, `upload_max_filesize=9M`,
`post_max_size=24M`.

| Base | Tests | Assertions | Échecs | Ignorés |
|---|---|---|---|---|
| SQLite (mémoire) | 2071 | 16273 | 0 | 0 |
| PostgreSQL 18.6 | 2071 | 16273 | 0 | 0 |

Les tests nouveaux sont dans `tests/Feature/Toyota90915Yzzd2PreuvesBoshokuTest.php`
(20 tests). Deux comptes exacts ont été mis à jour, et non relâchés :
- `FiltresHuileBoutiqueTest` passe de 95 à 103 lignes ;
- `GenerationsTacomaYarisTest` ajoute les 8 couples reposés par le seeder.
