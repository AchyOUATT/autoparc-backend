# Enregistrer un engin ou un matériel

Le parcours utilise les annonces `Vehicle` existantes pour conserver les tarifs,
les propriétaires, les photos et les réservations. `asset_kind=equipment`
distingue explicitement un engin d’un véhicule routier (`road_vehicle`).

## Parcours mobile

1. Choisir Vente ou Location.
2. Choisir Véhicule routier ou Engins et matériels.
3. Renseigner le type d’engin, la marque et le modèle, puis le prix de vente ou
   au moins un tarif journalier, hebdomadaire ou mensuel.
4. Compléter les autres informations si elles sont connues.

L’année, l’énergie, les numéros de série et de parc, l’immatriculation applicable,
les compteurs, caractéristiques, accessoires et documents sont facultatifs.
Une marque ou un modèle absent du référentiel peut être saisi librement, sans
créer automatiquement une référence constructeur. Le tracteur routier et le
camion restent dans les véhicules routiers ; `agricultural_tractor` désigne
uniquement un tracteur agricole.

Types : `agricultural_tractor`, `aerial_platform`, `excavator`, `backhoe_loader`,
`loader`, `telehandler`, `forklift`, `generator`, `other`.

## Identité et données techniques

Les routes existantes `/api/vehicles` et `/api/partner/vehicles` acceptent :

```json
{
  "asset_kind": "equipment",
  "equipment_type": "aerial_platform",
  "brand_id": null,
  "vehicle_model_id": null,
  "manufacturing_year": null,
  "engine_type_id": null,
  "availability": "rent",
  "rental_weekly_rate": 350000,
  "equipment_details": {
    "brand_label": "Marque déclarée",
    "model_label": "Désignation déclarée",
    "serial_number": "SERIE-42",
    "operating_hours": 1250.5,
    "hour_reading_date": "2026-11-08",
    "working_height_m": 16.5,
    "rated_load_kg": 230
  }
}
```

Ces libellés illustrent le format et ne constituent pas des références de
matériel vérifiées. Les caractéristiques saisies sont déclarées. Aucune
compatibilité de pièce n’est déduite du seul nom de la machine ou du moteur.

Une référence de modèle sélectionnée doit appartenir à la marque et au type
d’engin. Le référentiel existant est réutilisé via `equipment_category` ; les
années de production et les moteurs du catalogue ne sont pas inventés.

`equipment_details` accepte les identifiants et les mesures normalisées :
heures décimales, kilomètres, puissance en kW, charges et masses en kg, hauteurs
et déport en m, godet en m³ et prise de force en tr/min. Les champs visibles
s’adaptent au type. La transmission et la cabine disposent de valeurs propres
aux engins. Les déclarations `false` et les valeurs zéro restent distinctes
d’une information inconnue.

En modification, omettre un champ le conserve ; envoyer un sous-champ à `null`
l’efface. Les valeurs d’un bloc masqué ne sont pas supprimées automatiquement.
La famille `asset_kind` ne peut pas être changée sur un enregistrement existant.
Les obligations d’identité des véhicules routiers restent inchangées.

Le catalogue accepte `asset_kind` et `equipment_type`. Un filtre sur les engins
exclut les annonces privées automobiles. Les fiches et pages partagées affichent
les mesures d’engin et n’ajoutent pas de fausse année, immatriculation ou
consommation routière.

## Photos et documents

Les photos utilisent les galeries existantes. Les justificatifs administratifs
utilisent des fichiers et endpoints distincts, accessibles après enregistrement
de l’engin. Le bouton Documents se trouve sur son formulaire de modification.

Base staff : `/api/vehicles/{vehicle}/equipment-documents`.
Base partenaire : `/api/partner/vehicles/{vehicle}/equipment-documents`.

- `GET` : liste privée des métadonnées.
- `POST` multipart : `document`, `document_type` et `expires_on` facultatif.
- `GET /{document}/download` : téléchargement authentifié.
- `DELETE /{document}` : suppression du fichier et de son enregistrement.

Types de document : `ownership`, `registration`, `inspection`, `insurance`,
`other`. Formats : PDF, JPEG, PNG et WebP, au maximum 8 Mio. Les noms de fichiers
sont nettoyés. Les chemins, disques et URL publiques ne sont jamais renvoyés.
Le personnel doit disposer de `manage-catalog` ; le partenaire doit être
approuvé et propriétaire de l’engin. Les documents d’un autre engin ou d’un tiers
ne sont pas accessibles. L’ajout d’un document produit le statut `provided` et
ne certifie ni un contrôle technique ni une assurance.

### Stockage avant déploiement

Le stockage local privé convient au développement. En production, il faut un
bucket S3 privé et persistant dédié aux justificatifs, distinct de la galerie :

```dotenv
EQUIPMENT_DOCUMENTS_DISK=equipment_private
EQUIPMENT_DOCUMENTS_BUCKET=nom-du-bucket-prive-dedie
```

Le disque réutilise la configuration AWS/S3 existante. La politique du bucket
doit en interdire la lecture publique. Aucun bucket ni droit cloud n’est créé
automatiquement par ce correctif. Sans cette configuration, l’ajout de documents
en production répond 503 sans les placer sur le disque public ou éphémère.
Les annonces et photos restent utilisables indépendamment des justificatifs.

## Migration et vérification

Appliquer les migrations d’enregistrement des engins puis de documents privés
avant de déployer les nouvelles routes. Les véhicules existants restent de
famille `road_vehicle` et conservent leurs identifiants et leurs contrôles.
Le retrait des migrations refuse de perdre des engins ou des justificatifs
existants.

Les inspections tiennent compte des modifications techniques réelles des engins.
L’ordre des clés JSON n’invalide pas un contrôle, et les anciens rapports
routiers conservent leur empreinte.

La validation couvre création et édition staff/partenaire, identité libre ou
référencée, mesures décimales, tarifs, fiches publiques, documents privés,
contrôles d’accès, stockage et affichage FR/EN sur petit écran.
