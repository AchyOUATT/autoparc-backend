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
| `part_fitments` (CASCADE) | 4 | conservées, intactes, sur une ligne inactive ; remplacées par des lignes réévaluées sur les générations |
| `oem_number_fitments` (CASCADE) | 0 | aucun |
| `accessory_fitments` (CASCADE) | 0 | aucun |
| `motorisations` (SET NULL) | 86 (nrcan, Canada, 2007–2026 ; 43 en `model_year`, 43 en `unknown`) | conservées sur la ligne nue ; voir le §4 |
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
  explicitement l'id de la ligne nue lirait encore ses 4 lignes. Comme plus
  aucun sélecteur ne la propose, plus aucun nouveau véhicule n'y sera
  rattaché.

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

| Ligne nue (conservée) | Décision | Nouvelle(s) ligne(s) | Preuve |
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

## 4. Laissé en l'état, à décider

- **Motorisations nrcan (86)** : elles restent rattachées à la ligne nue.
  Les déplacer vers XL20, XL30 ou XL40 serait modifier des données
  existantes. Tant que ce n'est pas fait, une Sienna enregistrée sur une
  génération ne se voit proposer aucune de ces cotes de consommation.
  Proposition : rattacher chaque ligne `model_year` N à la génération qui
  couvre la fabrication N-1 ou N, et les lignes `unknown` d'après leur
  cylindrée (2,7 l → XL30 ; 2,5 l → XL40 ; 3,5 l → XL20 ou XL30 selon
  l'année). Les cas ambigus de 2010 et 2020 restent sur la ligne nue.
- **Les 4 lignes de la ligne nue** : conservées. Leur suppression n'est pas
  proposée sans accord explicite.
- **Marchés hors USA** : non couverts par ces lignes. La note de chaque
  ligne précise « marché USA ».
