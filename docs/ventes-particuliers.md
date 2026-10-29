# Vente de véhicules entre particuliers

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
- MAIS LE TÉLÉPHONE N’EST PAS DANS LA LISTE. Il est servi sur la fiche
  (`GET private-listings/{id}`) et sur la page de partage, pas sur l’index
  (`GET private-listings`). La condition est la présence du paramètre de route,
  dans `PrivateVehicleListingResource` : on appelle depuis une fiche, on ne
  parcourt pas une liste pour téléphoner. Vingt fiches par page et aucune
  authentification en faisaient sinon un annuaire de particuliers moissonnable
  d’un seul appel. Ne pas « simplifier » cette condition sans lire le
  commentaire qui l’accompagne : `$request->user() !== null` ferait disparaître
  les boutons d’appel pour tous les acheteurs, aucun intergiciel ne résolvant
  de compte sur ces routes.
- Et la fiche reste publique : les identifiants sont séquentiels, donc un
  moissonneur obstiné passe d’un appel pour vingt vendeurs à un appel par
  vendeur. Fermer vraiment la porte demande de poser `firebase` sur la lecture
  publique — une décision de produit, qui contredirait la ligne ci-dessus, et
  qui n’est pas prise.
- La page de partage porte `noindex, nofollow`. C’est la seule partie
  irréversible : une annonce retirée rend 404, mais un cache de moteur de
  recherche ne se reprend pas. La balise est dans la vue et JAMAIS dans
  `robots.txt`, qu’un `Disallow` ferait respecter par `facebookexternalhit` —
  ce qui tuerait la vignette WhatsApp, donc la raison d’être de la page.
- Le garage reste privé : aucun VIN, immatriculation, assurance, historique de
  carburant ou entretien n’est copié dans l’annonce.
- Envoi après au moins une photo, un numéro joignable et le consentement à
  publier le contact. Le vendeur SAISIT son numéro ; l’envoi ne le vérifie pas.
- Le numéro est vérifié PAR APPEL, par le personnel, pendant la modération.
  Deux colonnes le distinguent et il ne faut pas les confondre : `phone` est ce
  que le vendeur a tapé, `verified_phone` et `phone_verified_at` sont ce qu’un
  modérateur a confirmé. Les deux dernières restent dans la liste `prohibited`
  du formulaire : un vendeur ne peut pas se déclarer vérifié lui-même, et seul
  `approve()` les écrit. Changer de numéro efface la vérification ; toute autre
  retouche la conserve, pour ne pas rappeler le même vendeur à chaque prix
  corrigé.
- CE QUE LE MODÉRATEUR DOIT DEMANDER AU TÉLÉPHONE : « est-ce VOUS qui avez
  déposé cette annonce ? », et non « est-ce votre numéro ? ». La vérification
  par SMS rendait impossible de déposer une annonce avec le numéro de quelqu’un
  d’autre ; l’appel ne l’attrape que si l’on pose cette question-là. C’est la
  seule chose qui sépare ce dispositif d’une case cochée pour rien.
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
`PRIVATE_SALES_ENABLED` n’a pas une valeur affirmative reconnue (`true`, `1`,
`on` ou `yes`, sans distinction de casse). Absente, vide, `no`, `off`, `non`
ou inconnue, la valeur laisse le module fermé. Les contrôles d’authentification
peuvent rendre 401 avant ce contrôle pour une requête non authentifiée.

L’application ne montre ni entrée ni route de cette fonction sans la constante
de compilation `ENABLE_PRIVATE_SALES=true`. Cela permet d’examiner les changements
avant de décider de leur intégration.

Le déploiement Render décrit dans `render.yaml` fournit maintenant explicitement
`PRIVATE_SALES_ENABLED=true`. Les builds Android de diffusion doivent aussi porter
`--dart-define=ENABLE_PRIVATE_SALES=true`. Cela ouvre les entrées du module dans
les nouvelles versions de l’application ; une ancienne version déjà installée
ne peut pas recevoir cette constante à distance.

Sur un service Render existant, synchroniser le Blueprint ou ajouter cette
variable dans son environnement, puis redémarrer/déployer le service. Le cache
de configuration Laravel est reconstruit au démarrage. Confirmer ensuite une
réponse 200 sur `GET /api/private-listings`, même si la liste est vide.

## Mise en route sur une base locale jetable

1. Installer les dépendances verrouillées, copier `.env.example` en `.env` et
   générer la clé Laravel. Utiliser uniquement une base de développement.
2. Ajouter `PRIVATE_SALES_ENABLED=true`, une adresse `APP_URL` locale correcte et
   la configuration Firebase de développement. Activer GD dans PHP.
3. Exécuter `php artisan migrate`, `php artisan storage:link`, puis lancer l’API.
4. Dans la copie mobile : `flutter pub get`, puis lancer avec
   `--dart-define=ENABLE_PRIVATE_SALES=true` et
   `--dart-define=API_BASE_URL=http://ADRESSE_LOCALE:8000/api`.
Aucune configuration Firebase supplémentaire n’est nécessaire : la vérification
du numéro ne passe plus par un SMS. `verifyPhoneNumber` n’a plus aucun appelant
dans l’application, et l’attribut `firebase_verified_phone` a été retiré du
middleware — voir le commentaire qui y explique pourquoi il ne faut pas le
remettre. Le jeton Firebase ne sert plus qu’à l’authentification du client.

5. Pour éprouver la modération, il faut deux comptes : un client qui dépose, et
   un membre du personnel autorisé à gérer le catalogue qui approuve. Publier
   demande `phone_verified` à vrai dans l’appel d’approbation, c’est-à-dire
   l’affirmation que l’appel a été passé.

VERIFICATION PAR APPEL, CE QUE CELA COÛTE ET CE QUE CELA ÉVITE. Cela supprime le
plan Firebase payant, le budget par message, la politique de régions SMS, le
quota et la question d’App Check. En échange, chaque annonce demande un appel
humain, la file de modération porte donc un délai réel, et la vérification ne
vaut que ce que vaut la consigne donnée à celui qui appelle.

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

La migration additive `2026_10_27_000008` garde lisibles les premiers brouillons
locaux ; leurs références devront être sélectionnées à la prochaine modification.
La création porte le numéro `2026_10_27_000007`. Ces deux numéros évitent les
collisions avec les autres migrations du 27 octobre lors de la fusion.
Pour retester les migrations renommées, utiliser une base de test neuve.

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
preuves Firebase et un stockage local de test. Ils ne prouvent pas une
installation Android ni une concurrence réelle sur PostgreSQL. Ils ne prouvent
pas non plus qu’un appel a réellement lieu : aucun code ne peut l’établir, et
c’est pourquoi la consigne de l’appel est écrite plus haut.
Ces vérifications resteront à faire sur un environnement de développement avant
toute mise en service. Le stock professionnel et les versions obligatoires de
l’application ne sont pas modifiés par cette fonction.
