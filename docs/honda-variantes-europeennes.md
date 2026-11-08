# Honda : variantes européennes ajoutées

Ajouts **distincts** dans `VehicleModelsSeeder` (upsert par `brand_id` + `slug`).
Aucune ligne Honda existante n'est remplacée, supprimée ni modifiée, et leurs
compatibilités et références restent attachées.

| Slug | Nom | Génération | Carrosserie (`body_type`) | Production | Source officielle |
|---|---|---|---|---|---|
| `civic-ehev-fl4` | Civic e:HEV | FL4 | `compacte` (5 portes à hayon) | 2022 – en cours | [Rettungsdatenblatt Civic Hybrid 2024](https://www.honda.de/content/dam/local/germany/cars/pdf/rettungsdatenblaetter/rettungsdatenblatt_civic_hybrid_2024-06-06.pdf) |
| `civic-fn` | Civic | FN (FN1–FN4) | `compacte` (3 portes à hayon) | 2007 – 2011 | [Rettungsdatenblatt Civic 3D 2007-2011](https://www.honda.de/content/dam/local/austria/cars/pdf/rettungsdatenblaetter/rettungsdatenblatt_civic_3d_2007-2011.pdf) |
| `civic-hybrid-fd3` | Civic Hybrid | FD3 | `berline` (4 portes) | 2006 – 2011 | [Rettungsdatenblatt Civic Hybrid 2006-2011](https://www.honda.de/content/dam/local/austria/cars/pdf/rettungsdatenblaetter/rettungsdatenblatt_civic_hybrid_2006-2011.pdf) |
| `zr-v-ehev-rz4` | ZR-V e:HEV | RZ4 | `suv` (5 portes) | 2023 – en cours | [ERG Honda ZR-V e:HEV](https://www.techinfo.honda-eu.com/sites/default/files/2024-01/erg_honda_zr-v_ehev_de.pdf) |

## Choix

- **Périmètre** : marché européen (fiches de secours Honda Allemagne/Autriche/Europe).
- **Années** : années de production d'après les fiches, pas des millésimes. Fin `null` = ouverte.
- **Carrosserie** : vocabulaire réel du dépôt (`compacte` = hayon, `berline`, `suv`).
  Le schéma n'a pas de champ portes/places : « 3 portes », « 5 portes » et
  « 4 portes » sont documentés ici, pas inventés dans un champ.
- **FN** : regroupement officiel FN1–FN4 tel que la fiche le donne ; aucun
  moteur ni version par sous-code n'est inventé.
- **Hybride** : porté par le nom commercial (`Civic e:HEV`, `Civic Hybrid`,
  `ZR-V e:HEV`) afin de ne pas fusionner ces variantes avec les Civic
  thermiques.
- Pas de nouvelle clé étrangère, pas de colonne marché, pas de migration de schéma.

Les lignes sont créées au prochain passage de `VehicleModelsSeeder`
(rejoué au démarrage du conteneur, cf. `Dockerfile`).
