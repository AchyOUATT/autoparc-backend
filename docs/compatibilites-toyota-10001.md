# Revue des applications du filtre 90915-10001

Le patch reçu le 7 novembre 2026 proposait 11 compatibilités. Aucune n’est
publiée depuis ce lot : les sources citées ne sont pas identifiables et certaines
années dépassent même les plages annoncées dans les notes du patch.

Les propositions originales sont conservées dans
`database/catalogue/fitment-candidates/toyota-90915-10001.json`. Les champs
`proposed_year_*`, `claimed_market` et `claimed_source` décrivent des affirmations
à examiner. Ils ne constituent pas une période constructeur vérifiée. Le lot
porte `pending_review`, avec des listes de sources vides et des dates de
vérification nulles. Il n’est chargé ni par un seeder ni par une migration.

## Contrôle sans écriture

```bash
php artisan catalog:audit-fitment-candidates
php artisan catalog:audit-fitment-candidates --format=json
php artisan catalog:audit-fitment-candidates --fail-on-blocked
```

Un fichier explicite peut aussi être fourni en argument. L’audit contrôle son
format, cherche les modèles dans la marque concernée et relève les périodes
proposées qui dépassent les plages annoncées par les sources. La sortie JSON
inclut les problèmes par entrée et un bilan global. La commande ne consulte pas
les documents sur Internet et ne certifie aucune application. Elle n’écrit
aucune pièce, génération, compatibilité, valeur de stock ou valeur de prix.

Sans option, un audit valide retourne un succès même si des problèmes sont
constatés. `--fail-on-blocked` retourne un échec lorsque des candidats restent
en attente. Un document invalide retourne toujours un échec.

Avec le référentiel actuel, le lot contient :

| Contrôle | Résultat |
| --- | ---: |
| Propositions | 11 |
| Modèles résolus | 1 |
| Entrées dont le modèle manque | 10 |
| Slugs de modèle distincts manquants | 8 |
| Entrées dépassant les années citées | 4 |
| Entrées bloquées | 11 |

Ces valeurs reflètent le catalogue consulté le 7 novembre 2026. L’audit les
recalcule à partir de la base choisie ; un modèle ajouté ultérieurement peut
donc modifier le bilan sans valider sa compatibilité avec le filtre.

Les slugs absents sont `corolla-e130`, `corolla-e120`, `corolla-e110`,
`corolla-e100`, `yaris-xp90`, `camry-xv20`, `camry-xv10` et `rav4-xa10`.
La Corolla E140 existe, mais sa proposition 1ZZ-FE 2006–2008 ne devient pas
documentée par cette seule correspondance de slug.

Les quatre dépassements concernent les deux propositions Corolla E100
(1991 proposé, 1992 cité), Camry XV10 (1991 proposé, 1992 cité) et RAV4 XA10
(1994 proposé, 1996 cité). Les périodes ne sont pas raccourcies arbitrairement :
une plage corrigée doit correspondre à un document réellement consulté.

## Protection du seeder

L’entrée `90915-10001` de `FiltresHuileBoutiqueSeeder` porte
`compatibilites_a_verifier=true`. Avant toute suppression ou insertion de
compatibilités, le seeder conserve les lignes existantes de cette référence et
signale son attente. Les propositions ne deviennent donc pas des déclarations
présentées comme confirmées par la recherche de pièces.

Une compatibilité déjà enregistrée manuellement n’est pas réévaluée par ce
correctif. Son ID, sa provenance et ses notes sont conservés. Le prix et le stock
existants gardent également leur traitement habituel. Les autres références
gardent leur comportement de publication antérieur.

La description de l’article ne borne plus toutes ses applications à une période
« pré-2008 » : cette affirmation n’était pas justifiée par le lot, qui proposait
notamment une Yaris jusqu’en 2011. Le remplacement commercial par YZZN1 ne
transfère pas automatiquement les applications de la nouvelle référence à
l’ancienne.

## Avant une publication réelle

Pour chaque application, relever une source identifiable donnant le filtre
90915-10001, le marché, le modèle/châssis, le moteur et la période exacte.
Conserver l’URL ou la référence documentaire, la page et la date de consultation.
Documenter séparément les générations manquantes et la distinction entre
année de production et millésime. Revoir notamment le périmètre US de la
proposition Corolla E140 / 1ZZ-FE.

L’audit n’est pas un importeur. Lever explicitement la protection du seeder et
publier des applications en base demande un correctif distinct, avec ses preuves,
ses tests et une mise à jour ciblée qui préserve les déclarations boutique.
Ce lot n’ajoute aucune migration et n’appelle pas le seeder global en production.

Les tentatives vers `autoparts.toyota.com` et `parts.toyota.com` du 7 novembre
2026 ont reçu un refus de connexion du proxy (`CONNECT 403`). Aucun document
Toyota n’a donc été consulté en ligne pour valider les propositions de ce lot.

## Qualification des cinq lignes CUSCO (26 novembre 2026)

Les cinq applications documentées (Corolla E100/E110 4A-FE, Camry XV10/XV20 5S-FE, RAV4 XA10 3S-FE) reçoivent, par la migration `2026_11_26_210000_qualifier_les_preuves_aftermarket_du_filtre_90915_10001`, une preuve structurée `aftermarket_crossref` avec l’URL du [catalogue CUSCO](https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf), le marché `JP` et les mois des périodes déjà écrites dans les notes (le marché et les mois ont été retirés ensuite : voir la section suivante). Ce niveau ne suffit pas à confirmer : les lignes restent candidates. Seuls les champs vides sont remplis.

La description de l’article n’est corrigée que si elle est encore le texte lu en production le 26 novembre 2026. La borne « pré-2008 » en est retirée. Le remplacement par le 90915-YZZN1 y est présenté comme une mention des catalogues de concessionnaires américains, et non comme un fait officiel Toyota (la fiche [90915-YZZN1](https://autoparts.toyota.com/products/product/filter-s-a-oil-90915yzzn1) ne cite que le 90915-YZZF2 comme ancienne version). Aucune relation OEM 10001 → YZZN1 n’est ajoutée dans ce lot, et aucune compatibilité n’est recopiée de la YZZN1 vers la 10001.

Les six candidatures encore en attente (Corolla 7A-FE, E120/E130/E140 1ZZ-FE, Yaris XP90 1NZ-FE) ne sont toujours pas publiées. Le catalogue CUSCO range d’ailleurs la Corolla ZZE122/ZZE124 1ZZ-FE et la Vitz NCP91 1NZ-FE sous le 90915-10003, pas sous le 10001.

## Correctif du 26 novembre 2026 : le marché JP et les mois n'excluent plus

### Le défaut

Le marché de destination et les mois de fabrication sont des critères **éliminatoires** dans `FitmentRestrictions` : si le véhicule a un marché connu qui ne figure pas dans la liste de la ligne, le verdict passe à « exclu » et la pièce disparaît des listes. Il en va de même d'un mois hors plage dans l'année-borne (début ou fin). Sans mois ou sans marché côté véhicule, le critère est seulement « à renseigner » et le verdict reste « candidat ». Seul le châssis a un drapeau (`chassis_restricted`) qui dit explicitement « cette liste est restrictive » ; le marché et les mois n'en ont pas.

La migration `2026_11_26_210000` avait posé `market_codes=['JP']` et les mois de la période CUSCO sur les cinq lignes. Or le catalogue CUSCO est un catalogue japonais de pièces adaptables : rien ne prouve qu'il soit restrictif ni exhaustif. Un véhicule de marché USA, AFR, EUR ou GEN, ou né hors plage un mois de borne, était donc exclu sans preuve. Aucun véhicule du parc ni du garage n'avait de marché ou de mois renseigné, donc aucun verdict réel n'a changé ; le risque est apparu dès qu'un utilisateur renseigne un marché.

### La correction

La migration `2026_11_26_220000_retirer_marche_et_mois_des_lignes_cusco_du_filtre_90915_10001` retire `market_codes` et `month_from`/`month_to` de ces cinq lignes, champ par champ, **seulement** s'ils valent encore exactement ce que 210000 a posé (`['JP']` et les mois de la période source) et si la ligne est encore au niveau `aftermarket_crossref`. Le niveau, l'URL CUSCO et les notes restent. Le marché et la période restent lisibles dans les notes. Un champ modifié ou enrichi à la main, ou une ligne promue à un niveau suffisant, n'est jamais touché. Son `down()` remet ces valeurs sur les champs encore vides.

Résultat : les cinq lignes restent « candidat, preuve insuffisante » pour tout véhicule, quel que soit son marché ou son mois ; le seul critère à renseigner est la preuve.

Le `down()` de 210000 a aussi été corrigé : une ligne n'est rétablie que si **tous** les champs posés par `up()` (niveau, sources, marché, deux mois) valent encore leur valeur d'origine ; sinon elle n'est pas touchée du tout. La migration 210000 étant déjà appliquée en production, cette modification ne concerne que les retours arrière futurs.

Limite connue : Eloquent normalise `JP` en `JPN` à l'écriture. Une ligne réécrite par le modèle (par exemple par l'API) porte alors `['JPN']`, valeur différente de `['JP']` : le retrait ne la reconnaît plus comme « posée par 210000 » et ne la modifie pas.

### Option future : drapeaux de restriction

Un marché ou une période ne devraient restreindre que si la ligne le déclare, comme pour le châssis. Évolution possible, non réalisée ici : colonnes `market_restricted` et `period_restricted` (faux par défaut) ; `FitmentRestrictions` n'exclurait sur le marché ou les mois que si le drapeau est posé (ou si la preuve est suffisante) ; sinon ces critères resteraient informatifs (« à renseigner »). On pourrait alors réinscrire le marché et les mois d'une source qui documente réellement une restriction. Cela demande une migration de colonnes, des tests et une relecture de toutes les lignes existantes ; ce n'est pas fait.

### Remplacement 90915-10001 → 90915-YZZN1 : sources à conserver

Aucune relation `oem_number_relations` n'est créée, et aucune compatibilité n'est transférée de la YZZN1 vers la 10001. Les sources suivantes sont conservées pour une qualification ultérieure, **à qualifier plus tard dans `oem_number_relations`, sans transfert de compatibilités** :

| Source | Ce qu'elle dit | Nature |
|---|---|---|
| [ToyotaPartsDeal, fiche 90915-10001](https://www.toyotapartsdeal.com/oem/toyota~filter~sub~assy~oil~90915-10001.html) | « Replaced By 90915-YZZN1 » | catalogue de concessionnaire américain (consultée le 26 novembre 2026) |
| [ToyotaPartsNow, fiche 90915-10001](https://www.toyotapartsnow.com/oem/toyota~filter-sub-assy-oil~90915-10001) | « Replaced By 90915-YZZN1 » | catalogue de concessionnaire américain (consultée le 26 novembre 2026) |
| [Fiche officielle Toyota 90915-YZZN1](https://autoparts.toyota.com/products/product/filter-s-a-oil-90915yzzn1) | ne cite que le 90915-YZZF2 comme ancienne version ; ne mentionne pas le 90915-10001 | constructeur |

Le remplacement n'est donc attesté que par des catalogues de concessionnaires, pas par la fiche constructeur. Il ne prouve aucune application : un remplacement de référence ne transfère pas les applications de la nouvelle référence vers l'ancienne. Pour le qualifier, il faut une page constructeur (ou un EPC) qui nomme la 10001 comme ancienne référence de la YZZN1.

