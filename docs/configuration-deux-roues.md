# Configuration deux-roues

Le type de vente `two_wheeler` s’ajoute à `passenger`, `utility` et `heavy`.
`asset_kind` reste `road_vehicle`.

## Compatibilité avec l’application publiée (v131 / build 132)

On n’étend **pas** `vehicles.body_style`. Une valeur inconnue y serait
affichée brute, et le menu d’édition d’une ancienne app peut échouer si la
valeur n’est pas dans la liste. Les types de caisse vivent dans le JSON
`two_wheeler_configuration`. `body_style` reste nul.

`vehicle_type` est une chaîne : `two_wheeler` ne fait pas planter l’analyse
JSON des apps déjà installées. Elles ne classent pas la fiche en voiture,
utilitaire ou poids lourd (`isPassenger` / `isUtility` / `isHeavy` restent
faux). Elles voient désignation, prix, photos, année et cylindrée déjà
exposés, sans les libellés de caisse ni le filtre « Deux-roues ». Aucun
relèvement de `MOBILE_MIN_VERSION_CODE` n’est demandé : les annonces sont
visibles dès que le backend est déployé et qu’une fiche existe.

Une app nouvelle contre une API ancienne recevrait un 422 sur les nouvelles
clés. Déployer le backend d’abord, puis l’application, puis saisir les
fiches avec la nouvelle app.

## Clés de `two_wheeler_configuration`

Toutes optionnelles, liste blanche :

| Clé | Valeurs |
|-----|---------|
| `body_type` | `motorcycle`, `scooter`, `moped`, `other` (pas de `tricycle` dans ce lot) |
| `stroke` | `two_stroke`, `four_stroke` |
| `cooling` | `air`, `liquid` |
| `starter` | `kick`, `electric`, `both` |
| `drive` | `chain`, `belt`, `shaft` |
| `transmission` | `manual`, `semi_automatic`, `cvt` |

Le numéro de cadre (`vin`) d’un deux-roues accepte 5 à 32 caractères. Les
autres types conservent l’exigence de 17 caractères.

## Référentiel (motos routières)

Le seeder `TwoWheelerCatalogSeeder` est rejoué au démarrage (Dockerfile) et
par `DatabaseSeeder`. Il ne pose que des motos routières vérifiées sur des
pages constructeur, pour Yamaha, Honda, Apsonic et TVS. Scooters, trails,
modèles agricoles et tricycles sont volontairement absents. Détail des
sources dans l’en-tête du seeder.

## Installation

Après les migrations existantes, celle du 13 novembre 2026 ajoute la colonne
JSON, élargit `vin` et étend les contraintes `vehicle_type`.
