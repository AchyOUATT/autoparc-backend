# Récupération d’un mot de passe

Les mots de passe clients sont gérés par Firebase depuis l’application. Les
comptes partenaires et du personnel utilisent le serveur Laravel. Une
récupération ne change jamais le rôle, l’approbation, l’activation ou le lien
Firebase du compte ; elle ne donne donc aucun garage à un partenaire.

## Demande depuis l’application

`POST /api/auth/password/forgot`, public, corps JSON :

```json
{"email":"compte@example.org","account_type":"partner","locale":"fr"}
```

`account_type` accepte seulement `partner` ou `staff`, `locale` accepte `fr` ou
`en` (facultatif). Les clients utilisent directement la récupération Firebase.
Une adresse inconnue, un autre espace ou un compte inactif donnent la même
réponse 200 `password_reset_requested`. Le délai Laravel de 60 secondes entre
deux liens ne révèle pas non plus l’existence du compte. Les plafonds de route
par adresse, IP et globaux rendent 429 ; l’adresse email est hachée dans la clé
du limiteur. Les adresses sont rapprochées sans tenir compte de la casse ; des
doublons historiques différant uniquement par la casse sont refusés sans
choisir arbitrairement un compte.

Le lien de l’email ouvre le formulaire FR/EN `GET /password/reset/{token}`.
Il est préfixé par `APP_URL`, jamais par l’en-tête Host du demandeur. Une
signature relative lie le jeton, l’adresse, l’espace, la langue et la date
d’expiration : changer `account_type` ne permet pas de réutiliser un ancien
lien après un changement de rôle. Le POST relit ces valeurs dans la requête
signée, jamais dans les champs du formulaire. Le formulaire utilise CSRF, ne
charge aucune ressource externe et porte `no-store`, `no-referrer`, une CSP
restrictive et `noindex`. Aucun compte n’est connecté automatiquement.

Le broker Laravel stocke seulement le hash du jeton dans
`password_reset_tokens`, avec expiration à 60 minutes et utilisation unique.
Le POST verrouille le compte puis le jeton dans une transaction. Il exige
12 caractères, lettres et chiffres, refuse les octets nuls et les mots de passe
dépassant 72 octets (limite bcrypt). Une réussite remplace le mot de passe,
renouvelle le jeton de session web et révoque tous les jetons Sanctum du compte.
Les statuts d’un partenaire restent applicables à sa prochaine connexion.

## Activer l’envoi réel

Renseigner `APP_URL` en HTTPS et un expéditeur réel (`MAIL_FROM_ADDRESS` et
`MAIL_FROM_NAME`). Pour SMTP, renseigner `MAIL_MAILER=smtp`, `MAIL_HOST`,
`MAIL_PORT` et les paramètres d’authentification/chiffrement du prestataire
(`MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_SCHEME`, ou `MAIL_URL`). Le délai
réseau SMTP est borné à `MAIL_TIMEOUT`, 10 secondes par défaut. Les transports
API exigent aussi leur bibliothèque optionnelle et leurs clés configurées ;
aucune nouvelle dépendance de prestataire n’est installée par ce lot.

`log`, `array`, un repli vers `log`, un serveur SMTP local, un expéditeur
`hello@example.com` ou une URL de production non HTTPS ne constituent pas un
envoi disponible. Dans ce cas, **toutes** les demandes rendent 503
`password_reset_unavailable`, sans rechercher de compte ni créer de jeton.
L’application propose le contact AutoParc défini dans le back-office.
Cette vérification est un contrôle de configuration, pas un test de livraison.
Une panne réelle du transport conserve la réponse générique, invalide le jeton
non livré et écrit seulement `password_reset_delivery_failed`, sans adresse,
mot de passe, lien ou corps d’email. Vérifier la délivrabilité avec un compte de
test autorisé après configuration du prestataire. Ne pas activer de journal
de corps d’email et masquer les URL de réinitialisation dans les journaux HTTP
et les outils d’analyse.

## Partenaire sans email : récupération assistée

L’application affiche le contact AutoParc ; un téléphone déclaré n’est pas une
preuve de possession et aucun SMS/OTP n’est inventé. Le support doit vérifier
l’identité de la personne et son droit sur le compte avant toute rotation,
retrouver son identifiant utilisateur exact et utiliser une console privée :

```sh
php artisan partner:rotate-password 123
```

Cette commande refuse tout compte client ou du personnel. Elle génère un mot
de passe aléatoire de 24 caractères, l’affiche une seule fois, révoque les
sessions et invalide les liens email en attente. Elle conserve le rôle, l’email
facultatif, `is_active`, `firebase_uid` et tous les statuts du compte partenaire.
Un compte suspendu reste suspendu. Transmettre le nouveau mot de passe par un
canal privé après vérification ; ne pas conserver la sortie dans un ticket,
une capture ou un journal. Ce mot de passe ne bénéficie pas d’une expiration
automatique ou d’une obligation de changement à la connexion.

La commande existante `staff:rotate-password` reste réservée au personnel ;
elle invalide également les liens de récupération en attente. Les tests
utilisent exclusivement des comptes jetables en base SQLite et des
notifications simulées : aucun compte réel n’est modifié, aucun email réel
n’est envoyé.

Référence du broker et du flux Laravel :
[Laravel 13 — Resetting passwords](https://laravel.com/framework/docs/13.x/passwords).
