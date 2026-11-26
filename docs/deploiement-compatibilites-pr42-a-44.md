# Déploiement des PR #42 → #40 → #41 → #43 → #44 : sauvegarde, retour arrière, vérification

État vérifié le 26/11/2026 (heure de Ouagadougou, UTC+0). Rien n'est fusionné.
Ce document ne fusionne rien et ne modifie aucune donnée ; il décrit ce que
le propriétaire déclenche, et comment le vérifier.

## 1. Ce que Render exécute à chaque déploiement

- Service `autoparc-backend` (`srv-dacrposmqu1s73bkf2ug`), runtime Docker,
  branche `main`, **déploiement automatique à chaque commit sur `main`**,
  health check `/up`, 1 instance.
- Il n'y a ni pre-deploy command ni start command Render : tout est dans le
  `CMD` du `Dockerfile`, dans cet ordre :
  1. `config:cache`, `route:cache`, `storage:link` ;
  2. **`php artisan migrate --force`** : toutes les migrations en attente,
     dans l'ordre des noms de fichier, en un seul lot (`batch`) par
     déploiement. Chaque migration est transactionnelle sous PostgreSQL ;
  3. seeders rejoués (échec toléré) : `VehicleModelsSeeder`, `TrimsSeeder`,
     `KiaCatalogueSeeder`, `TwoWheelerCatalogSeeder`, `VehicleCheckItemsSeeder` ;
  4. en arrière-plan après `/up` : `media:apercus`, puis
     `catalog:import-consommations` (garde `ImportConsommations::relier()`
     apportée par #44) ;
  5. `php artisan serve`.
- Conséquence : **chaque fusion dans `main` est un déploiement**. Les imports
  de compatibilités (YZZD1, générations anciennes, Sienna) sont des
  migrations, pas des seeders ; aucun seeder de pièces n'est rejoué.
- Si `migrate` échoue, `serve` ne démarre pas, `/up` ne répond pas et Render
  laisse le déploiement précédent en service ; la migration fautive est
  annulée par sa transaction.

### Migrations par PR (lot créé au déploiement)

| Étape | Migrations ajoutées |
| --- | --- |
| #42 | `2026_11_26_200000_ajouter_les_criteres_chassis_marche_et_mois_aux_compatibilites` (table `markets`, `oem_number_relations`, colonnes de critères et de preuve, `verified_by_id`/`verified_on`, `circulation_country`) |
| #40 | `2026_11_26_140000_poser_les_compatibilites_du_filtre_90915_yzzd1`, `2026_11_26_201000_qualifier_les_remplacements_du_filtre_90915_yzzd1` |
| #41 | `2026_11_26_160000_ajouter_les_generations_anciennes_du_filtre_90915_yzzd1`, `2026_11_26_202000_qualifier_les_preuves_des_generations_anciennes_90915_yzzd1` |
| #43 | `2026_11_26_170000_donner_ses_generations_a_la_sienna` |
| #44 | `2026_11_26_180000_creer_les_journaux_de_restructuration_du_referentiel`, `…_180100_retirer_les_compatibilites_de_la_sienna_nue`, `…_180200_reattribuer_les_motorisations_de_la_sienna` |

Les horodatages 140000/160000/170000/180000 sont antérieurs à 200000 : en
production (fusions successives), Laravel les exécute quand même, après la
200000 déjà passée, puisqu'ils sont en attente. Ce scénario a été rejoué sur
PostgreSQL 17 local (référentiel `db:seed` + `FiltresHuileBoutiqueSeeder`,
puis chaque état déployé à la suite avec le `CMD` ci-dessus) : toutes les
migrations passent ; lignes YZZD1 documentées : 11 après #40, 32 après #41 ;
#43 ajoute 6 lignes Sienna (sans preuve structurée, voir §5).

## 2. Sauvegarde avant la première fusion

Constat (lecture seule, outils Render, 26/11/2026) : `autoparc-db`
(`dpg-dacrpbsmqu1s73bkdjq0-a`), PostgreSQL 18, plan **payant
`0.1c-256mb` (Basic-256mb)**, région Oregon, pas de haute disponibilité, pas
de réplica, disque 1 Go.

Ce qui existe ou peut être fait :

1. **Point-in-time recovery (automatique)** : Render sauvegarde en continu
   les bases payantes. Fenêtre : 3 jours (espace Hobby) ou 7 jours (Pro).
   La restauration crée une **nouvelle** instance à l'instant choisi (pas à
   moins de 10 min du présent) ; il faut ensuite pointer `DATABASE_URL` du
   service vers elle. Déclenchement : propriétaire, tableau de bord Render →
   base → *Recovery* → *Restore database*.
2. **Export logique (à faire juste avant la fusion de #42)** : tableau de
   bord → base → *Recovery* → *Create export*. Archive `.dir.tar.gz`
   téléchargeable, conservée 7 jours par Render : la télécharger pour la
   garder. Déclenchement : **propriétaire uniquement**.
3. `pg_dump` depuis un poste : exige l'URL externe de la base (un secret).
   Les agents ne l'utilisent pas : l'accès agent est en lecture seule
   (`query_render_postgres`), sans export ni identifiant d'écriture.

Noter l'heure exacte (UTC) de la fusion de #42 : c'est la cible d'un
éventuel PITR.

## 3. Retour arrière

Trois niveaux, du plus léger au plus lourd.

### 3.1 Code seul : *Rollback* Render

Tableau de bord → service → *Events* / *Deploys* → *Rollback* sur le
déploiement précédent (dernier en service avant ce chantier :
`dep-dc2tere0tbcc738il6sg`, commit `beaa0af`, PR #39). Le code revient, le
`CMD` rejoue `migrate --force` sans rien à migrer : **le schéma reste celui
du nouveau code**. Sûr, car toutes les migrations sont additives : l'ancien
code ignore les nouvelles tables et colonnes. Attention : avec l'auto-deploy,
le prochain commit sur `main` redéploie ; revenir aussi le commit (PR de
revert) si le retour doit durer.

### 3.2 Schéma et données : `down()` dans l'ordre inverse

Chaque déploiement crée un lot de migrations. `php artisan migrate:rollback --force` annule le dernier lot. Il doit s'exécuter **avec le code de l'état correspondant**, dans l'ordre #44 → #43 → #41 → #40 → #42.

**Pas de Shell Render** : le service web est sur l'offre gratuite. Le propriétaire lance donc la commande depuis son poste, en deux temps :
1. récupérer la *External Database URL* (tableau de bord → base → *Connect*) dans un `.env` local, sans la versionner ;
2. pour chaque état, dans l'ordre : `git checkout <commit de l'état>`, `composer install`, puis `php artisan migrate:rollback --force`.

Les agents n'ont aucun accès en écriture à la production. Faire d'abord le *Rollback* du code (§3.1). Sinon, le prochain démarrage rejoue `migrate --force` et réapplique le lot.

| PR annulée | Effet du `down()` |
| --- | --- |
| #44 | 180200 : restaure les motorisations depuis `motorisation_generation_assignments`. 180100 : restaure les lignes Sienna nues depuis `part_fitment_archives`, **avec leur identifiant, leurs colonnes et leurs dates d'origine** (correctif `fix/sienna-retour-arriere-identifiants`, cc1f4f8). 180000 : supprime ces deux journaux. |
| #43 | supprime les lignes Sienna posées et réactive le modèle nu `sienna` |
| #41 | supprime ses compatibilités, reconnues à l'identité et aux mêmes notes, et les générations créées qui ne sont référencées nulle part (5e377df) |
| #40 | supprime ses compatibilités, la relation de remplacement datée de l'import, les lignes de pivot secondaires et les numéros créés s'ils ne servent plus (01cdee2) |
| #42 | supprime `markets`, `oem_number_relations`, les colonnes de critères et de preuve, et `circulation_country`. **Toute preuve saisie après le déploiement est perdue** : l'exporter avant. |

### 3.2 bis Intégrité des données au retour arrière (répétée le 26/11/2026)

**Copie de répétition :**
- base PostgreSQL 18 locale, chargée depuis un export en lecture seule de la production (`scripts/verification-deploiement/`, sans donnée personnelle) ;
- schéma et 117 migrations identiques à la production ;
- tables chargées : catalogue complet, 31 véhicules du parc, 7 du garage, 111 motorisations Sienna et garage.

**Déroulé :**
1. démarrage de main ;
2. déploiements successifs, chacun avec `migrate --force` et les 5 seeders comme sur Render ;
3. après chaque étape : empreintes (`empreintes.sql` : nombre de lignes, md5 du contenu complet et md5 hors `created_at`/`updated_at`, par table, plus md5 du schéma, des contraintes et des index) et contrôle des clés étrangères (`controle_fk.sql`) ;
4. retours arrière dans l'ordre inverse, chacun avec le code de son état ;
5. redémarrage de main.

**Nombre de lignes à chaque étape :**

| Table | main | +#42 | +#40 | +#41 | +#43 | +#44 | −#44 | −#43 | −#41 | −#40 | −#42 |
|---|---|---|---|---|---|---|---|---|---|---|---|
| part_fitments | 143 | 143 | 154 | 175 | 181 | 177 | 181 | 175 | 154 | 143 | 143 |
| vehicle_models | 326 | 326 | 326 | 342 | 345 | 345 | 345 | 342 | 326 | 326 | 326 |
| oem_numbers / oem_number_part | 52 | 52 | 57 | 57 | 57 | 57 | 57 | 57 | 57 | 52 | 52 |
| oem_number_relations | — | 0 | 5 | 5 | 5 | 5 | 5 | 5 | 5 | 0 | — |
| markets | — | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | 8 | — |
| part_fitment_archives | — | — | — | — | — | 4 | — | — | — | — | — |
| motorisation_generation_assignments | — | — | — | — | — | 86 | — | — | — | — | — |
| motorisations | 111 | 111 | 111 | 111 | 111 | 111 | 111 | 111 | 111 | 111 | 111 |

**Résultat après le retour complet :**
- Contenu (md5 hors dates) **identique à l'état de départ pour toutes les tables**.
- Schéma, contraintes et index : identiques.
- 0 clé étrangère orpheline, à chaque étape.
- 0 changement de verdict (voir §4 bis).
- Seul `updated_at` diffère, sur trois tables :
  - `parts`, touché par les imports ;
  - `vehicle_models`, à cause de la réactivation de `sienna` ;
  - `vehicle_check_items`, réécrit par les seeders à **chaque** démarrage, même sans ce chantier.

**Avant les correctifs**, la même répétition laissait :
- 32 compatibilités, 16 générations, 5 numéros d'origine et 5 lignes de pivot, car les `down()` de #40 et #41 étaient vides ;
- les 4 lignes Sienna nues restaurées sous de nouveaux identifiants (548–551 au lieu de 340, 456–458).

Pour rejouer : `scripts/verification-deploiement/repetition-chaine.sh` (en-tête : prérequis et variables).

### 3.3 Restauration complète

Seulement en cas de perte de données : PITR à l'instant noté avant la
fusion de #42 (§2.1), ou restauration de l'export (§2.2) dans une **nouvelle**
base vide (`pg_restore --clean --if-exists --no-owner --no-privileges`),
puis bascule de `DATABASE_URL` et *Rollback* du code (§3.1). Toute donnée
saisie après l'instant restauré est perdue.

## 4. Vérification après chaque déploiement (lecture seule)

SQL (via `query_render_postgres`, SELECT uniquement) :

```sql
-- 1. Migrations du lot et lot courant
select batch, migration from migrations
where migration like '2026_11_26_%' order by id;

-- 2. Tables et colonnes attendues (#42)
select table_name, column_name from information_schema.columns
where (table_name = 'part_fitments' and column_name in
  ('evidence_level','evidence_sources','verified_by_id','verified_on','chassis_code'))
   or (table_name in ('owned_vehicles','vehicles') and column_name = 'circulation_country')
   or table_name in ('markets','oem_number_relations','part_fitment_archives','motorisation_generation_assignments')
order by 1, 2;

-- 3. Compatibilités : total, documentées (règle stricte), par article YZZD1
select count(*) total,
       count(*) filter (where evidence_level in
         ('manufacturer_official','manufacturer_epc','dealer_catalog','human_verified')
         and evidence_sources is not null) avec_preuve
from part_fitments;
select p.sku, f.evidence_level, count(*) from part_fitments f
join parts p on p.id = f.part_id
where p.sku in ('90915-YZZD1','04152-YZZA1','90915-YZZN1')
group by 1, 2 order by 1, 2;
-- attendu pour 90915-YZZD1 : 8 manufacturer_official + 3 dealer_catalog après #40,
-- 23 + 9 après #41 ; #43 ajoute des lignes Sienna SANS preuve (niveau vide)
-- tant qu'il n'est pas complété (§5).

-- 4. Remplacements qualifiés (#40)
select o1.number, r.kind, r.evidence_level, o2.number remplace_par
from oem_number_relations r
join oem_numbers o1 on o1.id = r.from_oem_number_id
join oem_numbers o2 on o2.id = r.to_oem_number_id order by 1;

-- 5. Sienna (#43/#44)
select vm.slug, vm.is_active, count(f.id) lignes
from vehicle_models vm left join part_fitments f on f.vehicle_model_id = vm.id
where vm.slug like 'sienna%' group by 1, 2 order by 1;
select count(*) from part_fitment_archives;
select count(*) from motorisation_generation_assignments;
```

Les noms de colonnes des requêtes 4 et 5 sont ceux des migrations de
l'état concerné ; les vérifier avec la requête 2 si une requête échoue.

API (publique, sans compte) :

- `GET https://autoparc-backend.onrender.com/up` → 200.
- `GET /api/catalog/compatible-parts?vehicle_model_id=<id de camry-xv30>&year=2004&engine_code=1MZ-FE&include_candidates=1`
  → 90915-YZZD1 avec `compatibility_assessment.status = confirmed`
  (ligne documentée, #41).
- Même requête sans `engine_code` → `candidate` (critère manquant).
- Un véhicule du parc portant PE01-18-221 / PE02-18-211 (véhicules 86,
  111, 113) → `candidate` avec `missing_criteria` contenant `evidence` :
  effet voulu de la règle stricte tant qu'aucune preuve n'est posée.
- `include_inactive=1` fait réapparaître le modèle nu `sienna` désactivé.

## 4 bis. Verdicts avant / après (lecture seule)

Deux scripts, dans `scripts/verification-deploiement/` :
- `instantane-verdicts.php` évalue, dans une transaction en lecture seule annulée à la fin :
  - chaque véhicule réel du parc et du garage × chaque pièce qui a des compatibilités ;
  - des échantillons du catalogue (chaque ligne aux années de début et de fin, avec et sans moteur).
- `compare-verdicts.php` classe chaque changement selon les règles attendues. Il sort en code 1 si un changement est `NON_ATTENDU`.

Les règles attendues :
- `preuve_insuffisante` : confirmé → candidat, faute de preuve ;
- `critere_contredit_exclu` : → exclu, sur un critère contredit ;
- `modele_inactif` : génération nue désactivée ;
- `nouvelles_lignes_yzzd1` : ajouts de #40 et #41 ;
- `referentiel_sienna` ;
- `modele_nouveau` : échantillon présent d'un seul côté ;
- `detail_seul` : même verdict, détail différent.

### Procédure

**Juste avant la fusion de #42 :**
1. Exporter la production en lecture seule : `export-catalogue.sql` → `catalogue.json` et `export-vehicules.sql` → `vehicules.json`, via `query_render_postgres`.
2. Préparer une base PostgreSQL vide et migrer avec le code de `main` : `php artisan migrate --force`.
3. Charger l'export : `python3 charger-copie.py <base> catalogue.json vehicules.json`, puis `psql <base> -f controle_fk.sql` (0 attendu).
4. Prendre l'instantané : `php scripts/verification-deploiement/instantane-verdicts.php avant.json`, avec `DB_*` pointant sur la copie.

   Pour que les échantillons couvrent aussi les générations créées par #41 et #43, passer en second argument l'instantané d'une répétition post-#44 : `instantane-verdicts.php avant.json repetition-pr44.json`.

**Après le déploiement de #44 :**
1. Ré-exporter. Décommenter dans `export-catalogue.sql` les quatre tables nouvelles, et ajouter `circulation_country` dans `export-vehicules.sql`.
2. Préparer une base vide migrée avec le **code déployé**, puis la charger.
3. Prendre l'instantané : `php instantane-verdicts.php apres.json avant.json`, pour réutiliser les mêmes échantillons.
4. Comparer : `php compare-verdicts.php avant.json apres.json ecarts.csv`. Le code de sortie doit être 0. Lire le CSV.

Les deux instantanés portent le commit et le nombre de migrations dans `meta`. Aucune écriture n'a lieu en production. Les instantanés ne contiennent ni VIN ni plaque.

### Résultat sur la copie (main → +#44)

793 changements, 0 `NON_ATTENDU`.

| Portée | Changement | Règle | Nombre |
|---|---|---|---|
| parc | confirmé → candidat | preuve_insuffisante | 5 |
| parc | inconnu → exclu (année) | critere_contredit_exclu | 4 |
| garage | inconnu → exclu (année) | critere_contredit_exclu | 7 |
| parc / garage | candidat → candidat (détail) | detail_seul | 7 / 2 |
| catalogue | confirmé → candidat | preuve_insuffisante | 220 |
| catalogue | inconnu → exclu | critere_contredit_exclu | 187 |
| catalogue | candidat / confirmé → inconnu | modele_inactif | 7 / 7 |
| catalogue | inconnu → candidat / confirmé | nouvelles_lignes_yzzd1 | 24 / 18 |
| catalogue | générations nouvelles | modele_nouveau | 104 |
| catalogue | candidat → candidat (détail) | detail_seul | 201 |

Les 5 couples du parc qui passent de « confirmé » à « candidat » :

| Véhicule du parc | Modèle | Année | Pièce | Ligne |
|---|---|---|---|---|
| 86 | CX-9 TC | 2018 | PE01-18-221 | 509 |
| 111 | CX-5 KF | 2018 | PE01-18-221 | 507 |
| 111 | CX-5 KF | 2018 | PE02-18-211 | 494 |
| 113 | CX-5 KF | 2019 | PE01-18-221 | 507 |
| 113 | CX-5 KF | 2019 | PE02-18-211 | 494 |

Après le retour arrière complet : 0 changement.

## 5. Points ouverts au 26/11/2026

- **Règle stricte, acceptée par le propriétaire.** Aucune des 143 compatibilités de production n'a de preuve structurée. Les 5 couples réels « confirmés » du parc passent à « candidat » (§4 bis). Leur qualification est un chantier séparé : PR #47, `docs/qualification-preuves/`.
- **#43 et #44 doivent intégrer les correctifs de retour arrière** avant fusion :
  - fusionner #41 (5e377df) ;
  - reprendre cc1f4f8 de `fix/sienna-retour-arriere-identifiants` (identifiants d'origine des lignes Sienna restaurées).
- **Exclusion de l'échec connu `seasonal`** (contrainte `vehicle_check_items`) : il est traité à part ; le job PostgreSQL l'exclut nommément et le rejoue à part.
