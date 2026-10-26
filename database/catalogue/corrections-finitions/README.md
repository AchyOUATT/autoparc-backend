# Corrections des valeurs par défaut des finitions

Un fichier par lot de corrections, lu par `php artisan catalog:correct-trims`.

Ces fichiers sont **versionnés** et ils sont la référence : `CorrectionDesFinitionsTest`
les relit et exige que `TrimsSeeder` leur donne raison. Appliquer une correction
en base sans corriger le seeder fait donc **échouer la suite**, et c'est voulu —
voir la règle 4 plus bas.

## Format

```json
{
  "corrections": [
    {
      "brand": "toyota",
      "model_slug": "highlander",
      "trim": "Limited",
      "applications_couvertes": 2,
      "expected": {
        "default_engine_type": "petrol",
        "default_drivetrain": "AWD"
      },
      "updates": {
        "default_drivetrain": null
      },
      "reason": "La brochure 2021 du marché nord-américain donne la Limited en FWD de série, l'AWD en option. Un défaut AWD cachait les pièces de traction avant.",
      "sources": ["https://exemple.test/brochure-2021.pdf"],
      "verified_on": "2026-10-26"
    }
  ]
}
```

## Les champs corrigeables

Deux, et deux seulement :

| champ | table de référence | codes |
|---|---|---|
| `default_engine_type` | `engine_types.code` | `petrol`, `diesel`, `electric`, `hybrid`, `phev`, `lpg` |
| `default_drivetrain` | `drivetrains.code` | `FWD`, `RWD`, `AWD`, `4WD` |

Les codes sont lus **sans égard à la casse** — `awd` et `AWD` sont acceptés —
et un code inconnu est refusé en nommant ceux que la base porte. On écrit des
codes et jamais des identifiants : un `drivetrains.id` dépend de l'ordre de
semis et ne vaut pas d'un serveur à l'autre.

## Les sept règles, et comment chacune est tenue

**1. Portée.** Une valeur dans `trims` s'applique à **toutes** les applications
qui en héritent — tous les marchés, tous les millésimes de `trim_applications`.
Une brochure d'un seul marché et d'un seul millésime ne justifie pas un défaut
global. `applications_couvertes` déclare ce que la preuve couvre, et la commande
refuse si ce nombre ne correspond pas à ce que la base porte. Le jour où une
application s'ajoute, le compte cesse de correspondre et la correction est
refusée — ce qui force à réexaminer la preuve au lieu de l'étendre en silence.

**2. Sens de `updates`.** Champ **absent** → la valeur actuelle est conservée.
Champ **présent et explicitement `null`** → la valeur est effacée. C'est ce qui
distingue une correction ciblée d'un effacement systématique.

Effacer est souvent la bonne correction : une motricité par défaut **fausse**
cache une pièce qui convient, alors qu'une motricité **nulle** ne filtre rien.
Le projet le mesure dans
`FinitionsToyotaSecondeMoitieTest::test_une_motricite_nulle_n_exclut_aucune_piece`.

**3. Concurrence.** `expected` doit couvrir **chaque** champ modifié, sans quoi
un champ serait écrit sans que sa valeur de départ ait été vérifiée. La
comparaison et l'écriture vivent dans la même transaction, et la ligne est
verrouillée avant d'être lue.

**4. Référence.** `TrimsSeeder` n'écrit que ce qui n'existe pas — il teste
`exists()` sur `(vehicle_model_id, name)` et fait `insert` ou rien. Corriger la
base ne suffit donc pas : une base neuve, et le `Dockerfile` resème à chaque
démarrage du conteneur, reviendrait à l'ancienne valeur. La correction du seeder
est obligatoire, et `CorrectionDesFinitionsTest` échoue tant qu'elle n'est pas
faite.

**5. Création.** Cette commande ne crée **aucune** finition.
`catalog:import-trims` le fait déjà, avec marché, millésimes et sources — et
c'est bien ainsi : une commande qui saurait créer *et* corriger laisserait créer
sans preuve d'application.

**6. Sûreté.** `--dry-run` déroule tout, écrit, puis défait : les contraintes de
la base sont réellement éprouvées, pas seulement les nôtres.

**7. Preuves.** `reason`, `sources` et `verified_on` sont obligatoires. Elles
restent propres à chaque finition, chaque marché et chaque millésime — le
mécanisme est réutilisable pour Hyundai, BMW, Mercedes ou Lexus, les preuves ne
le sont pas.


**8. Emplacement.** La commande n'accepte **que** des documents situés sous ce
répertoire. Le chemin est résolu par `realpath()` avant comparaison, et la
comparaison porte sur le séparateur final : ni un lien symbolique, ni une
remontée par `..`, ni un répertoire voisin au nom proche
(`corrections-finitions-brouillon`) n'en sortent.

C'est ce qui fait tenir la règle 4. Sans cette borne, la règle était écrite mais
pas appliquée : une correction lancée depuis `/tmp` partait en base sans laisser
de trace dans le dépôt, et la suite continuait de donner raison à l'ancienne
valeur. Les bancs écrivent des documents jetables et surchargent donc
`catalogue.corrections_finitions` vers un répertoire temporaire — jamais celui-ci,
qu'un test interrompu polluerait d'un fichier que la règle 4 relirait ensuite
comme une référence.

**9. Rejouable.** Un document entièrement appliqué est accepté **sans écriture**,
et le bilan l'annonce. C'est nécessaire : `expected` décrit l'état *avant* la
correction, donc un second passage trouvait `updates` en base et refusait sur
« la base a changé » — vrai, mais inutile, alors que rejouer est un geste normal
après un échec partiel ou sur une base restaurée.

`updated_at` n'est pas touché dans ce cas, et c'est voulu : c'est le curseur de
`/catalog/sync?since=…`, et une réécriture à l'identique ferait retélécharger la
finition par tous les appareils pour rien.

Un état **mixte** — un champ déjà corrigé, un autre non — reste refusé, avec son
propre message : la correction a été partiellement appliquée, et le travail est
de constater ce qui est passé, non de relire la preuve.
