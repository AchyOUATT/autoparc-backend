> **Fenêtre de récupération — vérifié le 2026-10-11 sur la documentation Render.**
>
> La documentation de Render (https://render.com/docs/free) l'écrit noir sur blanc :
> les bases Postgres gratuites expirent 30 jours après leur création ; une base
> expirée reste **inaccessible mais non supprimée** pendant un sursis de **14 jours**,
> le temps de la passer sur un plan payant ; passé ce délai, Render supprime
> définitivement la base et toutes ses données. Le plan gratuit ne propose
> **aucune forme de sauvegarde**.
>
> Base créée le 2026-09-03 → expirée vers le 2026-10-03 → sursis jusqu'au
> **~2026-10-17**. Le 2026-10-11, il reste donc environ **six jours** pour
> récupérer les données, en passant `autoparc-db` sur un plan payant depuis le
> tableau de bord Render (le palier le plus bas, Basic-256mb, est annoncé à
> ~6 $/mois ; tarif à confirmer sur l'écran d'upgrade).
>
> La date du 2026-09-03 vient du commit de configuration Render, pas de Render
> lui-même : la base a pu être créée un ou deux jours plus tard, ce qui décalerait
> d'autant la fin du sursis. La fiche de la base dans Render est la seule source
> fiable. Les sections ci-dessous, rédigées par les agents d'analyse, marquent ce
> sursis comme « à confirmer » parce qu'il n'est pas vérifiable depuis le dépôt :
> il l'est désormais, et c'est l'échéance qui commande tout le reste.

---
# AutoParc — document de reprise

## 1. Ce qui s'est passé

La base Postgres gratuite `autoparc-db`, créée le 2026-09-03, a expiré vers le 2026-10-03 (contexte établi).
Le conteneur du service web ne démarre plus : `php artisan migrate --force` (Dockerfile:64) est joint par `&&` à `php artisan serve` (Dockerfile:69), base injoignable = chaîne rompue avant que le serveur n'écoute.
Conséquence : aucun port n'est ouvert, donc aucune réponse HTTP — pas une erreur, rien.
Les données saisies depuis l'application sont perdues : aucun dump, aucune fixture, aucun script d'export dans le dépôt (vérifié).
Le schéma et une partie des référentiels, eux, se reconstruisent depuis le dépôt — mais pas tous, et pas automatiquement.

## 2. Pourquoi le service est totalement muet et pas en erreur

Le mécanisme interne est confirmé ligne par ligne :

1. Dockerfile:60-69 est un `CMD` en **forme shell** (`CMD php artisan ...`) : il est exécuté par `/bin/sh -c`, et ce `sh` est PID 1 du conteneur.
2. Dockerfile:64 `php artisan migrate --force` est joint par `&&` à Dockerfile:69 `php artisan serve --host=0.0.0.0 --port=${PORT:-8000}`. Un code de retour non nul ligne 64 termine PID 1 **avant tout `bind()`**.
3. Aucun port n'est donc jamais lié. `grep -ni healthCheckPath render.yaml` ne rend rien : aucun health check déclaré, Render n'a aucun autre moyen de qualifier l'instance.

D'où 000 et non 500 : il n'y a pas d'application derrière le routeur, il y a une absence d'instance.

Trois précisions qui changent la lecture des logs :

- **Le point de rupture n'est pas forcément la ligne 64.** `php artisan config:cache` (Dockerfile:60) s'exécute avant, et `APP_KEY` est `sync: false` (render.yaml:15), donc posée à la main. Variable manquante ou valeur de config invalide = rupture dès la ligne 60. À lire dans les logs, pas à présumer.
- **Le symptôme n'est probablement pas apparu le 2026-10-03.** Un déploiement en échec laisse tourner la version précédente. Ce qui rejoue le `CMD` complet, c'est un redémarrage d'instance. Donc : lire les logs du **dernier démarrage**, pas du dernier déploiement.
- Le silence de 180 s après poignée de main TLS est cohérent avec une périphérie qui attend un port qui ne s'ouvrira jamais, mais le comportement exact de Render n'est pas vérifiable depuis le dépôt → **à vérifier** dans l'onglet Events/Deploys du service `autoparc-backend`, et avec `curl -sv` (en-tête `x-render-routing`).

**Deuxième couche, indépendante et tout aussi mortelle.** Même si `serve` démarrait, 100 % des routes `/api/*` rendraient 500 : bootstrap/app.php:40 pose `throttle:api` sur tout le groupe, le RateLimiter retombe sur le magasin par défaut (`cache.limiter` absent de config/cache.php, lu par CacheServiceProvider.php:36), et ce magasin vaut `database` (config/cache.php:18). Chaque requête fait un SELECT sur la table `cache` **avant** d'atteindre un contrôleur. `/` tombe pour une raison voisine : `StartSession` + `SESSION_DRIVER` par défaut `database` (config/session.php:21). Aucune de ces variables n'est posée dans render.yaml.

Une seule route survit à une base morte : `/up` (bootstrap/app.php:15), enregistrée hors de tout groupe de middleware (ApplicationBuilder.php:223-251), sans écouteur `DiagnosingHealth` nulle part. Elle rend 200 base morte.

## 3. Ce qui est perdu, ce qui revient tout seul

**Revient sans intervention, au prochain démarrage réussi sur une base neuve**

- Le schéma complet : les 50 migrations de `database/migrations/` par Dockerfile:64.
- `vehicle_check_items` : `VehicleCheckItemsSeeder` est autonome (aucune dépendance, écrit directement dans sa table) et il est dans le `CMD` (Dockerfile:67).
- Les deux migrations de données 2026_10_03_000003 et 000004 (de simples `UPDATE` sur `vehicle_models`).
- Les comptes **clients** : VerifyFirebaseToken.php:83-90 recrée l'utilisateur à la première connexion mobile. Mais avec un **nouvel id local**, détaché de tout son historique, et avec `'role' => UserRole::Client` forcé (VerifyFirebaseToken.php:87).

**Ne revient pas**

- Les ~30 tables de données saisies en production : parc, clients, commandes, garages clients, contrôles, besoins, notifications, locations, partenaires. Aucune sauvegarde dans le dépôt. `database/database.sqlite` (456 Ko, 8 septembre) est une base de développement, inutilisable comme source.
- **Neuf référentiels sur onze.** Le `CMD` ne lance que `VehicleModelsSeeder` et `VehicleCheckItemsSeeder` (Dockerfile:65-68). Les autres (Drivetrains, EngineTypes, Colors, Countries, Manufacturers, **Brands**, **PartCategories**, Features, Trims) ne sont appelés que depuis DatabaseSeeder.php:35-51, et aucune migration ne les remplit.
- **Les modèles de véhicules, en silence.** `brands` étant vide, VehicleModelsSeeder.php:355-357 fait `continue` sur chaque ligne : zéro insertion, zéro message, code de retour nul. Le `|| echo` de Dockerfile:66 ne s'affichera même pas.
- **Le catalogue de la boutique, définitivement par ce chemin.** FiltresHuileBoutiqueSeeder.php:188-196 abandonne faute de `PartCategory` — bruyamment (`error()` lignes 191-193, relayé par `echo Artisan::output()` en 000005:32 et 000006:44), mais les migrations 2026_10_02_000005 et 000006 **sont enregistrées quand même**. Un `migrate` ultérieur ne les rejouera jamais : il faudra lancer le seeder à la main.
- **Tous les comptes du personnel.** `seedStaff()` est `private` (DatabaseSeeder.php:106), appelé seulement depuis DatabaseSeeder.php:55, et exige `SEED_STAFF_PASSWORD` (DatabaseSeeder.php:111), absente de render.yaml et de .env.example — sinon avertissement et `return` (lignes 113-120). `staff:rotate-password` ne crée aucun compte (RotateStaffPassword.php:45-49).
- `motorisations` : aucun seeder, aucune migration. Seul écrivain : `catalog:import-consommations` (ImportConsommations.php:169). La fonctionnalité motorisation de l'app restera morte jusqu'à ce que cette commande tourne.
- Tous les jetons Sanctum : reconnexion obligatoire pour le personnel et les clients.
- Les variables `sync: false`, si le **service web** est recréé et pas seulement la base : APP_URL/APP_KEY (render.yaml:14-15), FIREBASE_CREDENTIALS (38-39), MEDIA_DISK et les six AWS_* (60-73). Et `TASKS_SECRET` est en `generateValue: true` (render.yaml:45) : une recréation du service génère une nouvelle valeur, pendant que reminders.yml continue d'envoyer l'ancienne → 401 silencieux.

## 4. Ce qu'il faut faire maintenant

**Étape 1 — Dater la panne, gratuitement, sans Render (5 min).**
Dépôt GitHub → Actions → workflow des rappels. reminders.yml:27 le planifie à `0 8 * * *` et reminders.yml:103-106 fait `exit 1` sur tout code ≠ 200. La première exécution rouge date la panne à la journée. Attention, reminders.yml:18-21 le dit lui-même : GitHub désactive un planning après 60 jours sans activité sur le dépôt — vérifier cela avant de conclure d'une absence de traces.

**Étape 2 — Trouver le point exact de rupture.**
Render → `autoparc-backend` → Logs du **dernier démarrage d'instance** (pas du dernier déploiement). Chercher, dans l'ordre : la sortie de `config:cache`, celle de `route:cache`, puis un `SQLSTATE` / `could not translate host name` / `Connection refused` pendant `migrate`. Puis vérifier l'**absence** de la ligne `Server running on http://0.0.0.0:...`. Si la rupture est avant `migrate`, c'est APP_KEY ou le cache de configuration, et rien de la suite ne s'applique tel quel.

**Étape 3 — Ouvrir la page de `autoparc-db`.**
Trois choses à relever, dans cet ordre : l'état (expirée / suspendue / absente), la date de suppression annoncée s'il y en a une, et la présence ou l'absence d'un onglet Backups/Recovery. Les délais de grâce et la possibilité de récupérer sont **à confirmer sur place** : rien dans le dépôt ni dans le contexte établi ne les atteste, et les analyses se contredisaient sur ce point. Ne rien redéployer avant d'avoir regardé.

**Étape 4 — Si la base répond encore : dumper immédiatement.**
Le levier est votre poste, et il ne coûte qu'une ligne : décommenter `extension=pdo_pgsql` dans `C:/tools/php84/php.ini:936` (la DLL est déjà là : `C:/tools/php84/ext/php_pdo_pgsql.dll`), puis `php -m | findstr pgsql`. Ensuite, dans un `.env` **séparé** de votre `.env` de développement :

```
DB_CONNECTION=pgsql
DB_URL=<External Database URL>
```

`php artisan migrate:status` d'abord (lecture seule), puis `pg_dump`. config/database.php:89 lit déjà `env('DB_URL')` — aucun code à toucher. C'est le seul levier qui donne une commande ponctuelle : le plan gratuit n'ouvre pas de Shell.

**Étape 5 — Avant tout redéploiement, deux gestes à ne pas sauter.**

- **Ne pas rouvrir le service sur une base vide.** Le personnel ne peut pas se connecter (étape 8), mais les clients Firebase, eux, peuvent se connecter **et écrire** (VerifyFirebaseToken.php:83-90) : nouveaux `owned_vehicles`, `customer_needs`, `vehicle_checks`. Chaque jour de service ouvert fabrique des données neuves qui entreront en collision avec une restauration, et personne côté staff ne peut voir ce qui s'accumule.
- **Extraire `catalog_cache.db`** d'un téléphone ayant ouvert l'application récemment. CatalogSyncController.php:36-78 renvoie les `id` de production de neuf référentiels (brands, vehicle_models, trims, engine_types, drivetrains, colors, features, part_categories, locations), persistés sous la clé `refs` par catalog_local_cache.dart:126. Les seeders reconstruiront ces tables avec de **nouveaux** id : ce fichier est la seule pièce permettant de rétablir la correspondance ancien id → nouvel id. Il est fragile : catalog_local_cache.dart:48 (changement de version de schéma) ou un `clear()` le jette intégralement. Deux limites : seules les `locations` **actives** y figurent (CatalogSyncController.php:77 `->active()`), et seulement six colonnes.

**Étape 6 — Archiver l'environnement Render avant d'y toucher.** Copier les valeurs de APP_KEY, FIREBASE_CREDENTIALS, MEDIA_DISK, les AWS_* et TASKS_SECRET dans un gestionnaire de mots de passe. Elles n'existent nulle part dans le dépôt.

**Étape 7 — Reconstruire le schéma et les référentiels, depuis le poste (levier de l'étape 4), dans cet ordre exact.**

```
php artisan migrate --force
php artisan db:seed --class=DrivetrainsSeeder --force
php artisan db:seed --class=EngineTypesSeeder --force
php artisan db:seed --class=ColorsSeeder --force
php artisan db:seed --class=CountriesSeeder --force
php artisan db:seed --class=ManufacturersSeeder --force
php artisan db:seed --class=BrandsSeeder --force
php artisan db:seed --class=PartCategoriesSeeder --force
php artisan db:seed --class=FeaturesSeeder --force
php artisan db:seed --class=VehicleModelsSeeder --force
php artisan db:seed --class=TrimsSeeder --force
php artisan db:seed --class=VehicleCheckItemsSeeder --force
php artisan db:seed --class=FiltresHuileBoutiqueSeeder --force
php artisan catalog:import-consommations
```

(L'ordre est celui de DatabaseSeeder.php:35-51 ; vérifier les noms de classes exacts dans `database/seeders/` avant de lancer.)
`FiltresHuileBoutiqueSeeder` est obligatoire ici : les migrations 000005/000006 sont déjà enregistrées et ne le rejoueront pas. Il doit passer **après** `VehicleModelsSeeder`, sinon les dix pièces sont créées sans aucun fitment (FiltresHuileBoutiqueSeeder.php:252-259 résout par `VehicleModel::where('slug', ...)`) : `/api/catalog/parts` rendrait 200 avec dix références pendant que toute recherche de compatibilité rendrait du vide.

**Trois interdits, dans tous les cas :**

- **Ne jamais lancer `php artisan db:seed --force`** (sans `--class`). Sur une base vide, DatabaseSeeder.php:32 conclut que rien n'est peuplé et recrée 80 véhicules, 50 clients, 200 pièces et 80 accessoires de démonstration, mélangés aux vraies références. Le `down()` de la migration 000003 est vide (lignes 48-51) : rien ne les re-supprimera.
- **Ne pas utiliser `ReferenceDataSeeder`** malgré son nom : c'est un jeu d'essai, le dépôt l'écrit lui-même (tests/Feature/GenerationsNommeesTest.php:51). Idem pour `CommerceSeeder`, `CustomerSeeder`, `VehicleSeeder`, que personne n'appelle.
- **Ne pas utiliser `UserSeeder`** : mot de passe `password` en dur (UserSeeder.php:25, :42), adresses `@autoparc.test`, `User::create` au lieu de `firstOrCreate` (lignes 22, 39) donc non rejouable.

**Étape 8 — Recréer le compte administrateur.**
Extraire les lignes 106-146 de DatabaseSeeder dans `database/seeders/StaffSeeder.php` (même garde `SEED_STAFF_PASSWORD`, même liste, même `firstOrCreate`), et remplacer `$this->seedStaff()` de DatabaseSeeder.php:55 par `$this->call(StaffSeeder::class)`. Puis, depuis le poste :

```
SEED_STAFF_PASSWORD=<valeur forte temporaire> php artisan db:seed --class=StaffSeeder --force
php artisan staff:rotate-password admin@autoparc.bf
```

`admin@autoparc.bf` est à DatabaseSeeder.php:126. `staff:rotate-password` tire 24 caractères, les affiche une seule fois et révoque les jetons (RotateStaffPassword.php:56-68) ; `--all` pour les sept comptes. Ne pas laisser `SEED_STAFF_PASSWORD` dans l'environnement Render.

**Étape 9 — Vérifier, dans cet ordre.**

1. Log de déploiement : la ligne de démarrage de `artisan serve` sur `0.0.0.0`. Si elle manque, rien d'autre ne mérite d'être testé.
2. `GET /up` → 200 (première requête : l'instance peut dormir).
3. `GET /api/catalog/part-categories` → 200 avec un JSON non vide.
4. `GET /api/catalog/parts` → **dix** références (les huit SKU de 000005 lignes 47-50 + `90915-YZZD4` et `90915-10001` de 000006), pas deux cents.
5. `GET /api/catalog/vehicles` → liste **vide**. 80 véhicules = quelqu'un a lancé `db:seed`, il faut rejouer `catalog:purge` avant d'aller plus loin.
6. `POST /api/login` avec le compte admin → 200. Attention : la route est plafonnée par `throttle:login`, trois ou quatre essais suffisent à déclencher un 429 qui ressemble à un échec de reprise.
7. Actions → workflow des rappels → Run workflow avec `dry_run = true`. Un 401 signifie que `TASKS_SECRET` diffère entre Render et le secret du dépôt (ScheduledTaskController.php:100-106). À blanc d'abord : la tâche balaie le parc et envoie de vraies notifications.

**Étape 10 — Trois correctifs mobiles à embarquer avant de dire aux utilisateurs que c'est réparé.** Ils ne concernent pas la panne serveur mais ils ont aggravé son vécu, et ils resteront vrais à la prochaine.

- auth_provider.dart:151-154 : `catch (_) { await ApiClient.deleteToken(); }` **supprime le jeton Sanctum sur une simple erreur réseau**. Chaque démarrage à froid pendant la panne a déconnecté un membre du personnel. Ne supprimer que sur un vrai 401, et `return` au lieu de poursuivre vers la branche Firebase.
- auth_provider.dart:373-385 : une panne serveur est annoncée comme « Email ou mot de passe incorrect ». Ajouter `isNetworkFailure` et `isServerError` à la condition.
- vehicle_list_page.dart:141, accessory_list_page.dart:149, part_list_page.dart:166 : `error: paged.error.toString()` affiche le texte anglais brut de Dio. Remplacer par `messageFor(paged.error)`. Et api_client.dart:36-37 n'a **pas** de `sendTimeout` : un envoi de photos contre un serveur muet reste suspendu indéfiniment, pas 30 s.
- À signaler aussi : main.dart:454-459, le bouton « Rafraîchir le catalogue », vide le cache **avant** de recharger. Pendant une panne, il détruit le seul repli hors ligne qui fonctionnait encore (vehicle_admin_repository.dart:70-73). Ne vider qu'après un rechargement réussi.

## 5. Le choix de la base de remplacement

**Recommandation : une base Postgres externe pilotée par une seule variable `DB_URL`, cible par défaut Neon plan gratuit.**

Justification, deux lignes : le projet est déjà prêt — config/database.php:89 lit `'url' => env('DB_URL')` dans le bloc pgsql, et les paramètres de la query string écrasent la configuration (ConfigurationUrlParser.php:48-52 place `getQueryOptions` en dernier), donc `?sslmode=require` suffit sans toucher au code ; et le besoin de pooling est quasi nul puisque `php artisan serve` est mono-processus (ServeCommand.php:112 défaut 1 worker, `PHP_CLI_SERVER_WORKERS` absent de render.yaml et du Dockerfile). Le palier gratuit permanent de Neon, ses quotas et sa politique de mise en veille sont **à confirmer dans sa console** à la création du projet : rien de cela n'est vérifiable depuis le dépôt. Si le budget est de toute façon accepté, rester sur Render Postgres payant est défendable et ne demande aucune modification de fichier — mais le tarif est **à confirmer sur l'écran d'upgrade de `autoparc-db`**, qui seul engage votre compte.

**Ce qui change dans render.yaml**

- Supprimer les lignes **18-37** : les cinq blocs `fromDatabase: name: autoparc-db` (DB_HOST 18-21, DB_PORT 22-25, DB_DATABASE 26-29, DB_USERNAME 30-33, DB_PASSWORD 34-37).
- Supprimer les lignes **94-98** : le bloc `databases:` entier (`plan: free` ligne 96, `databaseName: autoparc` ligne 97).
- Conserver `DB_CONNECTION: pgsql` (lignes 16-17).
- Ajouter :

```yaml
      - key: DB_URL
        sync: false          # chaine de connexion complete, posee a la main
      - key: LOG_CHANNEL
        value: stderr
      - key: QUEUE_CONNECTION
        value: sync
      - key: CACHE_STORE
        value: file
```

- Poser `DB_URL` à la main dans Render, au format `postgres://<user>:<motdepasse>@<hote>/<base>?sslmode=require`, mot de passe rawurlencodé. **Ne pas la nommer `DATABASE_URL`** : aucune occurrence de ce nom dans tout le dépôt, la variable serait ignorée et la connexion retomberait silencieusement sur `env('DB_HOST', '127.0.0.1')` (config/database.php:90).
- Supprimer les anciennes clés DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD de l'environnement du service : `url` a la priorité, mais cinq valeurs mortes garantissent une confusion au prochain incident.

Pourquoi ces trois variables de contexte en plus de `DB_URL` :

- `LOG_CHANNEL=stderr` **d'abord**, c'est ce qui conditionne la valeur de tout le reste. Le canal existe déjà (config/logging.php:105). Aujourd'hui le défaut `stack` → `single` (config/logging.php:21, 57) écrit dans `storage/logs/laravel.log`, à l'intérieur d'un conteneur éphémère sans Shell : les request_id renvoyés au client par bootstrap/app.php:56-69 et AssignRequestId.php:39 ne mènent nulle part.
- `CACHE_STORE=file` retire deux allers-retours SQL de **chaque** requête `/api/*` (le `throttle:api` de bootstrap/app.php:40, cf. section 2). Prendre `file`, pas `array`, qui remettrait les compteurs à zéro à chaque redémarrage. Variante plus étroite et strictement meilleure, à préférer si vous voulez garder un cache applicatif en base : ajouter la clé `'limiter' => 'file'` à config/cache.php (absente aujourd'hui, lue par CacheServiceProvider.php:36) — elle déplace le seul limiteur hors de la base sans toucher au reste.
- `QUEUE_CONNECTION=sync` : la table `jobs` existe mais aucun `queue:work` ne tourne (Dockerfile:60-69) et aucun `dispatch(` n'existe dans `app/`. Le premier `dispatch()` écrirait dans une file que personne ne consommerait jamais.
- Je ne recommande **pas** `SESSION_DRIVER=cookie` sans arbitrage explicite : les données de session partiraient chez le client et une session ne pourrait plus être révoquée côté serveur. Pour une application à comptes de personnel et capacités (bootstrap/app.php:42-46), `file` est le choix sobre. Et de fait l'API n'utilise aucune session (authentification par jeton), seule routes/web.php:5 en consomme une.

Après la bascule, **supprimer `autoparc-db` à la main** dans Render. L'ordre entre la poussée du blueprint et la suppression de la ressource est **à vérifier dans la documentation Blueprint de Render** : rien ici ne permet de dire ce qu'une synchronisation fait d'une base déclarée mais absente, ni l'inverse.

## 6. L'empêchement structurel à corriger

Dockerfile:60-69. Remplacement exact, à appliquer dans le même commit que `healthCheckPath: /up` :

```dockerfile
CMD php artisan config:cache \
    && php artisan route:cache \
    && (php artisan storage:link \
        || echo "ATTENTION : lien de stockage non cree, les photos locales seront en 404") \
    && (php artisan migrate --force --graceful \
        || echo "ATTENTION : migrations non appliquees, base injoignable") \
    && (php artisan db:seed --class=VehicleModelsSeeder --force \
        || echo "ATTENTION : referentiel des modeles non mis a jour") \
    && (php artisan db:seed --class=VehicleCheckItemsSeeder --force \
        || echo "ATTENTION : points de controle non mis a jour") \
    && php artisan serve --no-reload --host=0.0.0.0 --port=${PORT:-8000}
```

Trois choses changent, et rien d'autre.

- `--graceful` sur la ligne 64. L'option existe nativement (MigrateCommand.php:39 : « Return a successful exit code even if an error occurs ») et elle couvre bien l'échec de **connexion**, pas seulement l'échec de migration : `handle()` (lignes 89-102) enveloppe `runMigrations()` dans un `try/catch`, et `prepareDatabase()` est appelé ligne 112, à l'intérieur du try. Aucun effet de bord : `handleMissingDatabase` exige, pour pgsql, le SQLSTATE `08006` **et** que le message cite le nom de la base entre guillemets — un échec d'hôte cite l'hôte, donc pas de `CREATE DATABASE` parasite.
- Le `|| echo` est une ceinture en plus de la bretelle : il rattrape un plantage antérieur au gestionnaire d'erreurs de PHP, et il aligne la ligne sur le style déjà en place lignes 62-68.
- `--no-reload` ligne 69 : sans lui, `PHP_CLI_SERVER_WORKERS` n'est de toute façon pas respecté (ServeCommand.php:112-126 exige les deux). Ajouter `PHP_CLI_SERVER_WORKERS=4` dans render.yaml si vous voulez sortir du mono-processus.

Ce que ce correctif vous donne : le service démarre base morte et sert des 500 **lisibles** (une fois `LOG_CHANNEL=stderr` posé), au lieu de ne rien servir du tout. Et les migrations restent automatiques : dès que la base revient, le démarrage suivant les applique. Le raisonnement est déjà écrit trois fois dans ce dépôt (Dockerfile:50-57, migration 000003:16-21, migration 000005:14-17) — « une API éteinte coûte plus cher qu'un catalogue incomplet ». Il n'avait simplement pas été appliqué à la ligne la plus critique.

Déclarer en même temps `healthCheckPath: /up` dans le bloc du service web. Les deux se tiennent : un health check sans `--graceful` ne sert à rien, puisque le conteneur meurt avant d'avoir un port à sonder. Mais à dire noir sur blanc, sinon vous installez un voyant vert menteur — exactement le défaut que render.yaml:77-92 reproche déjà au faux cron : `/up` rend 200 base morte, donc le service sera déclaré « sain » pendant que 100 % des routes utiles rendent 500. C'est un indicateur de « l'instance vit », pas de « l'application marche ».

**Variante plus robuste, à évaluer sans urgence :** réordonner la chaîne pour démarrer `serve` **d'abord** et lancer migrations et référentiels ensuite, en arrière-plan. Le port est alors disponible en quelques secondes quoi qu'il arrive, ce qu'aucune combinaison de `--graceful` et de `||` ne garantit (un épuisement mémoire ou un signal tue le processus avant tout gestionnaire d'erreurs PHP). Cela demande un script d'entrée plutôt qu'un `CMD` en une ligne, et donc un test local avant d'être poussé — d'où le fait que je ne le mette pas dans le correctif immédiat.

Détail mineur mais trompeur pour qui diagnostique : Dockerfile:40 déclare `EXPOSE 8000` alors que la ligne 69 écoute sur `${PORT:-8000}`. Sans conséquence, mais la seule ligne du dépôt qui annonce un port annonce le mauvais.

## 7. Points restant à vérifier par le propriétaire

1. **L'état réel de `autoparc-db`** sur sa page Render : expirée, suspendue ou absente ; date de suppression annoncée ; présence ou non d'un onglet Backups/Recovery. Les délais de grâce et la possibilité de récupérer les données ne sont attestés par rien d'exploitable ici.
2. **Le point exact de rupture** dans les logs du dernier **démarrage d'instance** : `config:cache` (Dockerfile:60, APP_KEY) ou `migrate` (ligne 64). Toute la section 6 suppose le second.
3. **Le comportement de la périphérie Render** sur le silence de 180 s : onglet Events/Deploys (statut du dernier déploiement) et en-tête `x-render-routing` avec `curl -sv`.
4. **`MEDIA_DISK` dans l'environnement Render** (render.yaml:60-61, `sync: false`). Si elle est restée à `public` (défaut de config/media.php:31), les photos étaient sur le disque éphémère et sont perdues depuis longtemps — il n'y a alors aucun objet à rattacher. Si elle vaut `s3`, lister le compartiment R2 par préfixe avant toute reconstruction.
5. **Les quotas et la politique de veille** de l'hébergeur retenu, dans **sa** console à la création du projet, pas dans une page de tarifs. Idem pour le prix réel d'un Render Postgres payant, sur l'écran d'upgrade de votre compte.
6. **`PGCONNECT_TIMEOUT`** avant d'en faire un correctif : aucun `connect_timeout` n'est borné côté applicatif (le bloc pgsql de config/database.php:87-100 n'a aucune clé `options`, contrairement à `mysql` lignes 62-64 et `mariadb` lignes 82-84 ; `getDsn` de PostgresConnector.php:59-95 ne propage que six clés codées en dur). Test local, sans Shell Render : `docker build -t ap . && docker run --rm -e PGCONNECT_TIMEOUT=5 -e DB_CONNECTION=pgsql -e DB_HOST=10.255.255.1 -e DB_PORT=5432 -e DB_DATABASE=autoparc -e DB_USERNAME=x -e DB_PASSWORD=x -e APP_KEY=... ap php artisan migrate --force`, et chronométrer. Si l'échec tombe à ~5 s au lieu de pendre, c'est validé.
7. **L'effet d'une synchronisation de blueprint** sur une base déclarée mais supprimée, et l'ordre correct entre pousser render.yaml et supprimer la ressource (documentation Blueprint de Render).
8. **Le chemin de reconstruction n'est testé sur aucun Postgres** : .github/workflows/tests.yml:23-24 fait tourner la suite sur SQLite en mémoire. Rien ne prouve que les 50 migrations aboutissent sur le moteur réel. À jouer d'abord contre la base neuve depuis le poste (étape 7), avant tout déploiement.
9. **Mettre en place une sauvegarde réelle**, sans quoi le prochain incident sera identique : un `pg_dump` planifié dans `.github/workflows/`, sur le modèle de reminders.yml. Il aura besoin de ses **propres** secrets de dépôt (chaîne de connexion, et clés R2 si vous y déposez les archives) : les variables `sync: false` de render.yaml:60-73 vivent côté Render et ne sont pas des secrets GitHub. Bénéfice secondaire : chaque exécution laisse une trace datée, ce qui est aujourd'hui la seule chose qui vous aurait dit quand le service est tombé.
