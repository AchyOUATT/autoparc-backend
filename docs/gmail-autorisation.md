# Autoriser le compte Gmail expéditeur

Cette procédure autorise seulement la boîte expéditrice AutoParc. Elle ne change
pas l’authentification Firebase des clients, les comptes partenaires ou leurs
rôles. Les acheteurs et les vendeurs n’ont pas à autoriser l’accès à leur Gmail.

Le transport `gmail_api` envoie en HTTPS, compatible avec le service Render Free.
Il utilise l’autorisation Google du compte expéditeur, pas un mot de passe Gmail
ou un mot de passe d’application. Le helper local ne procède à aucun envoi.

## Préparer le projet Google du propriétaire

1. Dans un projet Google Cloud contrôlé par le propriétaire AutoParc, activer
   **Gmail API** et configurer Google Auth Platform.
2. Préparer un client OAuth **Application Web** dédié à l’envoi serveur. Ne pas
   réutiliser les clients Android/Firebase ou les identifiants d’un connecteur.
3. Ajouter exactement cette URI de redirection autorisée :
   `http://127.0.0.1:8765/oauth2/callback`.
4. Prévoir les autorisations `openid`, `email` et
   `https://www.googleapis.com/auth/gmail.send`. Le helper refuse un accès
   supplémentaire à la lecture ou à la modification de la boîte.
5. Télécharger le JSON du client OAuth et le conserver **hors de tout dépôt Git**,
   dans un dossier privé du propriétaire. Le secret du client ne doit jamais
   être publié dans Git, un ticket ou un journal.

Le mode de consentement **External / Testing** limite à sept jours les jetons de
renouvellement de ce parcours, car il demande aussi l’envoi Gmail. Il convient à
un essai, pas à une configuration durable de production. Avant activation
durable, suivre les exigences Google de publication et de validation de
l’application ; leur applicabilité dépend du projet et des exceptions prévues
par Google. Publier l’application ne dispense pas automatiquement d’une
validation demandée par Google. Si le mode change, renouveler l’autorisation
avec le projet correctement configuré.

Références : [OAuth serveur Google](https://developers.google.com/identity/protocols/oauth2/web-server),
[expiration des jetons](https://developers.google.com/identity/protocols/oauth2#expiration),
[permissions Gmail](https://developers.google.com/workspace/gmail/api/auth/scopes).

## Exécuter l’autorisation locale

Le helper ne dépend que de Python 3.10 ou plus récent. Le dossier contenant le
JSON et la destination doivent être hors de tout checkout Git, même si un
fichier aurait été ignoré par `.gitignore`. Le dossier de destination doit déjà
exister. Aucun fichier existant n’est écrasé.

Exemple PowerShell, après création d’un dossier privé dans le répertoire
temporaire de l’utilisateur et téléchargement du JSON dans ce dossier :

```powershell
python scripts/authorize_gmail.py `
  --client-json "$env:TEMP\autoparc-mailer\client-secret.json" `
  --sender "expediteur@example.com" `
  --output-env "$env:TEMP\autoparc-mailer\gmail.env"
```

Remplacer l’adresse d’exemple par la boîte qui doit envoyer les messages. Le
helper ouvre le navigateur ; se connecter à cette boîte et accorder les seules
permissions demandées. L’option `--no-browser` permet d’ouvrir manuellement
l’URL Google affichée. Il faut effectuer cette étape sur la même machine que
le helper, qui écoute uniquement sur `127.0.0.1:8765` pendant dix minutes.

Le callback contrôle l’état anti-CSRF, son hôte et son chemin. L’échange utilise
PKCE S256. Avant enregistrement, le helper contrôle les permissions accordées et
l’adresse Google vérifiée ; une autre boîte est refusée. Le code, le secret du
client et les jetons ne sont jamais affichés. Une autorisation nouvellement
obtenue mais inutilisable est révoquée au mieux, sans exposer son contenu.

## Activer dans Render

Le fichier privé contient :

- `GMAIL_CLIENT_ID`
- `GMAIL_CLIENT_SECRET`
- `GMAIL_REFRESH_TOKEN`
- `MAIL_FROM_ADDRESS`, égal au compte Google autorisé
- `MAIL_FROM_NAME=AutoParc`
- `MAIL_MAILER=gmail_api`

Sous Windows, le helper retire l’héritage des droits du fichier et n’accorde
l’accès qu’au compte Windows actuel avant d’y écrire les secrets. Sous Unix,
le fichier est créé en mode `0600`. Un échec de protection empêche l’écriture.

Importer ces variables privées dans **Environment** du service Render, en
préservant les autres variables. Ne pas copier leur contenu dans une
conversation. Déployer le service, contrôler `/up` et tester l’envoi seulement
avec un compte et un destinataire explicitement autorisés. Supprimer le fichier
local après son transfert sûr et garder les identifiants dans le gestionnaire
de secrets prévu par le propriétaire.

Un défaut de renouvellement, une révocation Google ou un changement de politique
du compte nécessite une nouvelle autorisation. Les clients Google peuvent
révoquer l’accès à tout moment depuis leur compte.

## Tests hors ligne

```sh
python -m unittest discover -s scripts/tests -p 'test_*.py'
```

Les tests couvrent PKCE, les callbacks refusés, les permissions, la vérification
du compte expéditeur, la confidentialité des erreurs et des sorties, la
protection du fichier et le refus d’écrire dans Git. Tous les échanges Google
sont simulés ; aucun email ou appel OAuth réel n’est nécessaire.
