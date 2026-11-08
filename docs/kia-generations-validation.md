# Générations Kia : validation documentaire en attente

Les quatre entrées ci-dessous proviennent du patch `kia_generations.patch`
fourni par le propriétaire du projet. Elles ont été appliquées et vérifiées
techniquement en local ; leurs périodes n'ont pas été authentifiées auprès de
sources constructeur. Les sites Kia étaient inaccessibles dans cet environnement.

| Modèle | Génération | Début proposé | Fin proposée |
| --- | --- | --- | --- |
| Cerato | YD | 2013 | 2018 |
| Sportage | SL | 2010 | 2015 |
| Sorento | XM | 2009 | 2014 |
| Sorento | MQ4 | 2020 | non renseignée |

Point ouvert : le commentaire du patch indique une production du Cerato YD dès
2012, alors que la ligne commence en 2013. Il faut distinguer production,
millésime et marché avant de corriger ou de confirmer cette borne. Une fin nulle
ne constitue pas une preuve que tous les marchés commercialisent encore le modèle.

Aucune finition ou compatibilité de pièce n'est déduite de ces ajouts. Les tests
de catalogue valident l'intégration et l'idempotence, pas l'exactitude historique.

Ce lot est isolé dans un commit de la branche de travail pour permettre sa revue
indépendamment des détails moteur, des poids lourds et des recommandations de
consommation.
