# Stocker les photos du catalogue

## Pourquoi ce n'est pas réglé par défaut

Le disque de Render est **éphémère**. Tout fichier écrit dans `storage/app/public`
disparaît au déploiement suivant — et une migration de données en déclenche un.

Concrètement : tu charges les photos de tes filtres, elles s'affichent, puis la
prochaine mise en ligne les efface. La base garde les lignes `media`, qui
pointent alors sur des fichiers absents. Aucun message, aucune erreur : juste
des vignettes cassées, et personne ne sait depuis quand.

Les photos vont donc sur un **stockage objet**, extérieur au serveur.

## Le choix : Cloudflare R2

Compatible S3, 10 Go gratuits, et surtout **aucun frais de sortie**. C'est ce
dernier point qui tranche : S3 et Backblaze facturent la bande passante, et un
catalogue de pièces consulté depuis des mobiles à Ouagadougou en consomme.

> **À vérifier en premier.** L'activation de R2 demande d'enregistrer un moyen
> de paiement, même si l'usage reste dans le palier gratuit. Si c'est bloquant,
> dis-le avant de créer le compte : d'autres hébergeurs d'images (Cloudinary,
> ImageKit, Supabase) ont un palier gratuit sans carte, au prix d'un quota plus
> serré. Le code ne change pas tant que le stockage parle S3.

## 1. Le compte

1. Aller sur `dash.cloudflare.com/sign-up`.
2. Créer le compte avec une adresse e-mail, puis valider le lien reçu.
3. Aucun domaine n'est nécessaire à cette étape : passer les écrans qui en
   proposent un.

## 2. Activer R2

1. Menu de gauche → **R2 Object Storage**.
2. Suivre l'activation, qui demande le moyen de paiement mentionné plus haut.
3. Noter l'**ID de compte** affiché dans le panneau de droite. Il sert à
   construire l'adresse de l'API :
   `https://<ID de compte>.r2.cloudflarestorage.com`

## 3. Le compartiment

1. **Create bucket**.
2. Nom : `autoparc-media`.
3. Emplacement : indiquer **Europe (EU)** — c'est le plus proche de l'Afrique de
   l'Ouest parmi les zones proposées.
4. Créer.

## 4. Rendre les photos publiques

Une fiche produit doit s'afficher sans authentification.

Dans le compartiment → **Settings** → **Public access**, deux voies :

- **Sous-domaine `r2.dev`** : activable en un clic, donne tout de suite une
  adresse publique. Cloudflare la limite en débit et la déconseille pour la
  production, mais elle convient pour démarrer.
- **Domaine personnalisé** : préférable à terme, demande un domaine géré par
  Cloudflare.

Noter l'adresse publique obtenue : c'est `AWS_URL`.

## 5. Le jeton d'accès

1. Page R2 → **Manage R2 API Tokens** → **Create API token**.
2. Permissions : **Object Read & Write**.
3. Portée : **le seul compartiment `autoparc-media`**, pas tout le compte.
4. Durée : illimitée, ou une échéance que tu notes quelque part.
5. Créer.

Cloudflare affiche alors l'**Access Key ID** et la **Secret Access Key**.

> La clé secrète n'est montrée **qu'une fois**. Colle-la directement dans Render
> à l'étape suivante. Ne la mets ni dans un fichier du dépôt, ni dans un message,
> ni dans une conversation avec moi : je n'en ai pas besoin pour que ça marche,
> et une clé qui circule est une clé à révoquer.

## 6. Les variables sur Render

Tableau de bord Render → service `autoparc-backend` → **Environment** :

| Variable | Valeur |
|---|---|
| `MEDIA_DISK` | `s3` |
| `AWS_ACCESS_KEY_ID` | l'Access Key ID du jeton |
| `AWS_SECRET_ACCESS_KEY` | la Secret Access Key |
| `AWS_BUCKET` | `autoparc-media` |
| `AWS_DEFAULT_REGION` | `auto` |
| `AWS_ENDPOINT` | `https://<ID de compte>.r2.cloudflarestorage.com` |
| `AWS_URL` | l'adresse publique de l'étape 4 |
| `AWS_USE_PATH_STYLE_ENDPOINT` | `true` |

`AWS_DEFAULT_REGION` et `AWS_USE_PATH_STYLE_ENDPOINT` sont déjà posées dans
`render.yaml` ; les six autres sont en `sync: false` et se remplissent à la main.

Enregistrer déclenche un redéploiement.

## 7. Vérifier

Depuis l'application, ouvrir une pièce, ajouter une photo, puis :

1. La vignette s'affiche sur la fiche.
2. L'adresse de l'image commence par `AWS_URL`, pas par celle de l'API.
3. **Le test qui compte** : déclencher un redéploiement — pousser n'importe quel
   commit — et rouvrir la fiche. La photo doit toujours être là. C'est
   exactement ce qui échouait avant.

## Ce qui se passe si rien n'est configuré

`MEDIA_DISK` vaut `public` par défaut, donc le disque local. Rien ne plante :
les envois fonctionnent, les photos s'affichent, et elles disparaissent au
déploiement suivant. C'est le comportement d'avant, conservé pour que
l'application reste utilisable en développement.

Les photos déjà enregistrées gardent leur disque d'origine, inscrit ligne par
ligne dans la table `media`. Basculer la configuration ne les rend donc ni
illisibles ni introuvables à la suppression — mais celles du disque local
resteront condamnées à disparaître.
