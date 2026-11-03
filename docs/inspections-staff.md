# Inspection d’un véhicule par le personnel AutoParc

Le symbole accompagné du libellé **« Inspecté par AutoParc »** signifie qu’un membre
habilité du personnel a consigné un contrôle daté, à un kilométrage donné. Il ne
constitue pas une garantie, ni une certification de tous les organes du véhicule.
Les anomalies et les points non contrôlés restent consultables dans le compte rendu.
Un résultat `blocked` ne doit jamais être présenté avec une indication de bon état.

Cette inspection est indépendante des contrôles du garage personnel, de la
publication d’une annonce et de la validation d’un partenaire. Aucune de ces
opérations ne crée automatiquement le badge.

## API

Toutes les réponses de rapport utilisent l’enveloppe `data`.

| Méthode | URL | Accès |
| --- | --- | --- |
| GET | `/api/vehicle-inspections/{source}/{sourceId}` | Tout le personnel actif ; historique paginé |
| POST | `/api/vehicle-inspections/{source}/{sourceId}` | Administrateur, responsable, mécanicien |
| POST | `/api/vehicle-inspections/{inspection}/withdraw` | Mêmes rôles ; `{ "reason": "…" }` obligatoire |
| GET | `/api/catalog/vehicle-inspections/{inspection}` | Public ; rapport actif d’une cible visible |

`source` vaut `vehicle` (stock, y compris partenaires) ou `private_listing`.
Les identifiants restent numériques ; deux cibles ayant le même numéro sont
indépendantes. Le module de vente entre particuliers doit être activé pour les
inspections des annonces privées.

Exemple de création :

```json
{
  "inspected_at": "2026-11-02T10:00:00Z",
  "mileage_km": 50100,
  "valid_until": null,
  "summary": "Contrôle visuel et essai ; voir les limites ci-dessous.",
  "confirmed": true,
  "items": [
    { "label": "Freinage", "status": "ok", "note": "Essai effectué." },
    { "label": "Pneus", "status": "attention", "note": "Usure à surveiller." },
    { "label": "Climatisation", "status": "not_checked" }
  ]
}
```

Au moins un point doit être effectivement contrôlé. Limites : 100 points,
200 caractères par libellé, 2 000 par note et 10 000 pour le résumé.
`inspected_at` ne peut pas être dans le futur. `valid_until`, facultatif, doit être
postérieur au contrôle ; **aucune durée de validité n’est imposée par défaut**.
La case de confirmation atteste un contrôle réellement effectué par le personnel.

Le serveur calcule `checked_count` (tous les points sauf `not_checked`),
`attention_count`, `blocking_count` et `result` : `blocked` si un point est bloquant,
sinon `attention` si un point est à surveiller, sinon `clear`. Le client ne peut pas
fournir ces résultats ni l’identité de l’inspecteur.

## Publication et historique

`VehicleResource` et `PrivateVehicleListingResource` exposent toujours
`inspection`, à `null` ou avec `id`, `inspected_at`, `mileage_km`, `result`, les trois
compteurs et `valid_until`. La relation active et les détails techniques sont
chargés en lot, y compris dans le catalogue commun et l’espace partenaire.

Le rapport public ajoute `source`, `source_id`, `summary` et `items`. Il ne contient
aucun identifiant/nom du personnel, motif interne de retrait ou contact du vendeur.
Le contact public des particuliers reste celui d’AutoParc, paramétrable au back-office.
Le résumé et les notes du contrôle étant publics, leur auteur doit s’en tenir aux
constats techniques, sans y recopier les coordonnées du vendeur.

L’historique du personnel ajoute `state`, `actor` (`id` et `name`), `withdrawn_by`,
`withdrawn_at`, `withdrawal_reason` et `created_at`.

- Un nouveau rapport remplace l’ancien (`superseded`) sans modifier son contenu.
- Un retrait est daté et motivé (`withdrawn`) ; l’ancien rapport est conservé.
- Un rapport expiré ou ne correspondant plus à la configuration courante est
  invisible publiquement (`404`) et sans badge ; l’historique affiche `outdated`
  pour un enregistrement encore actif en base. Son retrait reste possible.
- Les changements techniques (identité, motorisation, transmission, année,
  kilométrage, historique d’accident, etc.) retirent le rapport actif avec un motif
  système. L’empreinte comparée à chaque lecture protège aussi contre une
  modification technique hors des observers Eloquent.
- Une nouvelle panne ou une modification de diagnostic retire le rapport actif.
  Réparer la panne ne restaure pas le badge ; un nouveau contrôle est nécessaire.
- Prix, description, photos, coordonnées du vendeur et simple révision de
  modération ne retirent pas l’inspection. Les règles de publication existantes
  continuent toutefois à déterminer si l’annonce entière est visible.
- Une suppression de compte ou de véhicule n’est pas bloquée. Les clés étrangères
  passent à `null`, les snapshots `source`/`source_id` conservent l’identité du rapport,
  et toute consultation publique de ce rapport retourne `404`.

## Stockage et déploiement

La migration `2026_11_02_000002_create_staff_vehicle_inspections.php` ajoute la table
`staff_vehicle_inspections`. Elle utilise deux clés étrangères natives optionnelles,
un snapshot immuable de l’identité et une `active_key` nullable unique (`source:id`).
Les transactions verrouillent la cible avant de remplacer son rapport actif.
Le contenu ne dispose d’aucun endpoint de modification ou de suppression.

Appliquer les migrations au déploiement avant d’exposer cette version de l’API.
Les essais sont dans `StaffVehicleInspectionTest`, avec la matrice de droits dans
`UserRoleCapabilitiesTest` et les garde-fous de routes dans `StaffRouteCapabilitiesTest`.
