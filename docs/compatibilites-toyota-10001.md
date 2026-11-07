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
