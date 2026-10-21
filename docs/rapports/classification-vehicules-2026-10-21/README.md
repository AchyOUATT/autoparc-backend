# Rapports de classification — 21 octobre 2026

Ces fichiers rendent l'audit consultable et téléchargeable depuis GitHub.

- [Rapport PDF](rapport-classification-vehicules.pdf)
- [Rapport Word modifiable](rapport-classification-vehicules.docx)
- [Rapport Markdown](rapport-classification-vehicules.md)
- [Détail des 248 entrées et de leurs finitions](modeles-et-finitions.csv)
- [Couverture des 25 marques](couverture-par-marque.csv)
- [Script du comptage statique](compter_catalogue.py)

Le rapport photographie le code initial au commit
`e8264c4f6fd6bcf092236b175c2c869cd27e19e4`, **avant les corrections présentes
sur cette branche**. Ses mentions « aucune modification appliquée » et
« tests PHP non exécutés » décrivent le moment de l'audit, pas l'état actuel
de la branche.

Pour les améliorations ensuite réalisées, leurs commandes et leurs limites,
voir [le guide du catalogue des finitions](../../catalogue-finitions.md).

## État des travaux joints

- Audit du catalogue en lecture seule, export JSON/CSV et backlog de dix
  entrées Mazda/Honda.
- Import de lots documentés avec marchés, millésimes, sources, simulation,
  protection des corrections et conservation des identifiants.
- Correction de l'horodatage et de la synchronisation ; transmission du
  contexte documentaire des finitions.
- 37 tests ciblés réussis (205 assertions).
- Suite complète : 568 tests réussis et deux échecs sur les aperçus d'images,
  reproduits à l'identique sur le commit initial sans ces modifications.

**Aucune nouvelle finition automobile n'a été ajoutée.** Les sites officiels
nécessaires à la vérification sont bloqués par le réseau de l'environnement.
Le référentiel reconstruit contient toujours 181 entrées sans finition.
La production et le client Flutter n'ont pas été modifiés.

Les CSV sont des données de référence issues du dépôt, sans données de
clients ni de véhicules individuels. Le script peut être relancé avec
`--repo` pour indiquer le checkout et `--output` pour choisir le dossier
d'export ; les colonnes de ligne source correspondent au code de l'audit.

Sur la page GitHub d'un PDF ou d'un fichier Word, utiliser l'action de
téléchargement du fichier. Pour une copie de tout le projet, sélectionner
la branche puis utiliser **Code → Download ZIP**.
