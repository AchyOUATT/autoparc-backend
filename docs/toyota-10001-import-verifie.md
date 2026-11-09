# Applications documentées du filtre 90915-10001

Le lot du 9 novembre 2026 ajoute huit références de génération et cinq
applications documentées. La source de données est
`database/catalogue/toyota-10001-verified-applications.json`.

| Modèle | Génération | Moteur | Années indexées | Période exacte de la source | Page |
| --- | --- | --- | --- | --- | --- |
| Corolla | E100 | 4A-FE | 1991–1995 | 06/1991–05/1995 | 4 |
| Corolla | E110 | 4A-FE | 1995–2000 | 05/1995–07/2000 | 4 |
| Camry / Scepter | XV10 | 5S-FE | 1992–1996 | 11/1992–09/1996 | 7 |
| Camry Gracia | XV20 | 5S-FE | 1996–2001 | 12/1996–08/2001 | 3 |
| RAV4 | XA10 | 3S-FE | 1994–2000 | 04/1994–05/2000 | 1 |

Ces lignes portent explicitement le numéro d’origine 90915-10001 dans le
[catalogue du fabricant CUSCO](https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf).
Elles documentent le périmètre japonais. Les notes enregistrées gardent les
châssis, les mois, la page et la date de consultation ; aux bornes annuelles,
le VIN et la date de fabrication restent à contrôler. Les données viennent du
texte du PDF ; aucune vérification visuelle de captures n’est revendiquée.

Les générations sont recoupées dans les historiques officiels Toyota, dont les
liens précis sont conservés par modèle dans le manifeste. Toyota confirme
notamment que la Camry export a été commercialisée au Japon comme
[Scepter SXV10 / 5S-FE](https://www.toyota-global.com/company/history_of_toyota/75years/vehicle_lineage/car/id60009803/index.html).

## Référentiel et périodes

Les huit références ajoutées sont Corolla E100/E110/E120/E130, Yaris XP90,
Camry XV10/XV20 et RAV4 XA10. E130 désigne la variante nord-américaine de la
neuvième génération, distincte de E140. Sa désignation le précise.

Les dates globales restent vides lorsque les sources documentent des marchés
ou des millésimes différents. Les périodes régionales restent conservées dans
le manifeste ; une borne japonaise ne doit pas bloquer une voiture américaine
ou européenne authentique. Aucune finition n’est créée.

## Propositions restantes

Les six autres lignes du lot initial restent en attente : Corolla E100/E110
7A-FE, E120/E130/E140 1ZZ-FE et Yaris XP90 1NZ-FE. Le catalogue CUSCO donne
notamment 10003 pour ZZE122/ZZE124 et NCP91 ; une supersession commerciale
vers YZZN1 ne prouve pas l’application inverse du 10001. Les lignes 7A-FE de
DENSO ne suffisent pas à répartir les périodes par génération et marché.

Les deux fichiers historiques de candidats restent des documents de recherche.
Le manifeste du présent lot distingue les cinq lignes documentées et les six
propositions non confirmées. La commande existante d’audit en lecture seule
pour le lot historique s’utilise avec le SKU :

```bash
php artisan catalog:audit-fitment-candidates 90915-10001 --json
```

## Application

La migration ciblée pose les références manquantes puis les applications du
seul article de plateforme actif `90915-10001`. Elle ne crée pas de pièce, ne
relance pas le catalogue global de filtres et ne déduit aucun lien OEM pour
les autres références. Les générations et déclarations existantes sont
préservées. Les prix, stocks et déclarations manuelles ne sont pas réécrits.
Le seeder de références assure uniquement la présence des huit générations sur
une base initialisée ultérieurement.

Le retrait de cette migration conserve les données : des annonces ou des
véhicules peuvent déjà utiliser les références créées. Après déploiement,
vérifier `/api/catalog/sync` et la fiche du filtre en production.
