# Enrichir le catalogue des finitions

Le catalogue historique contient 248 entrées modèle/génération et 258 finitions,
dont 181 entrées sans finition. Ces chiffres décrivent les seeders à l'origine
de ce travail, pas la couverture d'une base de production ni l'exhaustivité des
gammes automobiles.

Le premier lot de recherche est conservé dans
`database/catalogue/trims-pilot.json` : cinq entrées Mazda et cinq Honda. C'est
un **backlog de recherche**, pas un lot importable. Ses millésimes sont des
cibles à vérifier ; il ne contient aucun nom de finition inventé. L'accès aux
sources officielles était bloqué par le proxy au moment de sa préparation.

## Mesurer les manques sur la base cible

Après installation des dépendances et exécution des migrations :

```bash
php artisan catalog:audit-trims
php artisan catalog:audit-trims --only-missing --format=csv > finitions-manquantes.csv
php artisan catalog:audit-trims --pilot --format=json > pilote-finitions.json
```

Ces commandes ne modifient pas les données. Les compteurs globaux restent
distincts de la sélection filtrée. L'audit classe chaque entrée comme :

- `a_documenter` : aucune finition ;
- `finitions_non_documentees` : des finitions existent, sans périmètre sourcé
  dans le nouveau registre ;
- `documente_partiellement` : au moins une finition possède un marché et une
  période documentés.

**Aucun de ces états ne certifie une gamme exhaustive.** Les finitions
historiques ne deviennent pas « vérifiées » par leur seule présence. Il faut
prioriser les manques selon les véhicules effectivement utilisés et les
saisies libres du garage ; l'audit de référence ne contient pas de données
personnelles ni de classement automatique des véhicules.

## Préparer un lot vérifié

Lire une brochure, un catalogue ou un communiqué constructeur pour la
génération, le marché et les millésimes concernés. Relever les appellations
exactes, la provenance et la date de consultation. Une brochure d'un seul
millésime ne documente pas toute une génération. Une finition ne détermine pas
nécessairement un moteur, une boîte ou une motricité.

Voici uniquement un **exemple de schéma avec des données fictives** ; les noms
de marque, modèle et finition sont à remplacer, et cette URL ne constitue pas
une source automobile :

```json
{
  "version": 1,
  "entries": [
    {
      "brand": "marque-exemple",
      "model_slug": "modele-exemple-g1",
      "market": "US",
      "model_year_from": 2018,
      "model_year_to": 2018,
      "trims": [
        {
          "name": "Finition fictive",
          "code": null,
          "description": "Exemple de format uniquement."
        }
      ],
      "sources": [
        {
          "url": "https://example.com/brochure-fictive",
          "title": "Document fictif pour illustrer le schéma",
          "consulted_on": "2026-10-21"
        }
      ],
      "verified_on": "2026-10-21"
    }
  ]
}
```

`brand` est le slug de marque ; `model_slug` est résolu **dans cette marque**.
Le modèle doit déjà exister. Les marchés sont des codes explicites tels que
`US`, `FR` ou `GCC` ; choisir une convention cohérente pour les lots suivants.
Les millésimes sont des entiers. Les dates de consultation et de vérification
ne doivent pas être futures ; la vérification ne doit pas précéder la
consultation. Les sources exigent une URL HTTPS sans identifiants intégrés.

Les applications sont rattachées à la finition par marché et bornes de
millésimes. Une même finition peut avoir plusieurs périodes ou marchés. Les
nouveaux trims gardent leurs valeurs moteur et motricité par défaut à `null` :
le lot ne déduit aucune caractéristique technique.

La commande contrôle le format et la cohérence des données, **elle ne consulte
pas les sources sur Internet et ne certifie pas leur authenticité**. La
vérification documentaire est une étape humaine préalable. Ne pas utiliser
`verified_on` pour une recherche encore en attente.

## Simuler puis importer

```bash
php artisan catalog:import-trims /chemin/lot-verifie.json --dry-run
php artisan catalog:import-trims /chemin/lot-verifie.json
```

Tout le fichier est validé et résolu avant écriture. Un modèle absent, une
preuve structurée manquante ou une contradiction invalide le lot entier. La
simulation produit le même bilan sans écrire. Un rejeu identique conserve les
IDs et les dates de modification, sans doublon.

Les champs `code` et `description` omis conservent leurs valeurs existantes.
S'ils sont envoyés avec une valeur différente, l'import refuse par défaut la
correction. Examiner d'abord le bilan, puis utiliser l'option explicite si la
correction est voulue :

```bash
php artisan catalog:import-trims /chemin/lot-corrige.json --dry-run --update-existing
php artisan catalog:import-trims /chemin/lot-corrige.json --update-existing
```

Les sources et la date de vérification d'une même application peuvent être
actualisées par le lot ; une vérification antérieure à celle enregistrée est
refusée, même avec `--update-existing`. L'import fusionne les applications ; changer les
bornes d'une période ajoute une autre application, il ne supprime pas la
période précédente. Une rétractation ou une fusion de périodes nécessite une
revue distincte. Le fichier n'est pas exécuté automatiquement au déploiement :
une migration du schéma n'est pas un import de nouvelles gammes.

Les millésimes sont comparés aux bornes de production pour détecter une
erreur de modèle, mais ces notions peuvent différer. Si une source vérifiée
confirme un millésime hors de ces bornes, l'option
`--allow-production-year-mismatch` permet son import avec avertissement, sans
modifier les dates du modèle. Cette dérogation est distincte de l'autorisation
de corriger une description.

Le seeder historique continue à ajouter les anciennes finitions absentes sans
écraser celles déjà présentes. Pour une correction documentée de ces
finitions, utiliser le chemin d'import et son contrôle de conflit. Ne pas
supposer qu'une modification de texte dans l'ancien seeder met à jour toutes
les bases existantes.

## Transmission aux applications

`GET /api/catalog/brands/{brand}/models` ajoute `applications` aux finitions,
avec marché, millésimes, sources et date de vérification. Les descriptions et
les IDs moteur/motricité par défaut restent disponibles.

`GET /api/catalog/sync` expose désormais :

- `description` dans les trims ;
- une collection additive `trim_applications`, avec IDs, périmètre, sources et
  horodatages.

Les consommateurs doivent fusionner les références par ID. La synchronisation
relit la seconde du curseur (`updated_at >= since`) pour ne pas perdre un
changement enregistré à la même seconde ; une ligne de frontière peut donc
être reçue deux fois. Les seeders des marques et modèles horodatent les vrais
changements et ne rafraîchissent pas toutes les lignes à chaque rejeu.

Une migration réannonce une fois toutes les marques, modèles et finitions,
y compris les lignes ayant une ancienne date non nulle. Cela permet aux
clients déjà synchronisés de recevoir les données historiques corrigées et
les descriptions nouvellement disponibles. Les IDs et dates de création
connues sont conservés. Ce rattrapage est enregistré dans la table des
migrations et ne se répète pas à chaque démarrage.

Le client mobile doit encore lire les nouveaux périmètres pour filtrer ses
choix et afficher la source. Aucun changement Flutter n'est inclus dans ce
dépôt. Les anciennes lignes sans application restent explicitement sans
périmètre documenté ; elles ne sont ni supprimées ni déclarées exhaustives.

## Reprendre le pilote documentaire

Les sites nécessaires sont `hondanews.com`, `automobiles.honda.com`,
`news.mazdausa.com`, `www.mazdausa.com` et `insidemazda.mazdausa.com`. Les ajouts
réseau ont été enregistrés dans le brouillon de l'environnement. Le refus
observé était un CONNECT 403 du proxy, avant consultation des documents.

Après activation effective de l'accès, consulter les sources et préparer un
vrai lot au format ci-dessus. Conserver le backlog séparé du lot vérifié,
simuler l'import, examiner les conflits, importer sur la base choisie puis
relancer l'audit. Ne pas annoncer que les 181 entrées sont complétées avant
ce travail documentaire et la vérification de la base cible.

## Validation réalisée le 21 octobre 2026

- PHP 8.4, dépendances Composer du lockfile et extensions CI installés dans
  un runtime Docker local, sans modification des manifestes ni du lockfile.
- 37 tests ciblés passent, soit 205 assertions : audit en lecture seule,
  import atomique, simulation, conflits, rejeu, traçabilité, dérogation
  millésime/production et synchronisation.
- Suite complète : 568 tests passent ; deux tests de `PagesProduitTest`
  échouent sur les dimensions des aperçus. Les deux mêmes échecs ont été
  reproduits dans une archive isolée du commit initial, sans ces changements.
- Sur une base SQLite jetable réellement migrée et peuplée avec les seeders
  du référentiel : 25 marques, 248 entrées, 258 finitions et 181 entrées sans
  finition. Le backlog non vérifié est refusé sans écriture. Aucun nouveau
  nom de finition automobile n'a été ajouté.

La production et le client Flutter n'ont pas été modifiés. La vérification
documentaire des dix gammes pilotes reste bloquée par l'accès aux sites
constructeur ; l'outillage prêt ne remplace pas cet enrichissement.
