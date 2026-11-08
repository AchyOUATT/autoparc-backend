# Enregistrer un véhicule avec un tarif journalier, hebdomadaire ou mensuel

Le contrat concerne la fiche commerciale du véhicule et sa page de partage
publique. Le tarif hebdomadaire est accepté seul depuis le correctif du
7 novembre 2026, sans migration de schéma ni conversion entre périodes.

## Saisie via l'API

`POST /api/vehicles` et `PATCH /api/vehicles/{id}` acceptent un véhicule
proposé à la location (`availability: "rent"`) avec :

- un prix journalier (`rental_daily_rate`) ;
- un forfait hebdomadaire (`rental_weekly_rate`) ;
- un forfait mensuel (`rental_monthly_rate`) ;
- ou plusieurs de ces tarifs, renseignés indépendamment.

Auparavant, le journalier était toujours obligatoire, même avec un mensuel.
Les colonnes et les champs de réponse existaient déjà : aucune migration
de base de données n'est nécessaire.

Exemple mensuel uniquement, à compléter avec les identifiants réels :

```json
{
  "brand_id": 1,
  "vehicle_model_id": 42,
  "engine_type_id": 1,
  "manufacturing_year": 2020,
  "availability": "rent",
  "rental_daily_rate": null,
  "rental_monthly_rate": 500000,
  "currency": "XOF"
}
```

Pour un journalier seul, renseigner par exemple `rental_daily_rate: 25000`
et omettre les autres tarifs. Pour une semaine, renseigner par exemple
`rental_weekly_rate: 150000` seul. La disponibilité `both` applique la même alternative
locative et conserve l'obligation d'un `sale_price`.

## Modification et contrôles

La validation porte sur la fiche résultante : un tarif omis conserve sa
valeur en base ; un `null` explicite le retire. Il est possible de remplacer
le journalier par le mensuel dans une seule requête. Les champs d'identité
obligatoires du parc restent requis lors de la modification.

Un véhicule restant en `rent` ou `both` doit conserver au moins un des trois
tarifs. Une réponse 422 désigne `rental_daily_rate`, `rental_weekly_rate` et
`rental_monthly_rate` si les trois sont absents ou retirés, sans enregistrer
la modification. Un tarif hebdomadaire omis dans une modification conserve
sa valeur et peut satisfaire cette exigence à lui seul.

Les montants doivent rester numériques et non négatifs, comme auparavant.
La valeur zéro demeure acceptée par l'API ; la page partagée conserve sa
règle existante qui masque les montants nuls. Aucune conversion automatique
entre jour, semaine et mois n'est effectuée.

## Réponse et affichage

Les réponses de création, de modification et du catalogue public exposent
toujours les champs dans `data.commercial` :

```json
{
  "availability": "rent",
  "rental_daily_rate": null,
  "rental_weekly_rate": null,
  "rental_monthly_rate": "500000.00",
  "currency": "XOF"
}
```

La page partagée privilégie le journalier, puis le tarif « / semaine », puis
le forfait « / mois ». Si plusieurs tarifs sont proposés, elle affiche aussi
les tarifs complémentaires. Le prix de vente reste prioritaire dans le
titre d'une fiche mixte. Les tarifs locatifs ne sont pas montrés lorsque
la fiche n'est pas proposée à la location.

Le parcours mobile simplifié et la conservation des données lors de l’édition
sont décrits dans [l’enregistrement pour location](enregistrement-location.md).

## Contrats de location : périmètre distinct

Les forfaits hebdomadaires et mensuels enregistrés sont des tarifs d’annonce. Les réservations et
retours existants (`POST /api/rentals`, `RentalService`) calculent toujours
les montants en jours. Pour un véhicule uniquement hebdomadaire ou mensuel, une réservation
ne peut donc pas reprendre automatiquement le forfait : le service exige
encore un `daily_rate` explicite.

Sans ce tarif, l'API répond désormais 422 avec une erreur sur `daily_rate`,
au lieu d'une erreur serveur. Aucune réservation n'est créée et le véhicule
conserve son statut.

La réponse de création d'un contrat relit aussi les valeurs par défaut
enregistrées en base, notamment le statut de paiement. Cela évite une erreur
de sérialisation après une réservation pourtant créée.

Cette modification ne crée pas de contrat hebdomadaire ou mensuel et ne divise
pas les forfaits par 7 ou par 30. Une facturation mensuelle demanderait de définir la durée du mois,
le traitement des périodes incomplètes et les règles de prolongation.

## Vérification du 22 octobre 2026

- 30 nouveaux tests API réussis : création, modification, conservation des
  tarifs omis, retrait explicite, validation des montants et contrats journaliers.
- 5 nouveaux tests de partage réussis : mensuel seul, deux tarifs, journalier
  nul, prix de vente résiduel et offre mixte. Les cas existants restent couverts.
- Suite complète : 676 tests réussis, 2 échecs préexistants sur le cadrage
  des aperçus d'images, 4 046 assertions. Ces deux mêmes échecs avaient déjà
  été reproduits sur la version de référence avant ce travail.
- Aucune migration, modification de données de production ou intervention
  dans le dépôt Flutter.
