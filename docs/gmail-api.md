# Envoi des emails par l’API Gmail

Le transport `gmail_api` envoie les emails Laravel avec l’API Gmail en HTTPS.
Il ne dépend pas des ports SMTP 465/587, bloqués sur les services Render Free.
Aucune nouvelle bibliothèque n’est nécessaire : Laravel HTTP et Symfony MIME
déjà présents assurent les requêtes et le message multipart.

## Configuration

```ini
MAIL_MAILER=gmail_api
MAIL_FROM_ADDRESS=expediteur@example.org
MAIL_FROM_NAME=AutoParc
MAIL_TIMEOUT=10
GMAIL_CLIENT_ID=
GMAIL_CLIENT_SECRET=
GMAIL_REFRESH_TOKEN=
```

L’adresse expéditrice doit être celle du compte Google ayant autorisé l’envoi.
Les trois paramètres OAuth sont des secrets de l’environnement Render ; ils ne
doivent pas figurer dans le dépôt, les tickets, les journaux ou une capture.
Le transport ne repose pas sur un mot de passe d’application Gmail. La
procédure d’autorisation de l’expéditeur est séparée de la connexion Firebase
des clients et ne modifie aucun rôle de compte AutoParc.

Le projet Google doit avoir l’API Gmail activée et une autorisation OAuth avec
la portée `https://www.googleapis.com/auth/gmail.send`. L’autorisation initiale
doit fournir un jeton de renouvellement. Ce jeton peut être révoqué ou devenir
inutilisable : ne pas considérer sa présence dans l’environnement comme une
preuve de délivrabilité.

Suivre la [procédure d’autorisation](gmail-autorisation.md) pour préparer ces
secrets sans les afficher ni les transmettre dans la conversation.

## Comportement

Le serveur renouvelle un jeton d’accès auprès de
`https://oauth2.googleapis.com/token`, puis transmet le message MIME en
base64url à `https://gmail.googleapis.com/gmail/v1/users/me/messages/send`.
Le jeton d’accès reste uniquement dans la mémoire du transport jusqu’à son
expiration ; aucune table ni cache persistant ne reçoit les jetons OAuth.
Si Google renvoie des portées au renouvellement, seules `gmail.send` et les
portées d’identité `openid`/`email` sont acceptées ; une autorisation plus large
de lecture ou de modification de boîte mail est refusée.

Le message conserve ses parties texte/HTML, ses pièces jointes et ses
destinataires To/Cc/Bcc. Symfony supprime normalement Bcc lors de la
sérialisation SMTP ; le transport le rétablit uniquement dans le message
transmis à Gmail, qui détermine les destinataires à partir des en-têtes MIME.
Une enveloppe personnalisée qui ne correspond pas aux destinataires du
message est refusée, ainsi qu’une adresse From/Sender différente du compte
configuré.

Les requêtes utilisent des délais bornés, TLS et aucune redirection. Aucun
envoi n’est automatiquement réessayé : un délai dépassé après acceptation par
le prestataire ne doit pas produire un doublon. Une réponse sans identifiant
Gmail de message n’est pas considérée comme un succès. Les erreurs exposées
ne reprennent ni corps de réponse Google, ni identifiant OAuth, ni jeton, ni
contenu d’email. Le parcours de récupération invalide son jeton non livré et
conserve sa réponse générique avec le contact d’assistance.

L’absence d’un des trois paramètres OAuth garde le parcours serveur en 503
`password_reset_unavailable`, sans créer de jeton de récupération. Activer
`MAIL_MAILER=gmail_api` seulement lorsque l’autorisation du compte est prête,
puis vérifier un envoi avec une adresse de test autorisée.

Les tests `GmailApiTransportTest` simulent toutes les requêtes Google : aucun
email réel ni compte Google n’est utilisé.

Sources officielles :

- [Gmail : créer et envoyer des messages](https://developers.google.com/workspace/gmail/api/guides/sending)
- [OAuth serveur et accès hors connexion](https://developers.google.com/identity/protocols/oauth2/web-server#offline)
- [Restrictions Render Free](https://render.com/docs/free#other-limitations)
