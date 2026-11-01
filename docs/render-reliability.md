# Render : santé du service, archives privées et tâches planifiées

Cette procédure décrit les changements versionnés dans le dépôt. Leur
présence dans `render.yaml` ne prouve pas qu’ils ont été appliqués à un service
déjà créé. Un déploiement ou une synchronisation du Blueprint reste une
opération distincte. Les paramètres de version mobile ne changent pas dans ce
lot : dernière version annoncée `104`, minimum `92`. Le build `107` ne doit
pas être annoncé comme publié tant que sa disponibilité sur Play n’est pas
confirmée.

## Santé du service

Le service web déclare `healthCheckPath: /up`. Ce point de contrôle Laravel
permet à Render de constater que l’application démarre et traite une requête.
Une réponse HTTP 200 sur `/up` ne certifie ni l’exécution des tâches planifiées,
ni l’exhaustivité du catalogue, ni la disponibilité des organismes de données.

Après application du changement, contrôler dans Render que le chemin de santé
est bien `/up`, puis examiner le résultat du déploiement et les journaux du
service. Le canal `stderr` déjà configuré rend ces journaux consultables sans
accès au fichier local du conteneur.

## Archives des sources de consommation

`CONSUMPTION_SOURCE_ARCHIVE_STORE=database` sélectionne le stockage dans la
base de l’application. Sur ce service, `DB_CONNECTION=pgsql` le place dans
PostgreSQL. Le même réglage est le défaut de la configuration applicative ;
la déclaration Render rend le choix explicite.

La table privée `consumption_source_archives` porte :

- l’empreinte SHA-256 du contenu original, utilisée comme clé primaire ;
- le contenu compressé en gzip puis encodé en base64 ;
- la taille du contenu original et sa date d’archivage.

Un contenu identique ne doit pas créer plusieurs copies. La provenance d’une
référence conserve le descripteur permettant de retrouver l’archive. À la
lecture, le service décode le base64, décompresse le gzip et contrôle l’empreinte
des octets retrouvés. Une archive absente, illisible ou dont l’empreinte ne
correspond pas doit être signalée ; elle ne devient pas un document vérifié.

Le descripteur `provenance.archive` contient `status`, `store`, `key`,
`sha256`, `encoding`, `original_size` et `verified_at` lorsque l’archive est
relue avec succès. Ce `verified_at` atteste l’intégrité des octets archivés ;
il ne vérifie ni la méthode de la source, ni la correspondance d’un véhicule.
Une ancienne preuve absente garde le statut `unknown`.

La base conserve ces pièces après le remplacement d’un conteneur. Le disque
local de Render reste éphémère. Le compartiment des photos peut être public et
répond à un autre besoin : il n’est pas utilisé pour les pièces de provenance.
Il n’y a ni nouvelle API publique d’archives, ni nouveau service de stockage
à provisionner dans ce lot.

La compression et la déduplication limitent le volume. L’archivage utilise
cependant l’espace disponible de la base : son quota, sa conservation et ses
sauvegardes doivent couvrir `consumption_source_archives`, les références et
le journal des imports. L’absence de nouveau service ne signifie pas une
capacité de stockage illimitée ou une sauvegarde garantie par le plan utilisé.

### Consultation par un opérateur

La récupération d’une archive est une opération interne, réservée à un
opérateur ayant un accès autorisé à l’application ou à sa base. Retrouver le
descripteur dans la provenance de la référence, puis utiliser le service
interne d’archives pour lire et vérifier les octets. Ne pas créer de lien
public, de route de téléchargement ou de copie dans le compartiment des
photos pour cette consultation. L’accès à une archive ne constitue pas à lui
seul une validation de la cote pour un véhicule.

Depuis un contexte Laravel interne autorisé, le service s’utilise ainsi, avec
l’empreinte réellement lue dans la provenance :

```php
$archives = app(\App\Services\ConsumptionSourceArchive::class);
$bytes = $archives->read($sha256);
$descriptor = $archives->describe($sha256);
```

`read()` rend les octets vérifiés ou lève une exception. Cette procédure ne
suppose pas que le plan Render choisi fournisse un terminal ; l’accès de
maintenance ou à la base doit déjà être autorisé et disponible.

La restitution des octets ne doit pas être confondue avec le hachage
d’identité d’une configuration. Le SHA-256 d’archive décrit un fichier précis ;
les clés d’identité et de révision décrivent les références qui en sont tirées.

### Archives antérieures

Une archive restée uniquement dans `consumption-sources/<sha256>` sur un
ancien conteneur peut déjà être perdue. La migration ne peut pas la reconstruire
à partir de son empreinte. Les données et sélections existantes sont
conservées, mais leur contenu source demeure indisponible tant qu’il n’a pas
été retrouvé et vérifié.

Lorsqu’un fichier exploitable est retrouvé ou téléchargé à nouveau, son
réimport peut rétablir l’archive en base. Le réimport est idempotent et ne doit
ni modifier les valeurs d’une référence existante, ni changer la révision
sélectionnée par le propriétaire. Un fichier republié avec d’autres octets
est une autre archive ; il ne rétablit pas artificiellement l’ancien fichier.
Si l’ancien fichier local est encore présent, `recoverLegacyLocal($sha256)`
peut le transférer en base après contrôle d’empreinte ; un fichier absent rend
`null`, un fichier divergent provoque une erreur. La taille originale maximale
d’une archive est actuellement bornée à 16 Mio par la configuration applicative.

Le journal d’import porte désormais le stockage d’archives utilisé. Un import
récent antérieur à cette évolution ne suffit donc pas à éviter le prochain
téléchargement. Le premier démarrage relance NRCan pour archiver les octets
encore disponibles, puis retrouve sa garde habituelle de 180 jours. Une archive
manquante ou corrompue invalide également cette garde. Cette reprise ne recrée
pas un ancien fichier dont le contenu a changé depuis à la source.

## Configuration des tâches planifiées

Les workflows `.github/workflows/reminders.yml` et `fuel-anomaly.yml`
utilisent les réglages de dépôt GitHub suivants :

| Nom | Configuration attendue |
| --- | --- |
| `TASKS_URL` | Facultatif : surcharge de l’URL HTTPS complète des rappels. Sans secret, les workflows utilisent `https://autoparc-backend.onrender.com/api/tasks/reminders`. Ce n’est ni l’URL du tableau de bord Render, ni seulement la racine du service. |
| `TASKS_SECRET` | Même secret partagé que la variable `TASKS_SECRET` du service Render. Saisir sa valeur dans les interfaces sécurisées, sans l’écrire dans le dépôt ni dans les journaux. |

Dans Render, retrouver la variable du service existant ; dans GitHub, ouvrir
les paramètres du dépôt, puis **Secrets and variables → Actions** et ajouter
`TASKS_SECRET` ; `TASKS_URL` ne sert qu’à viser une autre adresse. Ne pas
régénérer seulement le secret d’un des deux côtés : les appels
utilisent l’en-tête `X-Task-Token` et seraient refusés si les valeurs divergent.

Lors de cette intervention, l’accès API nécessaire à l’écriture des secrets
GitHub était refusé (`GET` de la clé publique du dépôt : HTTP 403). La mise à
jour par API n’a donc pas été effectuée. L’utilisateur doit renseigner ces
entrées nécessaires dans l’interface GitHub ; aucune valeur secrète ne figure dans
cette procédure.

Après configuration, lancer manuellement les workflows en mode `dry_run`
pour vérifier l’authentification et le calcul sans envoi de notifications.
Contrôler leur résultat HTTP et la sortie de commande dans Actions. Une
réponse de `/up`, ou le succès du workflow de réveil, ne remplace pas cette
vérification des tâches.

## Vérifications après application

1. Le déploiement applique la migration d’archives et l’application démarre.
2. Le contrôle de santé Render utilise `/up` et reçoit une réponse HTTP 200.
3. Une archive de source est relue par le service interne avec la même taille
   et la même empreinte que les octets importés ; aucun accès public n’est créé.
4. Un réimport identique ne duplique ni l’archive ni les références et ne
   modifie pas une sélection existante.
5. Les deux workflows planifiés réussissent en mode `dry_run` après saisie
   des secrets GitHub, avec leurs résultats consultables dans Actions.

Ces étapes décrivent la validation à effectuer après application. Elles ne
constituent pas une déclaration de déploiement, de sauvegarde vérifiée ou de
publication sur Play.

## Validation du correctif

- Suite backend : 1 247 tests réussis, 6 980 assertions.
- Groupe archivage, imports et tâches sur PostgreSQL : 68 tests réussis,
  296 assertions.
- Source incompressible de 16 Mio : archivage et relecture vérifiés dans un
  sous-processus limité à 192 Mio de mémoire PHP ; pic mesuré d’environ 154 Mio.
  Une décompression hostile est refusée sans épuiser cette limite.
- YAML des workflows et du Blueprint, syntaxe des scripts shell et format PHP
  vérifiés. Ces contrôles locaux ne remplacent pas les essais à blanc distants.
