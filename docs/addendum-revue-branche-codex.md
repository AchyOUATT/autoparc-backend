# Addendum à la revue de `codex/catalogue-finitions-rapport-2026-10-21`

**Date :** 23 octobre 2026
**Objet :** quatre lacunes absentes de la première liste, deux points mineurs, une correction à la porte de fusion.

---

## Préambule

Le plan d'intervention en quatre phases est accepté tel quel. La méthode est la bonne — reproduire d'abord les deux anomalies de fusion pour obtenir des tests qui échouent *pour les bonnes raisons*, corriger ensuite — et la règle ajoutée (« pour une opération d'écriture, contrôler l'état de la base avant et après, pas seulement le code HTTP ») est précisément ce qui aurait attrapé le `201` muet.

Ce document ne conteste rien de ce plan. **Il corrige la liste sur laquelle il a été bâti : elle était incomplète, et c'est notre faute, pas la vôtre.** Les quatre points ci-dessous s'ajoutent aux sept lacunes et deux pièges déjà transmis.

Tout ce qui suit a été vérifié sur un banc d'essai dont l'autoload a été réparé — les worktrees chargent bien leur propre code. Les mesures faites avant cette réparation ont été rejetées.

---

## A. Les fiches déjà incohérentes deviennent immodifiables — **bloquant**

### Le mécanisme

`app/Support/VehicleClassification.php` valide l'**état final** de la fiche, en retombant sur la valeur stockée pour tout champ absent de la requête :

```php
$value = static fn (string $field) => array_key_exists($field, $data)
    ? $data[$field]
    : $vehicle?->getAttribute($field);
```

C'est exactement ce qu'il fallait faire pour fermer F2. Mais la conséquence n'a pas été traitée : **une fiche déjà incohérente — abîmée par le code d'avant, qui laissait passer ces écritures — déclenche désormais l'erreur sur *toute* modification, y compris d'un champ sans aucun rapport.**

### Ce que cela donne en exploitation

- **Côté parc.** Un véhicule dont la finition appartient à un autre modèle rend `422` sur une simple mise à prix : `{"trim_id":["Cette finition n'appartient pas au modele selectionne."]}`, et le prix n'est pas enregistré. Le véhicule ne peut plus être vendu au bon tarif.
- **Côté garage.** Le kilométrage — qui commande les rappels d'entretien — devient insaisissable sur la même fiche.

Le propriétaire n'a aucun moyen de s'en sortir : il faudrait qu'il corrige la finition, mais l'écran qu'il utilise n'envoie pas ce champ.

### Ce que nous demandons

Traiter ce point **en phase 02 et comme condition de fusion**, pas comme un reste. Deux voies possibles, à votre choix :

1. une migration de réparation qui met à `NULL` les `trim_id` / `motorisation_id` incohérents préexistants, en journalisant ce qu'elle touche ;
2. ou tolérer l'incohérence préexistante lorsque la requête n'y touche pas — c'est-à-dire ne valider une dépendance que si l'un des champs du couple est présent dans la requête *ou* a changé.

La première est plus franche, la seconde moins invasive. Ce qui n'est pas acceptable, c'est l'état actuel, où le correctif bloque des utilisateurs existants.

**Volume inconnu.** Nous n'avons pas accès à la base de production : le mécanisme est démontré, le nombre de fiches concernées ne l'est pas. C'est ce volume qui décide de l'urgence, et il faut le mesurer avant la fusion.

---

## B. Rien n'a été mesuré sur PostgreSQL

Toutes les mesures — les vôtres comme les nôtres — ont tourné sur SQLite en mémoire (`phpunit.xml`). La production est sur PostgreSQL. Deux points précis restent donc non établis, et l'un des deux a un mode de défaillance sévère.

### B.1 — `ReferenceCatalogWriter::equivalent()` énumère les représentations de booléen

```php
if (is_bool($expected)) {
    return in_array($stored, [true, 1, '1', 't', 'true'], true) === $expected;
}
```

Les représentations courantes de `pdo_pgsql` (`true`, `'t'`) **sont** dans la liste, donc **ce n'est pas un défaut établi**. Mais le code reconnaît une liste close au lieu de normaliser, et le mode de défaillance est asymétrique :

> si une valeur vraie revient un jour sous une forme non listée, chaque ligne est vue comme modifiée à **chaque** passage des seeders, et **tout le référentiel est réannoncé à chaque déploiement** — le défaut exactement inverse de F1, et plus coûteux que lui.

Aucun test SQLite ne peut l'attraper. Nous suggérons de normaliser (`filter_var($stored, FILTER_VALIDATE_BOOLEAN)` ou un cast explicite) plutôt que d'allonger la liste, et d'ajouter au moins un test sur le moteur de production.

### B.2 — La précision à la seconde

Le raisonnement qui justifie le passage de `>` à `>=` repose sur la précision à la seconde des colonnes de date. Elle n'a pas été vérifiée sur PostgreSQL.

---

## C. La migration de rattrapage a sa propre fenêtre de perte

`database/migrations/2026_10_21_000002_repair_reference_catalog_timestamps.php` :

```php
$now = now();

foreach (['brands', 'vehicle_models', 'trims'] as $table) {
    DB::table($table)->whereNull('created_at')->update(['created_at' => $now]);
    DB::table($table)->update(['updated_at' => $now]);
}
```

`$now` est figé **avant** trois `UPDATE` sans `where` ni lot. Sur PostgreSQL, la migration est de surcroît enveloppée dans une transaction (`Migration::$withinTransaction` vaut `true` et n'est pas surchargée ici).

Toute ligne réestampillée avec ce `$now` **antérieur**, mais rendue visible **après** le passage d'un client, est perdue définitivement pour ce client : son curseur suivant sera postérieur à `$now`, et le `>=` ne la rattrapera pas. Le scénario a été reproduit de façon déterministe, sans course.

Le correctif est simple — prendre l'horodatage ligne par ligne, ou poser le curseur serveur avant la migration — mais il faut le poser.

---

## D. Le passage de `>` à `>=` n'a aucun test

`CatalogSyncController.php:94` est passé de `>` à `>=`, et c'est une bonne correction : elle rattrape l'écriture survenue dans la même seconde que le curseur. Mais **remettre `>` laisse les quatre tests de `CatalogueHorodatageTest` au vert.**

Les tests contournent d'ailleurs la frontière au lieu de l'éprouver — `travel(1)` en `CatalogueHorodatageTest.php:54-55`, avec le commentaire « *Sync may replay its boundary second; put these rows before it.* », et `travel(2)` en `:86`.

Un correctif non verrouillé revient. Il faut un test qui écrit **exactement** sur la seconde du curseur.

---

## Deux points mineurs

### `trim_applications` arrive vide au déploiement

La collection que la branche ajoute à la charge utile de synchronisation (`CatalogSyncController.php:64-65`) compte **0 ligne** après `migrate` et les six seeders. Ses seuls écrivains sont `catalog:import-trims` et `catalog:audit-trims`, et **rien n'appelle `ImportTrims`** : hors `vendor/` et hors tests, le seul fichier qui mentionne la commande est la commande elle-même. Aucun script de déploiement, aucun seeder, aucun planificateur.

L'argument « les finitions sont couvertes pour l'avenir par `ImportTrims` » ne tient donc pas tant que personne ne lance `ImportTrims`.

### `source = declared` est la valeur par défaut de la colonne

Le contrôle de provenance de la phase 03 repose sur `source === FitmentSource::Declared`. Or `declared` est la valeur **par défaut** en base : tout chemin d'écriture qui omet la colonne produit une ligne confirmable. Ce n'est pas théorique — le seeder de la boutique, qui *est* le référentiel de pièces, crée ses 94 lignes sans renseigner `source`.

Le contrôle ne prouve donc pas qu'un humain a déclaré quoi que ce soit : il prouve seulement que la ligne n'est pas estampillée `generated`. À dire explicitement, ou à renforcer.

---

## Une correction à la porte de fusion

Le critère « la suite de non-régression passe » est **inutilisable tel quel**.

Sur le banc réparé, **2 passages complets sur 3 rendent un échec**, dans la famille du stockage de photos, avec un test différent à chaque fois. Ces tests passent en isolation, dépendent de l'ordre d'exécution et de la propreté du répertoire de stockage, et **ne figurent pas dans le diff de la branche**.

Ce n'est donc pas un défaut de la branche — mais une porte qui bloque à tort une fois sur trois se fait forcer, et une porte qu'on force cesse d'être une porte.

Il faut soit corriger la dépendance à l'ordre, soit nommer explicitement ces tests comme exclus du critère.

Dans le même esprit : les chiffres annoncés dans `docs/corrections-audit-externe.md:181-186` ne se reproduisent pas. Le document annonce « 641 tests réussis et deux échecs préexistants dans `PagesProduitTest` ». Mesuré sur le banc réparé : **643 tests, 3 848 assertions, zéro échec**, et les deux cas nommés sont des tests **sautés**, pas des échecs. À corriger dans le document, parce que c'est lui qui sert de référence à la revue.

---

## Ce qui n'est pas de votre ressort

`catalog:import-nhtsa` écrivait `is_active => true` sur chaque ligne rencontrée et aurait ressuscité les modèles désactivés à la main. **C'est traité de notre côté**, avec le filtre `is_active` sur les routes de sélection ; ne l'intégrez pas à votre périmètre.

Un point de méthode au passage, parce qu'il a failli nous coûter le correctif : nous avions d'abord jugé ce chemin intestable « parce qu'il appelle le réseau ». C'est faux — il passe par la façade `Http`, que `Http::fake()` intercepte entièrement. Il n'était pas intestable ; il n'était pas testé.

---

## Deux points où vous avez raison contre nous

1. **`compatible-parts`.** Refuser de toucher à la requête avant d'avoir établi si `engine_code IS NULL` est une contrainte intentionnelle ou un défaut de filtrage est la bonne discipline. Nous la traitions comme un défaut à retirer, sans l'avoir vérifié.

   Précision utile pour ce diagnostic : la cause du résultat nul **n'est pas** la reclassification en `generated`. Sur la base de référence, 0 ligne sur 94 est `generated`, et les trois lignes de la Camry XV50 sont toutes `declared` — elles portent `notes`, qui figure dans `EMPREINTE_HUMAINE`. Le filtre de source ne retire rien. La liste se vide parce que `constrainConfirmed` impose `engine_code IS NULL OR TRIM(engine_code) = ''` quand aucun code n'est fourni, et que les trois lignes portent toutes un code moteur. **Même un catalogue 100 % `declared` rend zéro.** La mesure que la branche recommandait avant fusion (« la part des `part_fitments` en `source=generated` ») n'aurait donc pas répondu à la question.

2. **La séparation des responsabilités.** Logique métier / couche API / couche de persistance : le principe est juste. Une réserve d'exécution seulement — poser des contraintes d'intégrité en base sur la cohérence finition / motorisation butera sur les lignes déjà incohérentes du point **A**. Ces deux chantiers sont liés et doivent être séquencés ensemble.
