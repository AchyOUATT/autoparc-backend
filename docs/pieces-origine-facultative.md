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
- `PartResource` sérialise `type: null` sans erreur.
- Le filtre `?type=` et l'historique restent inchangés.

## Ordre de déploiement

1. Déployer le backend **et exécuter la migration**.
2. Seulement ensuite publier l'application mobile qui n'envoie plus `type`.
   Un ancien backend refuserait sinon la création (`type` obligatoire).

Retour arrière (`down`) : les origines `null` deviennent `aftermarket` afin de
pouvoir rétablir la contrainte NOT NULL.
