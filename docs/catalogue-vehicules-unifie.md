# Catalogue public commun aux véhicules du parc et aux particuliers

`GET /api/catalog/vehicle-listings` alimente la grille commune. Les API
`/api/catalog/vehicles`, `/api/vehicles` et `/api/private-listings` gardent leurs
contrats et leurs responsabilités. Aucune migration de base de données n’est
nécessaire pour ce flux.

## Format

La réponse conserve l’enveloppe paginée Laravel : `data`, `links`, `meta`.
Chaque entrée identifie sa source et contient la ressource native de cette source.

```json
{
  "key": "vehicle:123",
  "source": "vehicle",
  "source_id": 123,
  "vehicle": {"id": 123}
}
```

```json
{
  "key": "private_listing:123",
  "source": "private_listing",
  "source_id": 123,
  "private_listing": {"id": 123}
}
```

Les objets imbriqués ci-dessus sont abrégés. Ils portent respectivement le
contrat de `VehicleResource` et de `PrivateVehicleListingResource`. Les IDs
natifs restent numériques. La clé composée sert à distinguer les cartes, même
si les deux tables contiennent le même ID.

## Sélection, tri et pagination

Le serveur sélectionne les identifiants des deux sources avec `UNION ALL`.
Il applique un tri commun, puis une seule pagination SQL, avant de charger les
relations des seuls éléments de la page. Il ne charge pas tout le catalogue
pour le découper en mémoire.

Les tris autorisés restent les colonnes publiques : `created_at`, `sale_price`,
`rental_daily_rate`, `manufacturing_year`, `consumption_combined`. Pour une
annonce particulière, la date publique est `published_at` et le prix est
`price`. Pour le stock, les promotions actives sont prises en compte dans les
filtres de prix et le tri. Les valeurs inconnues suivent les valeurs connues ;
la source et l’ID départagent les égalités. La taille des pages est comprise
entre 1 et 100.

## Filtres et informations inconnues

Les règles du stock sont partagées avec l’index historique. Les annonces
particulières sont des instantanés publics : leur identité affichée vient de
`vehicle_identity`, indépendamment d’un renommage ultérieur du référentiel.

| Filtre | Traitement pour une annonce particulière |
|---|---|
| Recherche `q` | Titre ou ville |
| Marque, modèle, finition, motorisation, roues motrices, couleur | Identifiants conservés dans l’annonce |
| `year_min`, `year_max` | Année déclarée |
| `price_min`, `price_max` | Prix demandé |
| `mileage_max` | Kilométrage déclaré |
| `city` | Ville déclarée |
| `availability=sale` | Incluse si les autres critères correspondent |
| Location, promotion, panne, consommation, origine ou dédouanement | Exclue lorsqu’un tel critère est demandé et qu’aucune donnée ne permet de l’établir |
| Immatriculation, état, type, carrosserie, agence ou statut du stock | Exclue lorsqu’un filtre strict est demandé : ces informations ne sont pas établies par le schéma privé actuel |

Une annonce particulière n’est donc pas automatiquement « immatriculée » ou
« véhicule particulier/passenger ». Sans filtre strict sur ces informations,
elle apparaît dans la liste générale.

## Visibilité et coordonnées

Le flux est toujours public, y compris lorsqu’un membre du personnel ou le
vendeur l’appelle. Il n’expose pas les données de modération ou les numéros
privés. Les annonces doivent être publiées, avoir une date de publication et
appartenir à un vendeur client actif.

Le drapeau serveur `private_sales.enabled` ferme la branche particuliers du
flux. Le paramètre `include_private=0` permet également de demander le stock
seul. Le stock reste consultable lorsque le module particuliers est fermé.

Le mobile ouvre la fiche native correspondant à `source`. La fiche d’un
particulier recharge ses coordonnées depuis `/api/private-listings/{id}` ; le
numéro du vendeur n’est pas inclus dans la grille commune. Les liens de
partage existants sont conservés pour chaque source.

## Intégration mobile

`Vehicle.id` conserve son type entier. `CatalogVehicleItem` enveloppe un
`Vehicle` ou une `PrivateListing`. Les cartes de particuliers portent le badge
localisé « Particulier » ; elles ne reprennent pas un statut d’immatriculation,
une TVA ou une validation technique non établis.

Le filtre de ville associe les villes des agences et celles des annonces
particulières déjà chargées. Une ville sélectionnée reste effaçable même si la
recherche ne retourne plus de résultat. La recherche textuelle permet aussi
de chercher une ville avant qu’elle apparaisse dans les pages chargées.

L’inclusion des particuliers respecte le drapeau de compilation mobile
`ENABLE_PRIVATE_SALES`. Un mobile dont le module est fermé envoie
`include_private=0`. Le drapeau serveur reste nécessaire même pour un mobile
qui l’active.

Publier d’abord le backend, puis la version mobile correspondante. Si le
nouveau point d’entrée répond 404 sur un serveur plus ancien, le mobile se
replie sur le catalogue véhicules historique. Une erreur serveur ou une
réponse invalide reste visible ; elle n’est pas transformée en liste vide.
