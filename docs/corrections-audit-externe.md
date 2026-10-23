# Corrections de l'audit externe — 21 octobre 2026

Ces changements sont préparés sur la branche
`codex/catalogue-finitions-rapport-2026-10-21`. Leur présence sur cette branche
ne signifie pas qu'ils sont fusionnés dans `main`, déployés ou utilisés par
les versions Flutter déjà installées.

## État des quatre défauts

| Défaut | Correction backend préparée | Limite de validation |
|---|---|---|
| F1 : références invisibles en delta | Horodatage des vrais changements, reprise des références historiques et seconde de frontière relue | Contrôler le delta après déploiement et l'upsert du cache mobile |
| F2 : classification incohérente après modification | Validation de l'état final marque, modèle, finition et cote de motorisation | Les clients doivent envoyer les nouveaux liens ou leur retrait explicite |
| F3 : caractère VIN confondu avec code moteur | Champs séparés, codes saisis normalisés, anciens caractères isolés exclus du calcul | Pas de table constructeur vérifiée permettant une résolution automatique du code moteur |
| F4 : compatibilité trop affirmative | Verdict par déclaration et listes confirmées par défaut | Flutter doit lire les nouveaux états pour distinguer inconnu et incompatibilité |

Aucune nouvelle finition automobile, correction de données de production ou
modification de Flutter n'est incluse dans ce lot.

## F2 — Vérifier la fiche résultante

Un champ absent d'une modification conserve la valeur déjà enregistrée ;
un `null` explicite retire une dépendance facultative. La validation examine
les deux ensemble :

- Le modèle doit appartenir à la marque résultante.
- La finition et la cote de motorisation doivent appartenir au modèle résultant.
- Une fiche en modèle libre ne doit pas conserver des dépendances d'un ancien
  modèle normalisé.

Changer uniquement un modèle ne transfère donc plus silencieusement la
finition ou la consommation de l'ancien. Une incohérence produit une réponse
422 désignant les champs concernés. Aucun lien n'est supprimé automatiquement.

Exemple de changement de modèle avec retrait explicite des anciens liens :

```json
{
  "brand_id": 1,
  "vehicle_model_id": 42,
  "trim_id": null,
  "motorisation_id": null
}
```

Les identifiants de cet exemple sont fictifs. Le garage accepte toujours les
modifications partielles ; le parc commercial conserve les champs obligatoires
de son contrat existant. Les IDs de finition, moteur, motricité et couleur
sont également exposés dans la réponse du garage pour préparer une édition
sans rapprochement approximatif par libellé.

## F3 — Le VIN ne résout pas automatiquement un code moteur

Le décodage retourne désormais :

```json
{
  "vin_engine_character": "K",
  "engine_code": null
}
```

`vin_engine_character` est le caractère brut en huitième position, y compris
`0` lorsqu'il y figure. Il n'est pas directement comparable à `2AR-FE`,
`A25A-FKS`, etc. Le champ NHTSA éventuel
`nhtsa.engine_code_nhtsa` reste une donnée brute distincte, pas un code
constructeur certifié par l'application.

En l'absence de correspondance constructeur vérifiée, `engine_code` reste
donc `null` et un avertissement invite à vérifier le vrai code sur le véhicule.
La confiance générale du décodage ne certifie pas une motorisation.

Les codes saisis à la création et à la modification du garage et du parc
sont normalisés : espaces de bord retirés, majuscules et chaîne vide convertie
en `null`. Les types JSON invalides restent des erreurs de validation, sans
conversion implicite de tableau ou de nombre en code moteur.

Les anciens codes d'un seul caractère restent stockés. Le calcul les traite
comme une information non résolue au lieu de les utiliser pour exclure des
pièces. Ils ne sont ni effacés ni convertis automatiquement. Une famille de
codes d'un caractère nécessiterait une résolution explicite par constructeur,
modèle et année avant d'être exploitable.

## F4 — Trois états selon les déclarations disponibles

Chaque pièce évaluée pour un véhicule ou des critères porte :

```json
{
  "compatibility_assessment": {
    "status": "candidate",
    "compatible": false,
    "compatibility_ready": false,
    "missing_criteria": ["engine_code"],
    "reason": "missing_vehicle_criteria"
  }
}
```

| État | Signification |
|---|---|
| `confirmed` | Une même déclaration directe ou OEM correspond au modèle et à toutes les contraintes qu'elle impose |
| `candidate` | Une ligne reste possible, mais des informations ou une vérification manquent |
| `unknown` | Le référentiel ne fournit pas de déclaration permettant de conclure |

Une confirmation est une correspondance **selon le référentiel déclaré**,
pas une certification indépendante du constructeur. Une déclaration
universelle pour une génération peut être confirmée sans code moteur si
elle n'impose aucune contrainte sur ce code. Une ligne limitée à un moteur
ne peut pas l'être lorsque le code manque. Les valeurs par défaut d'une
finition n'établissent pas la configuration constatée du véhicule.

Les lignes synthétiques `generated` ne confirment jamais une compatibilité.
Les déclarations directes `declared` et les lignes OEM sont évaluées sur
l'ensemble de leurs contraintes : année, finition, carburant, motricité et
code moteur lorsque ces valeurs sont présentes sur la ligne.

`unknown` ne signifie pas que la pièce est physiquement incompatible. Une
absence dans le catalogue ne prouve pas une incompatibilité.

### Listes et anciens clients

Les trois routes suivantes retournent **uniquement les correspondances
confirmées par défaut** :

- `GET /api/catalog/compatible-parts`
- `GET /api/my/vehicles/{id}/compatible-parts`
- `GET /api/vehicles/{id}/compatible-parts`

Ce choix est nécessaire parce que des versions mobiles utilisent seulement
les IDs de ces listes pour afficher une pastille verte. Ajouter un champ
de statut sans changer les résultats aurait laissé ces faux positifs.

Un client qui sait lire les états peut demander les candidats :

```text
GET /api/catalog/compatible-parts?vehicle_model_id=42&year=2019&include_candidates=1
```

Chaque résultat contient alors `compatibility_assessment`. Le champ existant
`compatibility`, lorsqu'il est exposé sur une fiche, reste le résumé des
modèles et n'est pas réutilisé pour ce nouveau verdict.

Le filtrage des confirmations précède la pagination : totaux et pages
décrivent la sélection réellement retournée. Les déclarations sont chargées
en lot pour l'évaluation, sans les exporter intégralement dans chaque résultat.

La route `GET /api/my/parts/{id}/garage-compatibility` expose les mêmes états
au premier niveau de chaque résultat véhicule. Ses anciens booléens
`compatible` et `compatibility_ready` ne valent `true` que pour `confirmed`.

L'indicateur général `compatibility_ready` d'une fiche de garage est désormais
faux si l'on ne connaît que « essence » ou un ancien caractère VIN. Il ne
remplace pas le verdict par pièce.

### Adaptation Flutter encore nécessaire

Les anciens clients recevront moins de résultats dans les listes confirmées.
Ils n'auront plus de candidat pris pour une confirmation par simple présence
de son ID. Ils peuvent toutefois encore présenter une liste vide comme une
incompatibilité : l'affichage fidèle de l'incertitude demande une adaptation
du client.

Pour exploiter le nouveau contrat :

1. Lire `status`, `missing_criteria` et `reason`.
2. Réserver la confirmation aux résultats `confirmed`.
3. Afficher les candidats avec les informations à vérifier, si l'écran utilise
   explicitement `include_candidates=1`.
4. Présenter `unknown` comme une information insuffisante, pas une exclusion.
5. Ne pas recopier le caractère du VIN dans le champ code moteur.

## Contrôles de non-régression

Les nouveaux tests couvrent les modifications partielles, les suppressions
explicites, le code moteur, les réponses VIN, les déclarations directes et OEM,
les sources synthétiques, les informations manquantes et les listes publiques,
garage et parc. Un scénario place un candidat avant un confirmé avec une
page d'un élément pour vérifier la pagination avant sélection.

Les chiffres de tests du premier guide concernent le lot précédent. Le bilan
de cette seconde livraison est le suivant :

- 138 tests ciblés réussis, 1 027 assertions, incluant les contrats existants
  de filtres à huile et de pagination.
- Suite complète : 641 tests réussis et deux échecs préexistants dans
  `PagesProduitTest` sur les proportions des aperçus d'images, 3 883 assertions.
- Les fichiers d'aperçus concernés et leurs tests n'ont pas été modifiés.
- Les tests de recherche large demandent désormais explicitement les candidats ;
  le cas Camry avec moteur identifié fournit son code pour une confirmation.
- Aucun manifeste, lockfile, donnée de production ou référence automobile
  supplémentaire n'a été modifié dans ce lot.

Les deux échecs historiques des aperçus restent un sujet distinct. Une
validation sur le moteur de base de production et une revue Flutter restent
nécessaires avant toute affirmation de correction en ligne.

## Suite de F2 dans l'application mobile

Le travail mobile est maintenant préparé dans `autoparc-mobile`, sur
`codex/partenaires-inscription-validation-2026-10-22`. L'édition du parc
retrouve les références par identifiant, retire les dépendances après un
changement de marque ou de modèle et transmet leur suppression à `null`.
Les réponses du parc exposent désormais les six identifiants bruts nécessaires
à cette édition ; les fiches publiques partenaires gardent leur réponse réduite.

Le formulaire de création du garage applique également les transitions de
classification. Il ne devient pas un formulaire d'édition du garage.

Les tests Flutter correspondants sont préparés, mais **pas exécutés** :
l'installation du SDK est bloquée sur son domaine officiel de téléchargement.
Voir le [suivi mobile F2 et partenaires](https://github.com/AchyOUATT/autoparc-mobile/blob/codex/partenaires-inscription-validation-2026-10-22/docs/comptes-partenaires-et-f2.md).
