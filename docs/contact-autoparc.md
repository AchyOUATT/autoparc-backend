# Numéro de contact AutoParc

Dans l’application, se connecter comme administrateur ou responsable, puis
ouvrir le menu **Paramètres · Contact AutoParc**. Saisir le numéro avec son
indicatif international (par exemple `+226 75 57 04 84`) et enregistrer.
Les espaces, parenthèses, points et tirets sont retirés avant stockage.
Le numéro doit commencer par `+`, suivi de 8 à 15 chiffres.

Un seul réglage alimente les appels et WhatsApp AutoParc : le menu Contact,
la fiche véhicule du parc, la commande du panier et les pages web partagées.
Les coordonnées des vendeurs partenaires restent propres à leurs annonces.

## Intégration

Cette préparation reste locale sur `feature/configurable-autoparc-contact`,
dans les dépôts serveur et mobile. Elle est indépendante de la branche des
annonces de particuliers. Rien n’est publié ni envoyé sur GitHub.

1. Intégrer les changements serveur et appliquer la migration
   `2026_10_28_000001_create_app_settings_table.php` sur l’environnement choisi.
2. Intégrer les changements Flutter et distribuer une nouvelle version de
   l’application. Les anciennes versions portent encore le numéro en dur.
3. Ouvrir les paramètres avec un compte habilité et enregistrer le numéro.

Après cette première intégration, changer le numéro ne demande plus de
reconstruire ni de republier l’application.

L’application recharge le contact au démarrage, au retour au premier plan
et à l’ouverture de la feuille Contact. Une sauvegarde réussie actualise
immédiatement le numéro dans la session du responsable. Hors connexion,
le dernier numéro confirmé reste disponible ; à la toute première ouverture
sans réseau, le numéro historique sert de repli.

Les réponses de l’API contact ne sont pas mises en cache. Les pages web de
partage conservent leur cache HTML existant de cinq minutes : un cache
intermédiaire peut donc afficher brièvement les anciennes coordonnées.

## Contrat serveur

- `GET /api/app/contact` : lecture publique du numéro AutoParc.
- `GET /api/settings/contact` : lecture pour le formulaire du back-office.
- `PUT /api/settings/contact` avec `{"phone":"+22675570484"}` : modification.
- Réponse : `{"phone":"+22675570484"}`.
- Le back-office exige une session Sanctum active, du personnel et la capacité
  `manage-settings`, accordée uniquement aux rôles `admin` et `manager`.
- Le réglage est conservé en base dans `app_settings`, clé `contact_phone`.
- Avant le premier enregistrement, les coordonnées de boutique configurées
  sur le serveur restent le repli ; une modification enregistrée prend priorité.

Les vérifications automatisées couvrent les droits, la validation, la
persistance, le cache mobile hors connexion, les échecs de sauvegarde et les
liens d’appel/WhatsApp réellement transmis par les écrans à la plateforme simulée.
