# Marques et modèles d'engins : proposition

**Date :** 24 octobre 2026
**Objet :** quelles machines entrer au référentiel pour servir les onze filtres poids lourds en stock.
**Statut :** proposition à arbitrer. **Rien n'a été écrit en base.**

---

## Ce qu'il faut savoir avant de lire la suite

Le document d'origine a été vérifié famille par famille, puis chaque vérification a été contestée. **Trois contre-expertises sur quatre ont conclu que la recherche ne tenait pas.** Ce document retient ce qui a survécu aux deux passes.

Et la conclusion principale n'est pas une liste de modèles. C'est ceci :

> **Le document propose un parc qui n'est pas celui du Burkina Faso, et les onze filtres en stock ne correspondent pas non plus au parc burkinabè neuf.**

Les deux affirmations sont étayées plus bas. Elles ne condamnent pas le lot — elles disent dans quel ordre travailler.

---

## 1. Ce que le marché burkinabè dit réellement

Seuls éléments burkinabè que la recherche a pu ouvrir :

| Fait établi | Source ouverte |
|---|---|
| **Diacfa Automobiles**, principal distributeur poids lourds de Ouagadougou, représente **Renault Trucks, Eicher, SinoTruk et Yutong** | financialafrik.com, 02/2019 |
| **135 Shacman H3000** livrés aux forces armées burkinabè, fin 2024 – début 2025 | military.africa, defensemirror |
| **530 autobus Scania** pour la SOTRACO à Ouagadougou, livrés de 02/2025 à 01/2026 | burkina24.com |
| Parc agricole d'État en **Foton Lovol**, assemblé à **Bobo-Dioulasso** par Tropic Agro Chem — environ 1 400 tracteurs subventionnés | presse nationale, 2019-2024 |
| Parc agricole privé ouest-africain surtout en **Massey Ferguson série 300** assemblés au Pakistan (MF 240, 350, 360, 375, 385) | dépôts financiers Millat Tractors |

**Aucune trace burkinabè de DAF, de Mercedes-Benz Trucks, de Dongfeng, ni de Setra.**

---

## 2. Ce que je recommande de ne PAS entrer, et pourquoi

### Les Dongfeng EQ du document — à écarter

`EQ1218G`, `EQ1228V2`, `EQ1230V2`, `EQ1242G`. Le préfixe EQ est bien celui de Dongfeng, et la nomenclature chinoise de 1989 décode « EQ + 1 + tonnage » — ce qui est précisément le piège : **n'importe quel code à quatre chiffres paraît crédible sous cette règle.** Aucune recherche, y compris en chinois, n'a trouvé une page nommant ou datant ces quatre codes. Le suffixe « V2 » n'est attesté nulle part.

Ce sont selon toute vraisemblance des **libellés d'application recopiés d'un catalogue de filtres**, pas des désignations constructeur. Le chercheur et le sceptique sont d'accord. Les vraies gammes export Dongfeng s'appellent KL/Tianlong, KR/Kingrun, KC.

### Setra, DAF 65, Cat 527, JCB 3C et 3D — à écarter

Aucune trace burkinabè pour les deux premières. Le débardeur forestier Cat 527 n'a **aucune année de production trouvable** et son moteur 3304 DIT ne prend aucun filtre du stock — un pays sahélien sans filière bois mécanisée. Les JCB 3C et 3D ont plus de 45 ans et leur seule source est un forum d'amateurs.

### Les moteurs nus — jamais en `vehicle_models`

Cat C4.4/C6.6/C7.1, Perkins 1004/1006/1103/1104/1106, John Deere PowerTech 3029/4045/6068, Cummins 6BTA5.9/6CTA8.3. **Un moteur n'est pas une machine : c'est la clé de compatibilité**, et sa place est `part_fitments.engine_code`, qui est une chaîne libre. Leurs fabricants restent dans `manufacturers`.

---

## 3. La question d'architecture est tranchée, et ma prémisse était fausse

J'avais écrit que `vehicles.vehicle_type` étant un enum `passenger | utility | heavy`, un groupe électrogène n'avait pas de case et qu'il faudrait choisir entre trois mauvaises options.

**C'est faux, et la vérification le montre.** Cet enum est celui d'une **annonce de vente** (table `vehicles`). La fiche de référence porte `vehicle_models.default_vehicle_type`, qui est un `string(20)` **nullable**, pas un enum.

> Entrer un groupe électrogène comme `vehicle_model` avec ce champ à `null` ne touche **aucun** enum et ne coûte **aucune** migration. L'enum ne mord que le jour où quelqu'un met un groupe **en vente**, pas quand il vend un filtre **pour** un groupe.

L'option « rattacher les filtres au moteur par une autre table » est d'ailleurs fermée par le schéma : `part_fitments.vehicle_model_id` est NOT NULL.

**Donc** : entrer l'unité (le groupe, la machine), jamais le moteur ; `generation` à null quand on n'a qu'un code de configuration ; le code moteur va dans `engine_code`.

---

## 4. Ce que je propose d'entrer, par ordre de valeur

### Lot A — les groupes électrogènes Caterpillar *(le plus vendeur)*

C'est la famille où **deux filtres du stock couvrent quatre machines**, et Caterpillar les nomme lui-même dans la table de compatibilité du 435-6493.

| Marque | Modèle | Moteur | Confiance |
|---|---|---|---|
| Caterpillar | DE150 GC *(50 Hz)* | C7.1 | vérifié |
| Caterpillar | DE165 GC *(50 Hz)* | C7.1 | plausible-haut |
| Caterpillar | DE200 GC *(50 Hz)* | C7.1 | vérifié |
| Caterpillar | DE220 GC *(50 Hz)* | C7.1 | vérifié |

**La fréquence fait partie du modèle.** Caterpillar en fait deux produits distincts, et la version 60 Hz (1800 tr/min, 220-480 V) **ne se branche pas sur le réseau burkinabè**. Une ligne sans fréquence est une ligne fausse.

Le kit d'entretien **610-3589 est confirmé** : « C7.1 PM Kit Filters », contenant 130-4678 (air, ×1), 435-6493 (carburant, ×2) et 441-5111. Deux de ces références sont déjà en stock ; les deux autres sont des achats futurs identifiés.

**Et une piste que le document a manquée : FG Wilson.** C'est la marque de groupes de Caterpillar (NI) Ltd, à moteurs **Perkins** — et la référence Perkins 26560145 du stock est précisément donnée pour les « FG Wilson 1300 Series ». C'est la famille qui répond aux **trois** filtres Perkins. Candidats : P110-3, P150-5, P165-5, P200-3. À instruire avant d'écrire : les dates ne sont pas établies.

### Lot B — les engins de chantier, lignes « marchés émergents »

Le document propose la mauvaise moitié du catalogue. Les deux seules machines vérifiables sur sources Caterpillar officielles sont celles qu'il ne cite pas ou cite mal :

| Marque | Modèle | Pourquoi celle-là |
|---|---|---|
| Caterpillar | **950 GC** | Communiqué CAT Afrique/Moyen-Orient 04/2014 : C7.1 Stage IIIA, **injection conçue pour les carburants des marchés émergents** |
| Caterpillar | **140 GC** | Communiqué 132PR19, 03/2019, explicitement « For Release in: Africa, Middle-East, Turkey » |
| JCB | **3CX** | la tractopelle la plus répandue au monde |
| JCB | **3DX** *(ligne indienne, Ballabgarh)* | c'est elle qui alimente l'Afrique de l'Ouest |

**`GC` n'est pas une génération.** C'est une *ligne de produit* parallèle — « General Construction », bas coût horaire, Tier 3/Stage IIIA. Et l'ordre des lettres n'est pas chronologique d'une famille à l'autre : chez les niveleuses, la série **K (2010) est postérieure à la série M (2006)**, alors que chez les chargeuses G→H→K→M l'est bien. **Un champ `generation` rempli naïvement par la lettre produit un classement faux.**

À l'inverse, les 930M, 963K et M318F sont Tier 4 Final à FAP/AdBlue — **mal adaptées au gazole sahélien**.

### Lot C — l'agricole, et c'est là que ça se complique

| Marque | Statut |
|---|---|
| **Foton Lovol** | le parc d'État réel. ~1 400 tracteurs, assemblés à Bobo-Dioulasso |
| **Massey Ferguson** série 300 pakistanaise | le parc privé réel : MF 240, 260, 350, 360, 375, 385 |
| John Deere, McCormick | second rang — pour les occasions d'importation |
| Valtra / Valmet | à ne pas entrer |

**L'avertissement commercial, et il est sérieux :** les Foton Lovol burkinabè ont des moteurs **Doosan, Xinchai ou Weichai**. **Aucun des onze filtres en stock ne s'y rattache.** Les onze filtres visent un parc John Deere / Perkins / CAT / JCB **d'occasion importée**, pas le parc neuf subventionné.

Deux pièges de nommage à tenir, sous peine de refaire l'erreur du catalogue Toyota :

- **Collision de noms.** Un « MF 375 » désigne **deux machines** : le 300 Series anglais (Coventry, 1987-1997, Perkins 3,9 L, 67 ch) et le pakistanais de 75 ch. Idem pour MF 360, MF 385, et pour le John Deere 5310 (américain *et* indien). **Deux machines, deux filtres.**
- **McCormick MTX.** La 1ʳᵉ génération (MTX 110/125/140, Doncaster, 2000-2003) est bien à moteur **Perkins 1006-60T** — ce qui valide le rattachement du 26560145. Mais la **Série II** (MTX 120/135/150, 2004-2007) est à moteur **Iveco/FPT**. Y poser un filtre Perkins serait une erreur de compatibilité.

### Lot D — les camions, à instruire avant d'écrire

Priorité renversée par rapport au document : **Sinotruk/HOWO, Shacman, Renault Trucks (gammes K et D), Yutong, Scania** — tous justifiés par le marché burkinabè, indépendamment des onze filtres.

Deux remarques qui ne coûtent **aucune marque nouvelle** :

- **Toyota Coaster et Dyna.** `toyota` est déjà au référentiel, CFAO Motors Burkina en est distributeur exclusif depuis 1973, et le Coaster est le minibus standard de la région. C'est la lacune la plus nette du travail.
- **Mitsubishi Fuso Canter, Isuzu NPR/FTR, Nissan Atleon** — ces trois marques existent déjà. Mais aucune preuve burkinabè : **à trancher avec vous, pas par une recherche web.**

Et une piste qui vaut un appel téléphonique plutôt qu'une recherche : **la STAF** exploite **219 autobus** au Burkina, chiffre public et daté. Aucune source ne nomme la marque. 219 autobus civils en exploitation valent commercialement plus que 135 camions militaires sous garantie.

---

## 5. Sur Mercedes-Benz et Ford, qui existent déjà

**Ne pas scinder la marque.** Un camion SK peut cohabiter avec une Classe C : `vehicle_models` est unique sur `(brand_id, slug)`. Ce qui se dégrade est ailleurs — le sélecteur mélangerait Classe C, SK et Actros dans une liste de 254 modèles, et la colonne `segment` (A à F) n'a aucun sens pour un camion.

**La solution existe déjà dans le schéma** : filtrer le sélecteur sur `vehicle_models.default_vehicle_type`. Argument supplémentaire contre la scission : `brands.oem_prefix` sert au décodage des numéros OEM, et Mercedes utilise la même numérotation pour camions et voitures — deux marques porteraient le même préfixe.

**Ford Cargo : jamais une seule ligne.** Trois productions sur trois continents sans moteurs communs — Langley (RU) 1981-1993, Brésil 1981-2019, Turquie (Ford Otosan) jusqu'à aujourd'hui. Une ligne unique couvrirait 1981 à aujourd'hui avec des motorisations incompatibles : **exactement l'erreur — intervalle de trente ans, motorisations mélangées — qui a fait refuser quinze lignes du catalogue Toyota.**

---

## 6. Ce que la contre-expertise a attrapé, et qu'il faut retenir

**Une erreur de compatibilité dangereuse.** La recherche attribuait un moteur **C7.1** aux niveleuses Cat 140M2 et 140M3. Elles ont un **C9.3**. C'est précisément ce moteur imaginaire qui servait ensuite à justifier le rattachement des filtres C7.1 du stock. Un filtre C7.1 posé sur une machine à C9.3, c'est la faute que ce projet a déjà refusé quinze fois.

**Et une correction à mon propre code, déjà poussé.** La description du CAT 462-1171 que j'ai écrite annonçait « moteurs C4.4 / C6.6 / C7.1 selon configuration ». Cette liste vient du document source, **n'est pas confirmée par Caterpillar**, et appartiendrait à une autre référence. Je l'ai retirée.

---

## 7. Ce que je ferais, dans cet ordre

1. **Lot A** — les quatre groupes Cat DE…GC en 50 Hz. Deux filtres du stock, quatre machines, et Caterpillar les nomme lui-même.
2. **Instruire FG Wilson** — c'est la famille des trois filtres Perkins, et la plus proche d'une vente.
3. **Lot B** — 950 GC, 140 GC, 3CX, 3DX. Quatre lignes, toutes justifiées par un communiqué « marchés émergents ».
4. **Lot C** — Foton Lovol et Massey Ferguson 300 d'abord, en traitant les collisions de noms.
5. **Lot D** — après un échange avec vous sur ce qui passe réellement par votre atelier.

**Et une question qui décide de beaucoup** : ces onze filtres, les avez-vous achetés pour un parc que vous connaissez — des clients qui ont déjà ces machines — ou sont-ils un pari sur un marché à ouvrir ? La réponse change l'ordre : dans le premier cas, c'est vous qui nommez les engins et aucune recherche web ne vaudra votre carnet.
