# Référentiel Toyota Sienna : restructuration

> **Statut** : décision du propriétaire du 26/11/2026, mise en œuvre sur la
> branche `feat/referentiel-sienna` (migration
> `2026_11_26_170000_donner_ses_generations_a_la_sienna`, classe
> `App\Support\ToyotaSiennaReferentielImport`). La Sienna XL10 vient de la
> PR #41, d'où l'ordre de fusion : **#41, puis cette PR**.

## 1. Audit des dépendances de la ligne nue `sienna`

Lecture seule en production le 26/11/2026 : id 18657, génération vide,
production 2007 à ouvert, active, sans alias.

| Table (clé étrangère, règle de suppression) | Lignes liées | Effet de la désactivation |
| --- | --- | --- |
| `vehicles` (RESTRICT) | 0 | aucun ; s'il y en avait, la ligne resterait active (garde dans le code) |
| `owned_vehicles` (RESTRICT) | 0 | idem |
| `trims` (CASCADE) | 0 | aucun |
| `part_fitments` (CASCADE) | 4 | remplacées par des lignes réévaluées sur les générations, puis retirées et archivées (PR suivante, voir le §4) |
| `oem_number_fitments` (CASCADE) | 0 | aucun |
| `accessory_fitments` (CASCADE) | 0 | aucun |
| `motorisations` (SET NULL) | 86 (nrcan, Canada, 2007–2026 ; 43 en `model_year`, 43 en `unknown`) | 84 réattribuées aux générations, 2 ambiguës restées sur la ligne nue ; voir le §5 |
| `customer_needs`, `private_vehicle_listings` (SET NULL) | 0 / 0 | aucun |

Aucune ligne n'est supprimée et rien n'est renommé. Seuls changent
`is_active` (passé à false) et les bornes (vidées), comme pour `tacoma` et
`venza`. Des bornes « 2007-ouvert » chevaucheraient XL20, XL30 et XL40, et
`ImportConsommations` lirait la ligne nue avant elles.

**Ce que fait l'inactivité, selon le code** :

- `/api/catalog/brands/{id}/models` filtre `is_active` ;
- `/api/catalog/sync` continue d'envoyer la ligne avec `is_active=false`,
  et l'application mobile masque les modèles inactifs (`ModelRef.isActive`,
  sélecteurs d'enregistrement), comme elle le fait déjà pour `tacoma` et
  `venza` ;
- le décodage VIN ne retient que les marques actives et départage les
  générations par l'année ;
- les compatibilités ne filtrent pas `is_active` : une requête qui vise
  explicitement l'id de la ligne nue lisait encore ses 4 lignes, d'où leur
  retrait (§4). Comme plus aucun sélecteur ne la propose, plus aucun nouveau
  véhicule n'y sera rattaché.

## 2. Générations (EPC Toyota, marché USA, années de fabrication)

| Slug | Production | Châssis | Catalogue EPC |
| --- | --- | --- | --- |
| `sienna-xl10` (PR #41) | 1997–2002 | MCL10 | 622410 |
| `sienna-xl20` | 2003–2009 | MCL20/23/25 (3MZ-FE, 01/2003–12/2006), GSL20/23/25 (2GR-FE, 12/2006–12/2009) | [622420](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622420/info) |
| `sienna-xl30` | 2010–2020 | ASL30 (1AR-FE), GSL30/33/35 (2GR-FE puis 2GR-FKS) | [622430](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622430/info) |
| `sienna-xl40` | 2020– | AXLH40/45 (A25A-FXS hybride) | [622440](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622440/info) |

Wikipedia ne sert que de recoupement. 2020 est une année partagée réelle :
XL30 jusqu'en 09/2020, XL40 à partir de 11/2020.

## 3. Compatibilités : décision ligne par ligne

Le constructeur est le [catalogue Toyota des filtres 2025-2026](https://www.toyotapartsandservice.com/wp-content/uploads/2024/10/06_OilFilters2025_hi.pdf),
en années-modèle américaines. La période vient de l'EPC, en années de
fabrication.

| Ligne nue (retirée depuis, voir le §4) | Décision | Nouvelle(s) ligne(s) | Preuve |
| --- | --- | --- | --- |
| 04152-YZZA1, 2GR-FE, 2007–2016 | à cheval sur XL20 et XL30 : **non dupliquée**, découpée selon l'EPC | XL20 GSL20/23/25 **2006–2009** ; XL30 GSL30/33/35 **2010–2016** | PDF « 2007-2010 » et « 2011-2016 V6 (2GRFE) → 04152-YZZA1 » ; EPC [622420 g/04152](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622420/g/04152) (dès 12/2006), [622430 f/151714B](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622430/f/151714B?pnc=04152) (01/2010–08/2016) ; schéma [GSL20L-PRADKK](https://www.toyodiy.com/parts/p_U_200612_TOYOTA_SIENNA_GSL20L-PRADKK_1502.html) |
| 04152-YZZA1, 1AR-FE, 2011–2013 | rattachée à XL30, période EPC | XL30 ASL30 **2010–2013** | PDF « 2011-2013 L4 (1ARFE) » ; EPC 622430 : 05/2010–08/2013 |
| 04152-YZZA1, 2GR-FKS, 2017–2020 | rattachée à XL30, période EPC | XL30 GSL3# **2016–2020** | PDF « 2017-2020 V6 (2GRFKS) » ; EPC 622430 : 08/2016–09/2020 ; schéma [GSL30L-PRZEHA](https://www.toyodiy.com/parts/p_U_201608_TOYOTA_SIENNA_GSL30L-PRZEHA_1502.html) |
| 90915-YZZN1, A25A-FXS, 2021– | rattachée à XL40, période EPC | XL40 AXLH40/45 **2020–** | PDF « 2021- L4 (A25AFXS) → 90915-YZZN1 » ; EPC [622440 g/15601](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622440/g/15601) (90915-10009 dès 11/2020) |
| — | **nouvelle**, confirmée par l'EPC | 90915-YZZD1 XL20 3MZ-FE, MCL20/23/25, **2003–2006** | PDF « 1998-2006 V6 (1MZFE, 3MZFE) → 90915-YZZD1 » ; EPC [622420 g/15601](https://toyota-epc.aftermarketcatalog.com/vehicle/US/622420/g/15601) (90915-20003 dès 01/2003) ; schéma [MCL20L-PRAEKK](https://www.toyodiy.com/parts/p_U_200301_TOYOTA_SIENNA_MCL20L-PRAEKK_1502.html) |

Remplacements cités :

- 04152-31090 → 04152-YZZA1 est publié par Toyota
  ([fiche officielle](https://autoparts.toyota.com/products/product/replaceable-element-oil-filter-04152yzza1),
  « Previous Version(s) ») ;
- 90915-20003 → 90915-YZZD1 n'est attesté que par les catalogues de
  distribution Toyota. Pour la Sienna, le PDF constructeur donne YZZD1
  directement ;
- 90915-10009 → 90915-YZZN1 n'est attesté que par les catalogues de
  distribution. Le PDF constructeur donne YZZN1 directement.

`FiltresHuileBoutiqueSeeder` efface et réécrit les compatibilités de la YZZA1
et de la YZZN1 à chacune de ses exécutions. Il repose donc les lignes Sienna
de ces deux références depuis `ToyotaSiennaReferentielImport`, leur seule
définition. La YZZD1 y est marquée `compatibilites_a_verifier`, et le seeder
la laisse intacte.

## 4. Retrait des 4 lignes de la ligne nue (décision du 26/11/2026)

Migration `2026_11_26_180100_retirer_les_compatibilites_de_la_sienna_nue`
(`SiennaLigneNueCompatibilitesRetrait`). Chaque ligne est retrouvée par SKU,
slug `sienna`, code moteur et bornes, jamais par identifiant. Elle n'est
retirée que si **toutes** ses remplaçantes existent et si leurs bornes
réunies couvrent les siennes. Sinon, elle reste, et le rapport de migration
le dit. Avant suppression, la ligne complète (JSON) et sa correspondance sont
copiées dans `part_fitment_archives`. Le `down()` recrée les lignes depuis ce
journal. Les mêmes 4 lignes sont retirées de `FiltresHuileBoutiqueSeeder`,
sans quoi un rejeu les reposerait.

| Ligne retirée (id prod., pour mémoire) | Remplacée par | Couverture |
| --- | --- | --- |
| 04152-YZZA1 · sienna · 2GR-FE · 2007–2016 (456) | 04152-YZZA1 · sienna-xl20 · 2GR-FE · 2006–2009 + sienna-xl30 · 2GR-FE · 2010–2016 | 2007–2016 ⊂ 2006–2016 |
| 04152-YZZA1 · sienna · 1AR-FE · 2011–2013 (457) | 04152-YZZA1 · sienna-xl30 · 1AR-FE · 2010–2013 | ⊂ |
| 04152-YZZA1 · sienna · 2GR-FKS · 2017–2020 (458) | 04152-YZZA1 · sienna-xl30 · 2GR-FKS · 2016–2020 | ⊂ |
| 90915-YZZN1 · sienna · A25A-FXS · 2021– (340) | 90915-YZZN1 · sienna-xl40 · A25A-FXS · 2020– | ⊂ |

## 5. Réattribution des 86 motorisations nrcan

Migration `2026_11_26_180200_reattribuer_les_motorisations_de_la_sienna`
(`SiennaMotorisationsReattribution`). Le rapport ligne par ligne est dans
[`docs/rapports/sienna-motorisations-2026-11-26.csv`](rapports/sienna-motorisations-2026-11-26.csv).

Les cotes nrcan ne portent **ni code moteur, ni châssis, ni type
d'hybride**. Elles donnent l'année (année-modèle canadienne quand
`year_kind = model_year`), la cylindrée, les cylindres, la boîte, le
carburant et le libellé. Une règle n'est retenue que si ces critères
désignent une seule combinaison génération + moteur dans les sources
constructeur. Aux années de transition, c'est la **boîte** qui tranche :

| Règle | Critères nrcan | Génération · moteur · châssis (EPC) | Sources |
| --- | --- | --- | --- |
| xl20-2gr-fe-a5 | 3,5 l · 6 cyl. · A5 · AM 2007–2010 | XL20 · 2GR-FE · GSL20/23/25 (12/2006–12/2009) | EPC 622420 ; [Toyota USA, Sienna 2010 en 5 rapports](https://pressroom.toyota.com/sienna-a-reputation-for-family-comfort-utility/) ; [la 2011 est la 3e génération, 6 rapports](https://pressroom.toyota.com/toyota-introduces-all-new-2011-toyota-sienna-minivan/) |
| xl30-1ar-fe-as6 | 2,7 l · 4 cyl. · AS6 · AM 2011–2013 | XL30 · 1AR-FE · ASL30 (05/2010–08/2013) | EPC 622430 ; [Toyota USA, tarifs 2011](https://pressroom.toyota.com/toyota-announces-pricing-for-all-new-2011-sienna-minivan/) |
| xl30-2gr-fe-as6 | 3,5 l · 6 cyl. · AS6 · AM 2011–2016 | XL30 · 2GR-FE · GSL30/33/35 (01/2010–08/2016) | EPC 622430 ; même source |
| xl30-2gr-fks-as8 | 3,5 l · 6 cyl. · AS8 · AM 2017–2020 | XL30 · 2GR-FKS · GSL30/33/35 (08/2016–09/2020) | EPC 622430 ; [Toyota Canada, Sienna 2017 en 8 rapports](https://media.toyota.ca/en/releases/2016/a-van-for-all-seasons--and-all-reasons-the-2017-toyota-sienna.html) |
| xl40-a25a-fxs-av | 2,5 l · 4 cyl. · AV · AM 2021–2026 | XL40 · A25A-FXS hybride · AXLH40/45 (dès 11/2020) | EPC 622440 ; [Toyota USA : 2021 hybride sur toutes les versions](https://pressroom.toyota.com/2021-toyota-sienna-hits-dealerships-this-november/) |

Quand la nature de l'année est inconnue (`year_kind = unknown`, 43 lignes
`legacy`), l'année est lue des deux façons, année-modèle et année de
fabrication. La cote n'est réattribuée que si les deux lectures désignent
la même génération.

**Résultat sur les 86 cotes de production (lues le 26/11/2026) :**

| Décision | Nombre | Détail |
| --- | --- | --- |
| Réattribuées | 84 | XL20 : 14 · XL30 : 46 · XL40 : 24 |
| Ambiguës (restent sur `sienna`, à valider à la main) | 2 | 24780 « Sienna » et 24781 « Sienna 4WD » : 2010, A5, nature de l'année inconnue. En année-modèle, c'est une XL20 ; en année de fabrication, la XL20 n'est plus produite en 2010 |
| Non réattribuées | 0 | — |
| Dupliquées par la migration | 0 | aucune cote copiée |
| Doublons préexistants | 43 paires | chaque ligne `legacy` répète une ligne `documented` (même année, libellé, cylindrée, boîte, consommation) ; signalés dans la colonne `doublon_de` du CSV |

Pourquoi rien n'est supprimé ni copié :
- seule `vehicle_model_id` change, et la décision est journalisée dans
  `motorisation_generation_assignments` (règle, moteur et châssis déduits,
  sources) ;
- les doublons `legacy` relèvent de la logique de révision de l'import
  (`is_current`) et peuvent être choisis par un véhicule de garage
  (`owned_vehicles.motorisation_id`). Les supprimer n'est pas sûr sans
  revue ;
- le moteur et les châssis déduits ne sont **pas** recopiés dans
  `motorisations.engine_code` : ils restent à valider avant de servir de
  critère ;
- une cote liée à une finition, vérifiée ou choisie par un véhicule de
  garage n'est pas déplacée (aucune en production).

`catalog:import-consommations` (rattachement par nom et année) ignore
désormais les cotes journalisées. Sans cette garde, sa règle d'années
poserait la 2010 A5 sur la XL30 (2010–2020), et la 2020 AS8 deviendrait
ambiguë (XL30 et XL40 couvrent 2020).

## 6. Laissé en l'état, à décider

- **Les 2 cotes ambiguës** (24780, 24781) : à valider à la main. Proposition :
  XL20, puisque NRCan publie des années-modèle et que la boîte A5 n'existe que
  sur la XL20.
- **24 cotes nrcan « Sienna » 1998–2006 sans modèle** (`vehicle_model_id`
  NULL : 3,0 l A4 et 3,3 l A5) : hors périmètre. Point de vigilance : la règle
  d'années de l'import poserait l'année-modèle 2003 (3,0 l A4, encore une
  XL10) sur la XL20 (fabrication 2003–2009). Il faudrait la même méthode par
  boîte.
- **Nouvelles révisions NRCan** : une nouvelle cote, avec un nouvel
  identifiant, serait rattachée par la règle d'années, avec le même risque
  en 2010 et en 2020.
- **Marchés hors USA** : les compatibilités restent « marché USA ». Les cotes
  sont canadiennes (`market = CA`) : même usine de fabrication (TMMI,
  Indiana) et mêmes générations, et Toyota Canada confirme la boîte 8
  rapports en 2017.
