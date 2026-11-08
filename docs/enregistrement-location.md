# Parcours distincts pour la vente et la location

La création d’un véhicule commence par le choix « Vente » ou « Location », dans
le catalogue du personnel comme dans l’espace partenaire. Le parcours vente
conserve son formulaire détaillé et ne propose plus de tarifs locatifs.

## Location

Le formulaire location tient sur une seule page. Il demande uniquement :

- la marque et le modèle ;
- l’année ;
- le carburant ou l’énergie ;
- au moins un tarif journalier, hebdomadaire ou mensuel.

La boîte, le nombre de places, la caution, le lieu de retrait, la description
et les équipements et accessoires sont facultatifs. Aucune boîte automatique ni
valeur de cinq places n’est
préremplie à partir du modèle. Une agence peut être choisie par le personnel ;
ce champ réservé n’est pas envoyé par un partenaire.

Le formulaire ne demande ni prix d’achat ou de vente, ni dossier d’import,
VIN, finition ou caractéristiques moteur détaillées. Le personnel accède aux
photos après la création, et depuis le bouton photos en modification.
Les partenaires retrouvent leur gestion de photos dans leur inventaire.

Le backend conserve ses règles existantes : tarifs non négatifs, avec au moins
un tarif journalier, hebdomadaire ou mensuel, sans calcul automatique entre eux.
Sans déclaration d’immatriculation ou plaque, la création garde le défaut
administratif `unregistered` ; le formulaire n’affirme pas une immatriculation.

## Édition sans perte de données

Modifier une location ouvre directement le formulaire court. Les champs cachés
restent omis : leur absence ne retire ni le VIN, les documents, les équipements,
les défauts, la configuration poids lourd ou les données commerciales existantes.
Les tarifs ne sont envoyés que s’ils changent ; un champ tarifaire effacé est
explicitement envoyé à `null`. Au moins un tarif doit subsister.

Une modification réelle du modèle ou du carburant retire les anciennes
dépendances techniques susceptibles de ne plus correspondre. La catégorie et la
carrosserie sont reprises du nouveau modèle lorsqu’elles sont documentées.

Les anciennes offres mixtes `both` gardent le formulaire complet, avec leurs
prix de vente et tarifs locatifs. Elles ne sont pas converties automatiquement
en location seule.

## Équipements et accessoires facultatifs

La section « Équipements et accessoires » est repliée par défaut. Les choix
proviennent du catalogue existant (confort, sécurité, multimédia, dotation et
aides à la conduite), sans présélection à la création. Les accessoires et services
non proposés peuvent être précisés dans la description facultative.

Sans sélection à la création, `features` est omis. À l’édition, les équipements
inchangés restent omis ; une sélection modifiée est envoyée explicitement, y
compris `features: []` pour tout retirer. Les équipements déjà enregistrés mais
absents du cache de références restent visibles et conservés tant que le vendeur
ne les retire pas. Le changement de modèle ne retire pas ces observations.

## Prix partenaires

La saisie restaure les prix proposés par le partenaire, distincts des prix
publics qui comprennent la marge. Un tarif inchangé n’est pas renvoyé et ne
subit pas une nouvelle majoration. Le récapitulatif au taux courant accompagne
uniquement un tarif nouveau ou modifié.

Les mises à jour partielles fusionnent les prix proposés avec les métadonnées
existantes sous verrou sur le véhicule. Les champs omis conservent leur prix
public, leur prix proposé et leur taux enregistré ; un `null` explicite retire
seulement le champ concerné. `taux_par_champ` conserve les calculs ayant des taux
différents, tandis que `taux` reste celui du prix principal pour les anciens
clients. Ces métadonnées restent réservées aux utilisateurs autorisés.

## API et réservations

Les routes et tables de véhicules existantes sont réutilisées. Aucune migration
de schéma et aucun nouvel endpoint ne sont nécessaires. À la création, le
mobile envoie `availability=rent`. À l’édition, il conserve la disponibilité
existante en omettant ce champ.

Ce changement concerne les annonces. Les contrats et réservations conservent
leurs règles de facturation existantes, décrites dans
[les tarifs de location](tarifs-location-vehicules.md).

La validation couvre le formulaire court, le choix du parcours, les retours de
navigation, les photos, l’affichage FR/EN sur petit écran, la conservation des
champs cachés et les prix partenaires partiels.
