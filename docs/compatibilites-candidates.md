# Compatibilités candidates (non vérifiées)

`database/catalogue/fitment-candidates/<SKU>.json` contient des **propositions**
de compatibilités, au statut `a_verifier`. Elles ne sont ni publiées ni
certifiées.

- `FiltresHuileBoutiqueSeeder` : une référence marquée
  `compatibilites_a_verifier` conserve ses compatibilités existantes et ne
  publie aucune proposition.
- `php artisan catalog:audit-fitment-candidates [SKU] [--json]` : lecture
  seule. Valide le JSON (sku = nom de fichier, `brand`, années, provenance),
  résout chaque slug **dans la marque déclarée** (un slug n'est unique que par
  marque) et signale les modèles absents ou ambigus. `model_exists` indique
  seulement que le modèle existe dans le référentiel, **pas** que la pièce lui
  est compatible.

Les candidats du 90915-10001 viennent de catalogues (principalement US) et
restent à vérifier selon le marché. Aucune validation automatique.
