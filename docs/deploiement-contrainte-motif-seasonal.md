# Déploiement — contrainte `vehicle_checks.reason` et motif `seasonal`

Ce document accompagne la PR qui corrige le ticket #45 : sous PostgreSQL,
`vehicle_checks_reason_check` n'acceptait que `trip` et `periodic`, alors que
l'API et l'application envoient `seasonal`.

Rien de ce qui suit n'autorise une fusion, un déploiement ou une écriture en
production. La fusion attend la confirmation explicite que la sauvegarde de
production a bien été faite.

## Version PostgreSQL

- Production (`autoparc-db`, `dpg-dacrpbsmqu1s73bkdjq0-a`) : **PostgreSQL 18.6**
  (`Debian 18.6-1.pgdg12+2`).
- Les premiers tests locaux avaient été joués sous **18.7**
  (`Debian 18.7-1.pgdg13+5`).
- Les mêmes contrôles ont ensuite été rejoués sous **18.6 exact**
  (`Debian 18.6-1.pgdg13+2`, binaire extrait du paquet officiel, cluster
  dédié). Seule la distribution Debian du paquet diffère (bookworm en
  production, trixie en local) : le numéro de version de PostgreSQL est
  identique.

Contrôles rejoués sous 18.6 : `migrate`, `migrate:rollback --step=1`
(la contrainte revient à `trip` / `periodic`, `seasonal` est refusé),
`migrate` à nouveau, `up()` joué deux fois, puis
`ControleAvantVoyageTest` et `MotifSeasonalDesControlesMigrationTest`
(49 tests réussis).

## Sauvegarde avant fusion

L'accès agent à la base est en lecture seule : personne d'autre que le titulaire du tableau de bord Render ne peut exporter la base.

Avant la première fusion, sur `autoparc-db` : **Recovery**, puis
**Create export**, et télécharger l'archive (Render ne la garde que 7 jours).
La restauration à un instant donné existe aussi sur les plans payants.

Sans confirmation explicite que cet export est fait, la PR #46 ne doit
pas être fusionnée.

## Référence d'avant migration (lecture seule, 26/11/2026, 17:51 UTC+0, Africa/Ouagadougou)

Une seule ligne dans `vehicle_checks`, motif `trip`. Aucune ligne
`seasonal` ni `periodic`. 17 réponses rattachées, aucune note saisie.
Aucun identifiant personnel n'est recopié ici.

Requête de contrôle (à rejouer à l'identique après déploiement) :

```sql
SELECT
    id,
    owned_vehicle_id,
    user_id,
    reason,
    trip_distance_km,
    mileage_km,
    to_char(performed_at, 'YYYY-MM-DD HH24:MI:SS') AS performed_at,
    verdict,
    blocking_count,
    watch_count,
    checked_count,
    (note IS NOT NULL) AS has_note,
    client_reference::text AS client_reference,
    to_char(created_at, 'YYYY-MM-DD HH24:MI:SS') AS created_at,
    to_char(updated_at, 'YYYY-MM-DD HH24:MI:SS') AS updated_at,
    md5(vehicle_checks::text) AS row_md5
FROM vehicle_checks
ORDER BY id;

SELECT
    COUNT(*) AS n,
    md5(string_agg(md5(vehicle_check_answers::text), ',' ORDER BY id)) AS answers_md5
FROM vehicle_check_answers
WHERE vehicle_check_id = 1;

SELECT pg_get_constraintdef(oid)
FROM pg_constraint
WHERE conname = 'vehicle_checks_reason_check';
```

Empreinte constatée le 26/11/2026 à 17:51 (Ouagadougou, UTC+0) :

| Champ | Valeur |
| --- | --- |
| `id` | 1 |
| `owned_vehicle_id` | 2 |
| `user_id` | 8 |
| `reason` | `trip` |
| `trip_distance_km` | 400 |
| `mileage_km` | 177000 |
| `performed_at` | 2026-10-03 03:08:54 |
| `verdict` | `blocked` |
| `blocking_count` | 3 |
| `watch_count` | 1 |
| `checked_count` | 17 |
| `has_note` | non |
| `client_reference` | `faa8ea4d-bd96-4c67-8bdb-ca0d4ccc963e` |
| `created_at` / `updated_at` | 2026-10-03 03:08:54 |
| `row_md5` (`md5(vehicle_checks::text)`) | `3e0e4421044f98c85bde2af3cfb70e51` |
| Nombre de réponses | 17 |
| `answers_md5` | `00b9de8d471ec345b4f051d880813c8d` |
| Contrainte actuelle | `CHECK (reason IN ('trip', 'periodic'))` |

Après déploiement, la ligne `id = 1` doit produire le même `row_md5` et
le même `answers_md5`. Seule la définition de la contrainte doit changer :

```
CHECK (reason IN ('trip', 'seasonal'))
```

Si d'autres contrôles ont été enregistrés entre cette référence et le
déploiement, les compter, mais la ligne `id = 1` doit rester inchangée.

## Limitation du retour arrière

Le `down()` de la migration `2026_11_26_180000_autoriser_le_motif_seasonal_des_controles`
rétablit la contrainte d'avant correctif (`trip`, `periodic`).

**Il refuse de s'exécuter** dès qu'une ligne porte un motif hors
`trip` / `periodic` (en pratique, dès qu'un contrôle `seasonal` a été
enregistré). Le message est :

> Retour arrière refusé : vehicle_checks contient un motif hors trip/periodic.
> Reclasser explicitement ces lignes avant de rétrécir la contrainte.

Cette migration **ne supprime jamais** de ligne, et **ne convertit jamais**
un motif en un autre, ni à la montée, ni à la descente. Aucun script
automatique ne doit le faire non plus.

Si un retour arrière devient nécessaire alors que des lignes `seasonal`
existent, la décision est humaine et manuelle, par exemple :

1. laisser la nouvelle contrainte en place (option par défaut, recommandée
   une fois des contrôles saisonniers enregistrés) ;
2. ou, après examen de chaque ligne concernée, la reclasser ou l'archiver
   à la main, puis seulement ensuite rejouer le `down()`.

Sans cette décision, le `down()` doit rester bloqué.

## Vérifications d'après déploiement

À lancer seulement après le déploiement sur `main`, et après que
https://autoparc-backend.onrender.com/up répond 200.

### 1. Santé

```bash
curl -sS -o /tmp/autoparc-up.txt -w "%{http_code}\n" https://autoparc-backend.onrender.com/up
```

Attendu : `200`.

### 2. SQL, lecture seule

Rejouer les requêtes de la section « Référence d'avant migration ».

- Contrainte : `trip` et `seasonal` uniquement (plus `periodic`).
- Ligne `id = 1` : mêmes valeurs, `row_md5 = 3e0e4421044f98c85bde2af3cfb70e51`.
- Réponses : `n = 17`, `answers_md5 = 00b9de8d471ec345b4f051d880813c8d`.

### 3. API — lecture seule, à privilégier

Les routes de contrôle sont derrière l'authentification Firebase,
préfixe `/api/my`. Elles exigent un compte client et un véhicule de
son garage. **Ne pas utiliser le véhicule du contrôle `id = 1`**
(`owned_vehicle_id = 2`) pour un essai d'écriture.

Le moyen le moins intrusif est le **GET du gabarit**, qui valide le
motif sans rien écrire :

```bash
# Remplacer JETON et VEHICULE_ID (véhicule d'essai, pas le 2).
API=https://autoparc-backend.onrender.com/api
AUTH="Authorization: Bearer JETON"

# seasonal doit être accepté (200) et renvoyé dans data.reason
curl -sS -D - -H "$AUTH" -H "Accept: application/json" \
  "$API/my/vehicles/VEHICULE_ID/check-template?reason=seasonal"

# trip doit rester fonctionnel (200)
curl -sS -D - -H "$AUTH" -H "Accept: application/json" \
  "$API/my/vehicles/VEHICULE_ID/check-template?reason=trip"

# periodic doit être rejeté par la validation Laravel : 422, pas 500
curl -sS -D - -H "$AUTH" -H "Accept: application/json" \
  "$API/my/vehicles/VEHICULE_ID/check-template?reason=periodic"
```

Attendu :

- `reason=seasonal` → 200, `data.reason` = `seasonal`, présence de
  `available_reasons`.
- `reason=trip` → 200, `data.reason` = `trip`.
- `reason=periodic` → **422** (motif absent de `CheckReason`). Un 500
  indiquerait une régression.

Le jeton est celui d'un compte d'essai déjà existant (application
mobile de développement, ou compte client de test). Aucun nouveau
compte n'a à être créé pour ces GET.

### 4. API — écriture, à ne lancer que sur accord explicite

`POST /api/my/vehicles/{id}/checks` **crée un passage définitif**.
L'API n'offre ni modification ni suppression d'un contrôle : un essai
laisserait une ligne réelle en production, rattachée au véhicule
choisi, visible dans l'historique.

Ce POST n'est donc **pas nécessaire** pour valider le correctif : le
GET ci-dessus prouve que `seasonal` passe la validation, et la
contrainte SQL prouve que PostgreSQL l'accepte. Si un test d'écriture
est tout de même demandé :

- le faire sur un véhicule d'essai, jamais sur `owned_vehicle_id = 2` ;
- fournir une `client_reference` UUID dédiée, pour le reconnaître ;
- savoir qu'aucune procédure automatique ne devra ensuite effacer
  ni convertir cette ligne ;
- ne lancer le POST qu'après un accord explicite.

`POST` avec `reason=periodic` doit aussi répondre **422**, pas 500,
toujours par la validation, avant d'atteindre la base.

Ces appels d'écriture n'ont pas été lancés.

### 5. Journaux Render

Service web : `srv-dacrposmqu1s73bkf2ug`, espace de travail
`tea-dacrks7qj5pc739eh6a0`.

Après le déploiement, parcourir les journaux depuis l'heure de mise
en service, et chercher :

- les erreurs d'application (`level` error, ou texte `SQLSTATE` /
  `vehicle_checks_reason_check`) ;
- les requêtes en 5xx, en particulier sur les chemins
  `/api/my/vehicles/*/check-template` et `/api/my/vehicles/*/checks`.

Une insertion `seasonal` qui échouerait encore produirait une violation
de contrainte et un 500, visibles dans ces journaux.

## Ticket

Après vérifications concluantes, clôturer le ticket #45 en y joignant
le résumé du déploiement (contrainte, empreinte de la ligne `trip`,
résultat des GET, absence d'erreur nouvelle dans les journaux).
