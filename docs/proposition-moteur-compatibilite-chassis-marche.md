# Moteur de compatibilité : châssis, marché de destination et mois de fabrication

Proposition technique et mise en œuvre (PR séparée, indépendante des PR #40 et #41).
Mesures en production faites le 26/11/2026 (UTC+0), en lecture seule.

## 1. Architecture actuelle

### Chemins de calcul

| Entrée | Code | Ce qui est comparé |
|---|---|---|
| `GET /api/catalog/compatible-parts` | `CompatibilityController::partsForCriteria` → `CompatibilityService::partsForCriteria` puis `PartCompatibilityAssessment` | modèle, année, finition, motorisation, transmission, code moteur |
| `GET /api/my/vehicles/{id}/compatible-parts` | `partsForOwnedVehicle` + `ownedVehicleCriteria` | idem, valeurs du véhicule du garage |
| `GET /api/vehicles/{id}/compatible-parts` | `partsForVehicle` + `vehicleCriteria` | idem, véhicule du parc |
| `GET /api/my/parts/{part}/garage-compatibility` | `GarageCompatibilityController` → `assess()` | idem, pour chaque véhicule du garage |
| `GET /api/oem/{n}/vehicles` | `CompatibilityService::vehiclesForOem` | idem, vers les véhicules du parc |

Deux étages :

1. un pré-filtre SQL (`whereHas` sur `part_fitments` et `oem_numbers.fitments`) qui écarte les lignes incompatibles ;
2. `PartCompatibilityAssessment::assess()` qui qualifie chaque pièce : `confirmed` (toutes les contraintes d'une ligne déclarée sont satisfaites), `candidate` (il manque une information : `missing_criteria`), `unknown` (aucune ligne applicable). Sans `include_candidates=1`, `constrainConfirmed()` ne garde que les confirmées, avant la pagination.

Règle historique : une colonne vide d'une ligne ne restreint rien ; une colonne renseignée et inconnue côté véhicule donne `candidate` ; une valeur différente exclut la ligne.

### Ce qui n'était pas comparé

- `part_fitments.chassis_code` (ajouté le 19/11/2026) : seulement affiché (`PartResource`, `CompatibilitesPourApercu`). Les véhicules n'avaient aucun champ châssis. `oem_number_fitments` n'avait pas la colonne.
- Marché de destination : seulement dans `notes` (« marché Japon (JP) », « marché USA »). `owned_vehicles.specification_market` existait (texte libre, utilisé par les consommations) mais n'était pas lu par le moteur de compatibilité. `vehicles` n'avait pas de marché.
- Mois : les bornes sont des années entières ; les mois EPC (« 11/2009–08/2011 ») ne vivaient que dans les notes.
- Remplacements de références : `oem_numbers.is_superseded` + `superseded_by_id`, sans distinguer une supersession constructeur d'un remplacement attesté par un concessionnaire.

## 2. Conception

### Principes

1. **Null = aucune restriction.** Toutes les nouvelles colonnes sont nullables ; les 143 lignes en production gardent exactement leur comportement.
2. **Restriction explicite seulement.** Un code châssis ne devient restrictif que si la ligne porte `chassis_restricted = true`. Le `chassis_code` actuel reste informatif tant que personne ne l'a décidé ligne par ligne.
3. **Inconnu → `candidate`, jamais `confirmed`.** Incompatible → la ligne est exclue (les autres lignes de la pièce peuvent encore confirmer).
4. **Une seule implémentation de la règle.** Les critères nouveaux sont jugés en PHP (`App\Services\FitmentRestrictions`). Les requêtes SQL reçoivent uniquement les identifiants des lignes restrictives à écarter (`whereNotIn`). Seules les lignes restrictives du modèle demandé sont chargées : aujourd'hui, aucune.
5. **Rien n'est déduit du code moteur.** Le châssis vient de la saisie, ou du préfixe d'un numéro de châssis japonais (`NZE121-3123456` → `NZE121`). Un VIN à 17 caractères n'est jamais décodé en châssis.

### Châssis (`App\Support\ChassisCode`)

- Normalisation : majuscules, sans espaces. Une ligne peut lister plusieurs codes : `TRN280/TRN285`, `MCV30, MCV36`.
- Côté véhicule, trois formes sont comparées : le code complet (`ACV30L-AEPNKA`), la partie avant le tiret (`ACV30L`) et la base (`ACV30`, sans les lettres finales).
- **Variantes** : `TRN280` et `TRN285` sont deux codes distincts. L'un ne vaut pas l'autre.
- **Familles** : seulement si la ligne les déclare avec une étoile (`TRN28*`, `MCV3*`).
- **Véhicule moins précis que la règle** (règle `ACV30L`, véhicule `ACV30`) → `candidate` (`chassis_code` dans `missing_criteria`).

| Règle | Véhicule | Résultat |
|---|---|---|
| `TRN280/TRN285` | `TRN285` ou `TRN285L-GKPSKA` | satisfait |
| `TRN280/TRN285` | `GRN285` | exclue |
| `TRN280/TRN285` | inconnu | candidate |
| `MCV3*` | `MCV36` | satisfait |
| `MCV30` | `MCV36` | exclue |
| `ACV30L` | `ACV30` | candidate |

### Marchés (`markets` + `App\Support\Market`)

Table `markets` (code, nom, `epc_region`, ordre). **Aucune hiérarchie** (revue du 26/11/2026, voir section 11) : faute de documentation constructeur, aucun marché n'en couvre un autre.

| Code | Nom | `epc_region` (informatif) |
|---|---|---|
| JPN | Japon | JPN |
| EUR | Europe | EUR |
| GEN | Général | GEN |
| USA | États-Unis | USA |
| CAN | Canada | — |
| MDE | Moyen-Orient / Golfe | — |
| AFR | Afrique | — |
| AUS | Australie | — |

Alias acceptés en lecture : `US`, `CA`, `EU`, `JP`, `JDM`, `GCC`, `ME`, `AF`, `AU`, `GR`… `owned_vehicles.specification_market` n'est **pas réécrit** (les consommations l'utilisent avec `US`, `CA`, `EU`) : la normalisation se fait au moment de comparer.

Comparaison (`part_fitments.market_codes`, liste JSON), strictement explicite :

- marché du véhicule littéralement présent dans la liste → satisfait ;
- marché absent, ou code inconnu du référentiel → candidate ;
- sinon → exclue. `GEN` ne couvre ni `AFR`, ni `MDE`, ni `AUS` ; `USA` ne couvre pas `CAN`. Une ligne valable pour plusieurs marchés les énumère (`["USA","CAN"]`), chacun sur preuve.

`specification_market` = marché de destination **commerciale d'origine** (spécification constructeur), le seul comparé. Le pays où le véhicule circule aujourd'hui est un champ distinct, `circulation_country` (ISO 3166-1 alpha-2, ex. `BF`), sur `owned_vehicles` et `vehicles`, **affiché seulement, jamais comparé** : une Camry américaine importée au Burkina reste `USA` / `BF`.

### Mois de fabrication

`month_from` / `month_to` (1–12) précisent les années-bornes `year_from` / `year_to`. Le véhicule porte `manufacturing_month` (facultatif).

- Année intérieure à la plage → le mois n'est pas demandé.
- Année-borne avec un mois de borne, mois du véhicule inconnu → candidate (`manufacturing_month`).
- Mois hors borne → exclue.

L'année comparée reste l'**année de fabrication** (convention du moteur, rappelée dans la revue des PR #40/#41). L'année-modèle n'est pas utilisée ici.

### Références OEM (`oem_number_relations`)

| Nature | `kind` | Niveaux de preuve admis (`evidence_level`) |
|---|---|---|
| Référence d'origine | lien `oem_number_part` (inchangé) | — |
| Supersession constructeur | `manufacturer_supersession` | `manufacturer_official`, `manufacturer_epc` |
| Remplacement attesté distributeur | `distributor_replacement` | `dealer_catalog`, `aftermarket_crossref` |

Colonnes : `sources` (liste JSON d'URL), `source_note`, `verified_on`, unicité (from, to, kind). Le modèle refuse une nature inconnue, un niveau de preuve incohérent avec la nature, et une référence qui se remplace elle-même. `OemNumber::currentManufacturerReference()` ne suit que les supersessions constructeur. `currentReference()` et `is_superseded / superseded_by_id` restent en place (lecture historique, « supersession non qualifiée »).

Règle appliquée : **un numéro remplacé ne transmet jamais ses compatibilités**, quelle que soit la preuve du remplacement (voir section 10).

## 3. Changements de schéma (migration `2026_11_26_200000_…`)

Additive et idempotente (`hasTable` / `hasColumn`, marchés par `updateOrInsert` sur le code ; aucun identifiant codé en dur, aucune donnée personnelle).

- `markets` : nouvelle table, 8 lignes.
- `part_fitments` : `chassis_restricted` (bool null), `market_codes` (json null), `month_from`, `month_to` (tinyint null).
- `oem_number_fitments` : les mêmes + `chassis_code`.
- `owned_vehicles` : `chassis_code`, `manufacturing_month`.
- `vehicles` : `chassis_code`, `manufacturing_month`, `specification_market`.
- `oem_number_relations` : nouvelle table.

API (ajouts uniquement) :

- `GET /api/catalog/compatible-parts` : paramètres facultatifs `chassis_code`, `specification_market`, `manufacturing_month`.
- Garage et parc : champs facultatifs `chassis_code`, `manufacturing_month` (+ `specification_market` pour le parc), validés et rendus par les ressources.
- Pièces (`POST/PUT /api/parts`) : `fitments.*.chassis_restricted`, `market_codes`, `month_from`, `month_to` ; `PartResource` les rend.
- `missing_criteria` peut contenir `chassis_code`, `specification_market`, `manufacturing_month`.

Protection des anciennes applications : le `PUT` d'une pièce remplace toute la liste des compatibilités. `ConservationRestrictionsFitment` reprend les critères restrictifs d'une ligne de même identité quand la clé est absente (même mécanisme que `ConservationChassisFitment`). Clé présente, même vide : la valeur reçue gagne.

## 4. Impact sur les données de production (lecture seule, 26/11/2026)

| Mesure | Valeur |
|---|---|
| Compatibilités directes (`part_fitments`) | 143, toutes déclarées |
| … avec `chassis_code` | 12 (2 pièces : 90915-10001 ×5, 90915-10003 ×7 ; 8 modèles) |
| … avec un marché explicite dans les notes | 13, dont 12 « marché Japon (JP) » (ce sont les 12 lignes à châssis) |
| … avec des mois `mm/aaaa` dans les notes | 95 |
| … mentionnant un châssis dans la note sans colonne | 29 |
| … avec code moteur / bornes d'années | 113 / 130 |
| Compatibilités par numéro OEM (`oem_number_fitments`) | 0 |
| Numéros OEM remplacés (`is_superseded`) | 10 |
| Véhicules du garage (non supprimés) | 7, dont 0 avec marché, 0 numéro de châssis japonais (5 VIN à 17 caractères) |
| Véhicules du parc | 31, dont 0 avec un VIN au format châssis japonais |

Simulation sur les véhicules réels (garage + parc, 14 véhicules ayant au moins une ligne applicable) :

| Scénario | Paires véhicule × ligne dans la plage d'années | confirmed → candidate | → exclues |
|---|---|---|---|
| **Cette PR telle quelle** (colonnes nouvelles vides) | 25 (9 confirmées, 16 candidates) | **0** | **0** |
| Activer `chassis_restricted` sur les 12 lignes à châssis | 0 paire concernée | 0 | 0 |
| Poser `JPN` sur les 12 lignes « marché Japon » | 0 paire concernée | 0 | 0 |
| Poser les mois des 95 notes `mm/aaaa` | 0 paire sur une année-borne | 0 | 0 |

Pour la recherche publique du catalogue (sans véhicule), activer le châssis restrictif sur les 12 lignes ferait passer ces 12 lignes de `confirmed` à `candidate` tant que le châssis n'est pas fourni. Elles restent visibles avec `include_candidates=1`.

Par construction, aucune ligne existante n'est modifiée par cette PR.

## 5. Risques de régression et parades

| Risque | Parade |
|---|---|
| Une ligne existante change de verdict | Colonnes nulles = aucune restriction ; suite complète verte ; tests de non-régression (châssis informatif, ligne sans restriction). |
| L'éditeur mobile installé efface les restrictions au `PUT` | `ConservationRestrictionsFitment` + test dédié. |
| Divergence SQL / PHP | Les critères nouveaux ne sont jugés qu'en PHP ; le SQL reçoit des identifiants. |
| Coût des requêtes | Seules les lignes restrictives du modèle demandé sont chargées (0 aujourd'hui) ; une requête par table et par recherche. |
| `specification_market` déjà utilisé par les consommations | Pas de réécriture ; alias à la comparaison. |
| Nouveaux `missing_criteria` inconnus de l'app | L'app lit `compatible` / `compatibility_ready`, pas la liste ; les nouvelles valeurs sont ignorées sans erreur. |

## 6. Application mobile (analyse, rien n'est modifié)

- L'éditeur de compatibilités (`fitments_section.dart`) envoie `chassis_code` mais ignore les nouvelles clés : couvert par la conservation côté serveur.
- `garage_compatibility.dart` ne lit que `compatible` et `compatibility_ready` : compatible tel quel.
- Le formulaire du garage n'a ni code châssis, ni mois de fabrication, ni marché (le dépôt sait envoyer `specification_market`, mais aucun champ ne le saisit).

Proposition (PR mobile séparée, après validation) :

1. Formulaire du garage : champs facultatifs « Code châssis » (aide : plaque constructeur ou début du numéro de châssis japonais), « Mois de fabrication » et « Marché d'origine » (liste des 8 codes).
2. Afficher « à vérifier : code châssis / marché / mois » quand ces critères manquent (déjà renvoyés dans `missing_criteria`).
3. Éditeur de compatibilités (personnel) : case « châssis restrictif », sélection des marchés, mois de début et de fin.

Rien de cela n'est nécessaire pour la compatibilité de l'API : tous les ajouts sont facultatifs.

## 7. Compatibilité avec les PR #40 et #41

Les deux PR écrivent `chassis_code` (codes EPC : `MCV30`, `TRN280/TRN285`, `RZN140/…`) et mettent le marché et les mois dans `notes`. Avec ce moteur :

- **sans adaptation**, leurs lignes se comportent comme aujourd'hui (châssis informatif, marché et mois ignorés) ; aucun conflit de fichier attendu (migration datée après les leurs) ;
- **adaptation proposée** (migration de suivi, après fusion des trois PR et validation) : pour chaque ligne YZZD1, ciblée par référence de pièce + slug du modèle + code moteur + bornes, poser `chassis_restricted = true`, `market_codes` (`USA`, ou `USA`+`GEN` pour l'Avalon XX20, etc.) et les mois EPC. Exemple : 4Runner N280 2TR-FE → `TRN280/TRN285`, `USA`, 11/2009 → 08/2011.
- **remplacement 90915-20001 / 20003 → YZZD1** (traité par une autre PR) : à enregistrer en `distributor_replacement` / `dealer_catalog` dans `oem_number_relations`, puisqu'il n'est attesté que par des catalogues de concessionnaires. Les remplacements que la fiche officielle Toyota déclare (YZZB1, YZZB9, YZZG2 → YZZD1) vont en `manufacturer_supersession` / `manufacturer_official`.

## 8. Déploiement

1. Fusion de cette PR (après accord) : migration additive, aucun changement de verdict.
2. Vérification en production : `markets` = 8 lignes ; colonnes présentes ; requêtes de contrôle en lecture.
3. Activation des données, une PR par lot, chacune avec ses sources : lignes YZZD1, puis éventuellement les 12 lignes 10001/10003 (`JPN`, châssis restrictif) et les 10 remplacements existants qualifiés un par un.
4. PR mobile (formulaire du garage, éditeur).

Retour arrière : la migration a un `down()` qui ne retire que ce qu'elle a ajouté.

## 9. Questions ouvertes

1. Activer le châssis restrictif et `JPN` sur les 12 lignes 90915-10001 / 10003 (sources CUSCO, marché japonais) ? Effet : ces lignes passent en « à vérifier » dans le catalogue public tant que le châssis et le marché ne sont pas fournis.
2. Hiérarchie des marchés : `GEN` couvre-t-il bien `AFR`, `MDE` et `AUS` pour vos usages ? Faut-il ajouter d'autres destinations (Asie, Amérique latine) ?
3. Canada : rattaché à la région EPC USA mais distinct de `USA`. Faut-il qu'une ligne `USA` couvre aussi le Canada ?
4. Faut-il un mois de fabrication obligatoire pour certains modèles (changement de référence en cours d'année) ?
5. Qualifier les 10 remplacements existants (`is_superseded`) dans `oem_number_relations` : avec quelles preuves ?


## 10. Compléments (revue du 26/11/2026)

### 10.1 Stockage des preuves : une seule structure par nature de preuve

| Ce qu'on prouve | Où | Colonnes |
|---|---|---|
| Qu'une référence en **remplace** une autre | `oem_number_relations` | `kind` (`manufacturer_supersession` / `distributor_replacement`), `evidence_level` (`manufacturer_official`, `manufacturer_epc` / `dealer_catalog`, `aftermarket_crossref`), `sources` (JSON), `source_note`, `verified_on` |
| Qu'un **véhicule reçoit** une pièce | `part_fitments`, `oem_number_fitments` | `evidence_level` (`manufacturer_official`, `manufacturer_epc`, `dealer_catalog`, `aftermarket_crossref`, `unverified`), `evidence_sources` (JSON) ; `source` (`declared` / `generated`) inchangé |

`oem_numbers.is_superseded / superseded_by_id` restent le pointeur historique (recherche par ancien numéro), sans niveau de preuve. Les colonnes `oem_numbers.supersession_evidence / supersession_sources` que la PR #40 ajoutait ne sont **plus créées** : la #40 écrit désormais ses remplacements dans `oem_number_relations` (voir 10.5).

### 10.2 Verdicts

| Verdict | Condition | `compatible` / `compatibility_ready` |
|---|---|---|
| `confirmed` | une ligne du modèle : tous les critères exigés connus et satisfaits **et** preuve suffisante | vrai / vrai |
| `candidate` | au moins une ligne non exclue, mais critère inconnu, preuve insuffisante (`missing_criteria` contient `evidence`) ou ligne `generated` | faux / faux |
| `excluded` | le modèle a des lignes pour la pièce et **chacune** est contredite par un critère connu (`excluded_criteria`) | faux / faux |
| `unknown` | aucune ligne pour ce modèle, modèle absent, ou modèle inactif | faux / faux |

Priorité : confirmed > candidate > excluded > unknown. `unknown` n'est pas une incompatibilité (le catalogue ne sait pas). Les anciens booléens ne sont vrais que pour `confirmed` : une application qui ne lit pas `status` ne présente jamais une candidate ni une exclusion comme compatible.

Changement visible : une ligne contredite (autre code moteur, autre motricité, année hors plage, châssis/marché/mois incompatibles) donnait `unknown` / `no_matching_fitment` ; elle donne désormais `excluded` / `incompatible_vehicle_criteria`. Les booléens ne changent pas.

### 10.3 Preuve suffisante (`App\Support\FitmentEvidence`)

- ligne `generated` : jamais suffisante → candidate ;
- **remplacé par la section 11** (règle stricte : une déclaration nue n'est plus suffisante).

Le même filtre est appliqué en SQL (`constrainConfirmed`) et en PHP (`assess`).

### 10.4 Numéros remplacés

Une pièce peut porter d'anciens numéros pour être retrouvée par recherche. Ces numéros ne lui transmettent **jamais** leurs compatibilités (`App\Support\OemFitmentInheritance`), qu'ils soient marqués par le drapeau historique ou par un lien qualifié, constructeur ou distributeur. Seule exception : le numéro **principal** de la pièce (`is_primary`), cas d'une pièce ancienne vendue sous son propre numéro. En production : 10 numéros remplacés rattachés à des pièces, dont 1 principal ; 0 compatibilité par numéro OEM, donc aucun verdict ne change.

### 10.5 Données de la PR #40

La #40 se fonde maintenant sur cette PR (elle en est la suite) :

- sa migration `2026_11_26_135000` (colonnes `supersession_*`) est retirée ;
- `Toyota90915Yzzd1Import` crée, de façon idempotente, une ligne `oem_number_relations` par ancien numéro : YZZB1, YZZB9, YZZG2 → `manufacturer_supersession` / `manufacturer_official` avec les 2 URL de la fiche officielle Toyota / Lexus ; 20001, 20003 → `distributor_replacement` / `dealer_catalog` avec les 5 URL des catalogues de concession. Un lien existant n'est jamais réécrit ;
- la table étant créée par la migration `200000`, postérieure à la `140000` qui lance l'import, une migration `2026_11_26_201000_qualifier_les_remplacements_du_filtre_90915_yzzd1` rejoue l'import (idempotent) pour poser les liens ; avant cela, l'import saute l'étape si la table n'existe pas ;
- `verified_on` = 2026-11-26 (date de la revue des sources de la #40) ; `source_note` résume la nature de la source ;
- l'API expose ces liens dans `replaced_by` / `replaces` (fiche OEM et `GET /api/oem/{n}/cross-references`) à la place de `supersession_evidence / supersession_sources` ;
- le drapeau historique `is_superseded / superseded_by_id` reste posé comme avant ;
- ses compatibilités gardent leur preuve dans `notes` ; leur `evidence_level` n'est pas renseigné (lignes déclarées, donc confirmables) — à qualifier ligne par ligne dans une PR de données.

### 10.6 Modèles inactifs

Une génération inactive (`vehicle_models.is_active = false`) sort des recherches courantes : `GET /api/catalog/compatible-parts`, `/api/my/vehicles/{id}/compatible-parts`, `/api/vehicles/{id}/compatible-parts`, `/api/my/parts/{part}/garage-compatibility` (verdict `unknown`, raison `inactive_vehicle_model`), `/api/oem/{n}/models`, `/api/oem/{n}/vehicles`, le résumé `compatibility` des cartes et le texte d'aperçu de partage. Les données restent en base et consultables : paramètre `include_inactive=1` sur ces routes, et la liste `fitments` complète de la fiche pièce, avec `vehicle_model_active: false` (l'éditeur du personnel doit voir toutes les lignes, sinon un `PUT` les effacerait). En production : 2 modèles inactifs, 0 compatibilité et 0 véhicule dessus.

### 10.7 Application mobile

- `GarageCompatibility` lit `compatible` et `compatibility_ready` : `confirmed` → « Compatible » (vert) ; `candidate`, `excluded` et `unknown` → « Non déterminé » (ambre). Une candidate n'est jamais présentée comme compatible.
- Les listes de pièces compatibles n'envoient pas `include_candidates` : elles ne montrent que des confirmées.
- Aucune correction n'est nécessaire. Amélioration proposée (PR séparée, à valider) : lire `status` pour afficher « À vérifier : moteur / châssis / marché / mois / preuve » et « Exclue : … », et masquer dans la fiche les lignes `vehicle_model_active: false`.

### 10.8 Ordre de fusion

1. **#42** (ce moteur, migration additive) ;
2. **#40** (base : #42 ; écrit dans `oem_number_relations`) ;
3. **#41** (indépendante du code de #40/#42 ; sa documentation renvoie à `oem_number_relations`) ;
4. **#43** (Sienna, dépend de #41).

## 11. Règles strictes (revue du 26/11/2026, seconde passe)

Principe du propriétaire : **aucune compatibilité n'est confirmée par une extrapolation non documentée.**

### 11.1 Ligne « documentée » (`App\Support\FitmentEvidence::isDocumented`)

Une compatibilité (`part_fitments` ou `oem_number_fitments`) peut confirmer si et seulement si :

1. son origine est `declared` (jamais `generated`) ;
2. `evidence_level` ∈ `manufacturer_official`, `manufacturer_epc`, `dealer_catalog`, `human_verified` ;
3. `evidence_sources` contient **au moins une URL http(s)** ;
4. pour `human_verified` seulement : `verified_by_id` (compte du personnel, posé par le serveur, jamais par le client) **et** `verified_on` (date) sont renseignés.

Ne suffisent jamais : `evidence_level` vide (déclaration nue, lignes historiques comprises), `aftermarket_crossref`, `unverified`, un niveau fort sans URL, et **le texte de `notes`**, même s'il cite une source ou contient une URL. La même règle est appliquée en SQL (`constrainDocumented`, listes confirmées) et en PHP (`assess`). L'API refuse à l'écriture un niveau qui peut confirmer sans `evidence_sources`, un `human_verified` sans `verified_on`, et tout `verified_by_id` envoyé par le client. La fiche pièce expose `evidence_documented` par ligne.

### 11.2 Impact sur la production (lecture seule, 26/11/2026)

- 143 compatibilités, toutes `declared`, aucune avec preuve structurée : **0 documentée, 143 non documentées**. 15 ont une URL dans `notes`, 126 une note sans URL, 2 aucune note. 0 ligne `oem_number_fitments`.
- Paires réelles véhicule × compatibilité (garage 7 véhicules + parc, même modèle) : 37. Avant : 5 confirmées, 16 candidates, 16 exclues. Après : **0 confirmée**, 21 candidates, 16 exclues. Les **5 paires qui passent de confirmée à candidate sont toutes au parc** (véhicules 86, 111, 113 ; capteurs Mazda PE01-18-221 et PE02-18-211, dont les notes citent « MazdaPartsNow / Mazda USA » sans URL). Garage : 0 confirmée avant comme après.
- Conséquence : sans preuve ajoutée, plus aucune pièce n'est « Compatible » dans l'app ; elles restent visibles en « à vérifier » (`include_candidates=1`).

### 11.3 Rétro-remplissage proposé (migration séparée, NON incluse)

Branche `feat/preuves-retro-remplissage-notes` : pour les seules lignes sans preuve dont `notes` contient des URL, recopie ces URL dans `evidence_sources` et pose un niveau **uniquement par domaine listé** (`parts.cat.com`, `deere.com` → `manufacturer_official` ; `cusco.co.jp`, `hks-power.co.jp` → `aftermarket_crossref`) ; tout autre domaine laisse la ligne intacte. Résultat sur la production : 15 lignes, dont 3 deviennent confirmables (Caterpillar 435-6493, John Deere RE504836 et RE59754) et 12 restent candidates (CUSCO/HKS sur 90915-10001/10003, catalogues aftermarket). Aucune de ces 15 lignes ne concerne un véhicule réel : 0 paire ne change. Les 128 autres lignes ne peuvent pas être qualifiées sans nouvelle recherche.

### 11.4 Restrictions

- Les 12 châssis des 90915-10001/10003 restent **informatifs** (`chassis_restricted` vide) : leurs sources (CUSCO, HKS) sont des catalogues aftermarket qui ne prouvent pas l'exhaustivité des châssis. Aucun marché `JPN` n'est posé.
- Marchés : correspondance explicite seulement (section 2). Mois : facultatif ; seul un véhicule d'**année-borne** sans mois devient candidat (`manufacturing_month` manquant), jamais exclu ni confirmé.

