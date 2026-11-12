# Configuration poids lourd déclarée

Le formulaire des véhicules de stock et des annonces partenaires affiche une
section facultative lorsque la catégorie est « Poids lourd ». La fiche détaillée
affiche uniquement les valeurs renseignées, avec la mention « Données déclarées ».
Cette section ne concerne pas les véhicules personnels du garage (`OwnedVehicle`).

Les libellés ne constituent pas un référentiel constructeur. Aucun nom de version
ne sert à déduire une puissance, une masse, une cabine ou une disposition d’essieux.
Les champs moteur et boîte de vitesses restent dans la partie technique commune.

## Contrat API

Les routes existantes de création/modification de `Vehicle`, y compris celles des
partenaires, acceptent `heavy_configuration` à la racine de la requête.

| Clé | Valeur facultative |
| --- | --- |
| `version_label` | Version commerciale, 100 caractères maximum |
| `cab_label` | Cabine, 100 caractères maximum |
| `axle_configuration` | Notation exacte des essieux, 40 caractères maximum |
| `chassis_type` | `tractor`, `rigid` ou `other` |
| `body_type` | Carrosserie poids lourd : `tanker`, `tipper`, `tractor_unit`, `flatbed`, `box`, `mixer` ou `other` |
| `body_use` | Précision libre de carrosserie / usage, 100 caractères maximum |
| `emission_standard` | Norme antipollution, 40 caractères maximum |
| `ptac_kg` | Entier de 1 à 1 000 000 kg |
| `ptra_kg` | Entier de 1 à 1 000 000 kg |
| `useful_volume_m3` | Volume utile, de 0,01 à 500 m³, deux décimales |
| `tank_capacity_liters` | Capacité de cuve (citerne), entier de 1 à 200 000 L |
| `payload_kg` | Charge utile, entier de 1 à 1 000 000 kg |

Le type de carrosserie poids lourd vit dans `heavy_configuration.body_type`,
et non dans `vehicles.body_style`. Cette dernière colonne conserve ses valeurs
historiques (`truck`, `bus`, …) pour que les applications déjà installées ne
reçoivent pas un code qu’elles afficheraient brut ou classeraient mal.

`tank_capacity_liters` décrit la cuve d’une citerne, pas le réservoir du
porteur (`fuel_tank_liters`, inchangé).

Lorsque les deux masses sont renseignées, le PTRA doit être supérieur ou égal au
PTAC. Ce contrôle porte sur les valeurs après fusion, avec une seconde validation
sous verrou transactionnel lors d’une modification.

La lecture renvoie `technical.heavy_configuration`, ainsi que
`technical.heavy_configuration_status`, dérivé par le serveur : `declared` si une
valeur existe, sinon `null`. Le client ne peut fournir ni statut de vérification,
ni source, ni clé supplémentaire dans la configuration.

Sur modification :

- Une clé `heavy_configuration` omise conserve les données existantes.
- Un objet partiel conserve les sous-champs omis.
- Un sous-champ à `null` efface cette valeur.
- `heavy_configuration: null` efface toute la configuration.
- Après fusion, une configuration sans aucune valeur est stockée à `null`.

Les espaces autour des libellés sont retirés. Les notations `6x2/4`, `6x2*4` ou
`6×2/4` restent distinctes. Les valeurs ne sont pas ajoutées au catalogue de
finitions. Un changement de catégorie dans le formulaire conserve les données
pour permettre un retour à la catégorie poids lourd ; la section est alors masquée.

Une modification de cette configuration retire l’inspection active du véhicule.
Les anciens rapports sans configuration restent valides et l’ordre des clés JSON
ne modifie pas leur empreinte.

## Installation

Appliquer les migrations `2026_11_07_000001_add_heavy_configuration_to_vehicles`
(colonne) puis `2026_11_12_120000_extend_heavy_configuration_declared_keys`
(clés déclarées, sans alter de schéma) avant de déployer un client qui les envoie :


```bash
php artisan migrate --force
```

Aucun seeder ni import de données constructeur n’est nécessaire.
