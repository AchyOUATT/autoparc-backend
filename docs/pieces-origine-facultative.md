# Origine des pièces facultative

Les formulaires de pièces (personnel et partenaire) ne demandent plus le
**type / origine** (OEM, OES, aftermarket, occasion). Seuls l'**état**
(neuve, reconditionnée, d'occasion) et la catégorie restent demandés.

## Changements backend

- `StorePartRequest` : `type` passe de `required` à `sometimes|nullable`. Une
  valeur hors liste reste refusée.
- Migration `2026_11_08_000001_make_part_type_optional` : `parts.type` devient
  nullable et **sans valeur par défaut**. Les origines déjà enregistrées ne
  sont pas modifiées. Une nouvelle pièce créée sans origine porte `null`, jamais
  un `aftermarket` inventé.
- Édition : un `PATCH` sans `type` conserve l'origine existante.
- `PartResource` conserve un contrat lisible par les anciennes apps : sans
  capacité annoncée, une origine absente est représentée par la chaîne
  non vide `unknown`. Les valeurs connues restent identiques. Cette sentinelle
  n'est pas une origine métier et n'est jamais enregistrée comme telle.
- Avec `X-AutoParc-Capabilities: nullable-part-type`, la réponse contient
  `type: null`. Le mobile l'annonce sur ses clients public/staff et partenaire.
  `Vary: X-AutoParc-Capabilities` sépare ces représentations dans les caches.
  La capacité concerne uniquement la représentation, jamais les permissions.
- Un ancien éditeur qui renvoie `unknown` est normalisé vers `null` à la
  validation. Les autres valeurs invalides restent refusées.
- Le filtre `?type=` et l'historique restent inchangés.

## Ordre de déploiement

1. Déployer ce backend compatible **et exécuter la migration**.
2. Publier ensuite le mobile qui n'envoie plus `type` et annonce la capacité.
   Un ancien backend refuserait sinon la création (`type` obligatoire).
3. Les anciennes installations continuent à lire les nouvelles pièces : elles
   reçoivent `unknown`, jamais un null incompatible avec leur cast String.
   Leurs formulaires conservent leurs anciens champs; la mise à jour est
   nécessaire pour bénéficier de la saisie sans origine. La nouvelle app
   interprète aussi la sentinelle d'une ancienne réponse comme non renseignée.

Ne pas retirer la représentation de compatibilité tant que des clients anciens
peuvent encore accéder au catalogue. Ni une origine `aftermarket` par défaut ni
une disparition des nouvelles pièces du catalogue ne servent de contournement.

Retour arrière (`down`) : les origines `null` deviennent `aftermarket` afin de
pouvoir rétablir la contrainte NOT NULL.
