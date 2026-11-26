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

Table `markets` (code, nom, `epc_region`, `parent_code`). Les quatre catalogues de l'EPC Toyota sont Japon, Europe, Général et USA/Canada. Les destinations du catalogue Général sont rattachées à `GEN` :

| Code | Nom | Région EPC | Parent |
|---|---|---|---|
| JPN | Japon | JPN | — |
| EUR | Europe | EUR | — |
| GEN | Général | GEN | — |
| USA | États-Unis | USA | — |
| CAN | Canada | USA | — |
| MDE | Moyen-Orient / Golfe | GEN | GEN |
| AFR | Afrique | GEN | GEN |
| AUS | Australie | GEN | GEN |

Alias acceptés en lecture : `US`, `CA`, `EU`, `JP`, `JDM`, `GCC`, `ME`, `AF`, `AU`, `GR`… `owned_vehicles.specification_market` n'est **pas réécrit** (les consommations l'utilisent avec `US`, `CA`, `EU`) : la normalisation se fait au moment de comparer.

Comparaison (`part_fitments.market_codes`, liste JSON) :

- marché du véhicule ou l'un de ses parents dans la liste → satisfait (`GEN` couvre `AFR`) ;
- véhicule connu par un parent d'un marché de la liste (`GEN` contre `AFR`) → candidate ;
- marché absent, ou code inconnu du référentiel → candidate ;
- sinon → exclue.

Convention de saisie : une ligne documentée dans le catalogue Général sans restriction de destination se saisit `GEN` ; si l'EPC précise une destination, on saisit le code fin (`MDE`, `AFR`…).

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

Colonnes : `source_url`, `source_note`, `verified_on`, unicité (from, to, kind). Le modèle refuse une nature inconnue, un niveau de preuve incohérent avec la nature, et une référence qui se remplace elle-même. `OemNumber::currentManufacturerReference()` ne suit que les supersessions constructeur. `currentReference()` et `is_superseded / superseded_by_id` restent en place (lecture historique, « supersession non qualifiée »).

Règle proposée pour la suite : un remplacement distributeur ne permet jamais d'hériter d'une compatibilité confirmée ; au mieux, candidate. Aujourd'hui aucune compatibilité n'est héritée par remplacement, donc rien ne change.

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
