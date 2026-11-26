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

Un lot par déploiement ; `php artisan migrate:rollback --force` (Shell
Render du service, par le propriétaire) annule le dernier lot, **avec le
code de l'état correspondant**. Ordre : #44 → #43 → #41 → #40 → #42.

| PR annulée | Effet du `down()` |
| --- | --- |
| #44 | 180200 : restaure les motorisations depuis `motorisation_generation_assignments` ; 180100 : restaure les lignes Sienna nues depuis `part_fitment_archives` ; 180000 : supprime ces deux journaux |
| #43 | supprime les lignes Sienna posées, réactive le modèle nu `sienna` (générations créées : voir la PR) |
| #41 | sans effet sur les données (référentiel conservé, rejeu idempotent) |
| #40 | sans effet sur les données (idem) |
| #42 | supprime `markets`, `oem_number_relations`, les colonnes de critères, de preuve, `verified_by_id`/`verified_on`, `circulation_country` (**données de preuve perdues**) |

Rejoué localement sur PostgreSQL (états #42, #40, #41, +#43 `eb4ab09`,
+#44 `d59dae0`) : les cinq retours passent et le schéma
revient exactement à celui de `main` (aucune migration en attente). Les
lignes YZZD1 de #40/#41 restent (sans colonnes de preuve), compatibles avec
l'ancien code.

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

## 5. Points ouverts au 26/11/2026

- Règle stricte : en production, 0 des 143 compatibilités existantes n'a
  de preuve structurée ; les 5 couples réels aujourd'hui « confirmés »
  (parc) passeront « non déterminés » après #42. Décision du propriétaire.
- #43 : les 6 lignes Sienna sont posées sans `evidence_level` /
  `evidence_sources` ; le test `ToyotaSiennaReferentielImportTest::test_api_positifs_et_negatifs_par_generation`
  attend `confirmed` et échoue sur l'état +#43. Il faut que #43 pose la
  preuve structurée (même mécanique que la migration 202000 de #41) avant
  fusion.
