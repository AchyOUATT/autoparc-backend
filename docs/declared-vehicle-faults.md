# Défauts déclarés du véhicule

La déclaration du vendeur est indépendante de l’inspection AutoParc. Une liste
vide ne prouve ni l’absence de défaut, ni une inspection. Le même contrat est utilisé
pour le stock (immatriculé ou importé), les partenaires et les annonces privées.

## Données envoyées

Les formulaires véhicules et particuliers acceptent ces deux champs optionnels :

```json
{
  "fault_declaration_state": "has_faults",
  "faults": [{
    "id": 12,
    "category": "engine",
    "title": "Fuite à examiner",
    "description": "Constat du vendeur.",
    "severity": "moderate",
    "status": "declared",
    "affects_drivability": false,
    "is_safety_critical": false
  }]
}
```

- `unknown` : déclaration non renseignée ; état par défaut des données anciennes.
- `none` : absence de défaut connu déclarée explicitement ; aucune ligne ne doit rester.
- `has_faults` : au moins une ligne, y compris lorsque tous les défauts ont été réparés.

`faults` doit être une liste JSON, de 100 éléments maximum. Les treize catégories
et quatre gravités des enums existants sont acceptées. Le titre est limité à 180
caractères, la description à 5 000. `id` sert à modifier une ligne du véhicule
courant ; toute référence à un autre véhicule ou à une ligne non éditable est refusée.
Sans `id`, une nouvelle déclaration est créée. Les lignes éditables omises d’une
liste explicitement envoyée sont retirées ; pour conserver l’historique d’une réparation,
réutiliser son `id` avec `status: repaired` plutôt que supprimer la ligne.

Les particuliers et partenaires utilisent `declared`, `repaired` ou `wont_fix`.
Le personnel habilité utilise les six statuts existants. Le statut initial serveur
est `declared`. `disclosed_to_buyer` est imposé à `true` pour les déclarations privées et partenaires : le
vendeur ne choisit pas de cacher un défaut connu. Le personnel habilité peut préserver
ou modifier ce booléen pour un diagnostic interne ; son omission préserve sa valeur.
Les diagnostics internes du stock
conservent leur workflow séparé et leurs règles de visibilité.

Les champs de déclaration **omis en mise à jour ne changent rien**. Cette règle
préserve les défauts lorsqu’une ancienne application modifie seulement le prix.
Les dates, auteurs, coûts, assignations et métadonnées de gestion ne sont pas
modifiables par le payload de déclaration. Un commercial sans `manage-faults` peut
créer un véhicule `unknown` et conserver ses données existantes, pas déclarer ou
modifier ses défauts par le formulaire catalogue.

## Réponse commune

```json
{
  "fault_declaration": {
    "state": "has_faults",
    "declared_at": "2026-11-03T10:00:00+00:00",
    "faults": [{
      "id": 12,
      "category": "engine",
      "title": "Fuite à examiner",
      "description": "Constat du vendeur.",
      "severity": "moderate",
      "status": "wont_fix",
      "is_open": false,
      "affects_drivability": false,
      "is_safety_critical": false,
      "disclosed_to_buyer": true
    }],
    "unresolved_count": 1
  }
}
```

`unresolved_count` compte **tous les statuts autres que `repaired`**, donc aussi
`wont_fix`. `is_open` garde sa signification historique pour la maintenance :
`false` pour `repaired` et `wont_fix`. Ne pas utiliser ce booléen comme preuve de
réparation. Un historique entièrement réparé reste `has_faults`, avec compteur zéro.

Les sorties publiques filtrent les lignes non divulguées et utilisent une liste
blanche de champs techniques. Aucun coût, pièce nécessaire, auteur, assignation ou
champ interne n’est public, même avec une session staff sur `/api/catalog/*`.
Cela s’applique aussi au tableau historique `faults` de `VehicleResource`.
Un diagnostic caché reste compté comme non réparé le cas échéant : sa présence ne
peut produire une fausse déclaration publique `none`.

En gestion, chaque ligne de `fault_declaration.faults` ajoute `editable`, calculé
par le serveur. Le partenaire ne peut modifier que ses propres déclarations non
reprises par le personnel. Les lignes internes, masquées, assignées, chiffrées ou
dans un statut interne sont en lecture seule et conservées lors du sync partenaire.
Le marqueur serveur `staff_managed_at` empêche une reprise implicite du workflow
par le partenaire, même si le personnel revient ensuite à un statut `repaired`.
Le client doit envoyer uniquement les lignes éditables, sans le champ `editable`.
Les sorties staff de gestion conservent les coûts et pièces internes ; le partenaire
reçoit seulement les champs techniques autorisés, sans coûts ni pièces d’atelier.

## Publication, inspection et recherche

Un particulier peut enregistrer un brouillon `unknown`. La soumission **et**
l’approbation exigent une déclaration explicite cohérente. Tout changement de ses
défauts passe dans la transaction de l’annonce, remet celle-ci en brouillon,
incrémente sa révision et exige une nouvelle modération.

Les modifications de déclaration et de défauts retirent l’inspection active.
Une réparation ou une suppression ne restaure jamais un ancien badge. L’empreinte
du rapport comprend l’état et les lignes techniques triées : une modification SQL
hors observers ne peut conserver le badge à tort. Les anciens rapports `unknown`
sans aucune panne conservent leur empreinte ; ceux comportant déjà des pannes
peuvent devenir périmés et nécessiter un nouveau contrôle au déploiement.

Le catalogue commun inclut les privées pour `fault_category` et `without_faults`.
Une recherche par catégorie publique porte uniquement sur les défauts divulgués.
`without_faults=true` exige une déclaration connue et aucune panne non réparée,
y compris cachée ou `wont_fix`. Les anciens imports sans donnée restent `unknown`
et ne sont pas sélectionnés. Le scope historique `withoutOpenFaults` reste réservé
à sa signification de maintenance ; le catalogue emploie une condition distincte.

## Migration

`2026_11_03_000002_add_declared_vehicle_faults.php` ajoute l’état et la date aux
deux sources, la provenance et le marqueur staff aux pannes stock, et la table
`private_listing_faults` avec FK `private_vehicle_listing_id` et suppression en cascade.
Les anciennes lignes stock existantes sont marquées `has_faults` ; **aucune ligne
ancienne n’est convertie en `none` et aucune date de déclaration n’est inventée**.
Appliquer cette migration avant de publier la nouvelle API.

Les régressions sont couvertes par `DeclaredVehicleFaultsTest`, les parcours privés,
les tests catalogue, partenaire et inspection existants.
