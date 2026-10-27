# Vente de véhicules entre particuliers — préparation locale

Cette fonction est isolée du stock AutoParc et de l’espace professionnel.
Aucun accompagnement par un partenaire, aucune commission ni aucun transfert
de contacts vers un partenaire n’est prévu.

## Parcours livré

- Compte client Firebase existant, sans création d’un compte partenaire.
- Une annonce en cours gratuite par compte : les brouillons et annonces à
  corriger occupent aussi cette place. Retirer ou marquer vendu libère la place.
- Création directe ou préremplissage choisi depuis « Mon garage ».
- Informations publiques : titre, année, kilométrage, prix XOF, ville,
  énergie/boîte facultatives, description, nom affiché, téléphone vérifié et photos.
- Le garage reste privé : aucun VIN, immatriculation, assurance, historique de
  carburant ou entretien n’est copié dans l’annonce.
- Envoi après au moins une photo, vérification du téléphone et consentement à
  publier le contact. La preuve téléphonique vient exclusivement du jeton signé
  Firebase, jamais d’un numéro déclaré dans le formulaire.
- Validation/refus par le personnel autorisé à gérer le catalogue. Un refus est
  expliqué au vendeur. Une annonce publiée peut aussi être retirée par ce personnel.
- Modifier le texte, le prix ou les photos retire la publication et demande une
  nouvelle validation. Le numéro de révision empêche une décision sur une version
  que le vendeur a changée depuis l’ouverture de la liste de validation.
- États : brouillon → en attente → publiée ; refus → à corriger ; retrait ou vente
  → clôturée. Une annonce vendue ou retirée ne se rouvre pas : on en crée une autre.
- Consultation publique distincte, contact téléphone/WhatsApp et page de partage.
- Photos limitées à 10, 2048 × 2048 pixels et 9 Mo chacune. Le serveur les réencode
  en JPEG pour supprimer les métadonnées EXIF/GPS. La première sert de couverture.
- Suppression du compte : cascade des annonces et enregistrements photo, puis
  nettoyage des fichiers après validation de la transaction.

## Fonction désactivée par défaut

Le serveur répond 404 sur les routes de cette fonction tant que
`PRIVATE_SALES_ENABLED` ne vaut pas `true`. Les contrôles d’authentification
peuvent rendre 401 avant ce contrôle pour une requête non authentifiée.

L’application ne montre ni entrée ni route de cette fonction sans la constante
de compilation `ENABLE_PRIVATE_SALES=true`. Cela permet d’examiner les changements
avant de décider de leur intégration.

## Mise en route sur une base locale jetable

1. Installer les dépendances verrouillées, copier `.env.example` en `.env` et
   générer la clé Laravel. Utiliser uniquement une base de développement.
2. Ajouter `PRIVATE_SALES_ENABLED=true`, une adresse `APP_URL` locale correcte et
   la configuration Firebase de développement. Activer GD dans PHP.
3. Exécuter `php artisan migrate`, `php artisan storage:link`, puis lancer l’API.
4. Dans la copie mobile : `flutter pub get`, puis lancer avec
   `--dart-define=ENABLE_PRIVATE_SALES=true` et
   `--dart-define=API_BASE_URL=http://ADRESSE_LOCALE:8000/api`.
5. Sur Firebase de développement, activer l’authentification téléphonique et
   configurer les applications Android. Utiliser d’abord les numéros fictifs et
   codes de test configurés dans Firebase ; les tests automatisés n’envoient aucun SMS.

La vérification ajoute le téléphone au compte client déjà connecté. Elle ne crée
pas un second compte et ne connecte pas le vendeur sous une autre identité.

## Contrat d’API ajouté

La saisie véhicule utilise les références des formulaires existants. La création
et la modification demandent `brand_id`, puis soit `vehicle_model_id`, soit
`model_libre` (80 caractères). `trim_id`, `engine_type_id`, `drivetrain_id` et
`color_id` sont facultatifs. `year` et `mileage_km` restent obligatoires.
`transmission` accepte `manual`, `automatic`, `cvt`, `dual_clutch`, `single_speed`.

Le serveur contrôle l’appartenance du modèle à la marque et de la finition au
modèle. Les références inactives ne peuvent pas être nouvellement choisies ; une
annonce qui les possède déjà peut les conserver. `title`, `fuel_type` et
`vehicle_identity` sont calculés par le serveur et ne sont pas des champs de
saisie. Les libellés publics sont un instantané : un renommage du catalogue ne
modifie pas une annonce déjà validée. Aucun défaut de finition n’est pris pour
une caractéristique constatée du véhicule.

La migration additive `2026_10_27_000002` garde lisibles les premiers brouillons
locaux ; leurs références devront être sélectionnées à la prochaine modification.

- Lecture publique : `GET /api/private-listings`, `GET /api/private-listings/{id}`.
- Gestion client Firebase : `GET/POST /api/my/private-listings`,
  `GET/PUT /api/my/private-listings/{id}`.
- Actions client : `POST .../{id}/submit`, `withdraw`, `sold`.
- Photos client : `POST .../{id}/photos`, `DELETE .../{id}/photos/{photo}`.
- Personnel : `GET /api/private-listing-reviews?status=pending|published|rejected`,
  `POST /api/private-listing-reviews/{id}/approve|reject`.
- Décision : `revision` obligatoire ; un refus demande aussi `reason`.
- Page de partage : `GET /annonces/{id}` ; 404 si l’annonce n’est plus publiée.

Les listes sont paginées par 20. La consultation publique accepte une recherche
`search` sur le titre et la ville. Les routes privées vérifient toujours le
propriétaire côté serveur. Le plafond d’une annonce est protégé par verrou de
compte et par un index unique, indépendamment des boutons affichés dans l’application.

## Vérifications et limites de cette livraison

Tests ciblés serveur : `php vendor/phpunit/phpunit/phpunit tests/Feature/PrivateVehicleSalesTest.php`.
Tests ciblés mobile : `flutter test test/private_sales`.
Analyse mobile : `dart analyze lib/features/private_sales`.

Ces tests vérifient les formulaires, autorisations, états, confidentialité,
modération et appels d’API. Ils utilisent une base SQLite en mémoire, de fausses
preuves Firebase et un stockage local de test. Ils ne prouvent pas une livraison
réelle de SMS, une installation Android ou une concurrence réelle sur PostgreSQL.
Ces vérifications resteront à faire sur un environnement de développement avant
toute mise en service. Le stock professionnel et les versions obligatoires de
l’application ne sont pas modifiés par cette fonction.
