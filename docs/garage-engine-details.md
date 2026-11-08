# Détails facultatifs du moteur au garage

Les véhicules personnels (`owned_vehicles`) acceptent des renseignements déclarés
par leur propriétaire, à la création comme à la modification. Tous les champs
restent facultatifs. Une mise à jour qui omet un champ le conserve ; `null`
l'efface.

| Champ API | Valeur | Sens |
| --- | --- | --- |
| `engine_displacement_l` | nombre positif, 3 décimales au plus | Cylindrée indiquée en litres (ex. 1,8 L) |
| `engine_displacement_cc` | entier positif | Cylindrée exacte connue en cm³ (ex. 1798) |
| `cylinders` | entier de 1 à 24 | Nombre de cylindres |
| `engine_layout` | `inline`, `v`, `boxer`, `w`, `other` | Disposition déclarée, indépendante du nombre |
| `engine_code` | chaîne de 20 caractères au plus | Code constructeur, normalisé en majuscules |
| `power_hp` | entier positif | Puissance déclarée en chevaux métriques (ch) |

Les litres sont conservés séparément des cm³ : aucune conversion ne prétend
retrouver une cylindrée exacte depuis une appellation commerciale. Le formulaire
accepte une virgule ou un point pour les litres. Aucune donnée n'est déduite du
nombre de cylindres ou d'une génération du catalogue.

Les nouveaux champs descriptifs ne modifient pas les verdicts de compatibilité
ni les références de consommation. Le code moteur conserve son usage existant
par les services de compatibilité, qui vérifient les contraintes de chaque pièce.

La migration `2026_11_03_000005_add_engine_details_to_owned_vehicles.php` doit être
appliquée au serveur avant de distribuer la nouvelle application mobile. Elle
ajoute uniquement des colonnes nullables, sans compléter les anciens véhicules.
