# Propositions de compatibilités à examiner

Ces fichiers sont des lots d’attente, indépendants des `part_fitments` actifs.
Leurs périodes et marchés proposés ne sont pas des applications constructeur
vérifiées. Ils ne sont jamais chargés par les seeders ou les migrations.

```bash
php artisan catalog:audit-fitment-candidates chemin/lot.json --format=json
```

L’audit reste en lecture seule, y compris avec `--fail-on-blocked`. La présence
d’un modèle, d’un code moteur ou d’une URL ne suffit pas à certifier une
compatibilité.

Le premier lot concerne `90915-10001`. Voir
[sa revue et les conditions de publication](../../../docs/compatibilites-toyota-10001.md).
