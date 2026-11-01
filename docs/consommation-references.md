# Références de consommation : identité, limites et reprise

Cette évolution prépare les données déjà importées de NRCan et de l’EEA. Elle
ne promet pas une couverture de toutes les versions et n’active aucun nouvel
import EPA, MLIT, GVG ou constructeur.

## Les trois objets à distinguer

Une **configuration technique** identifie une version sur un marché et une
période documentés : modèle et génération, moteur et hybridation, puissance,
boîte, roues motrices et variantes distinguées par la source. Une **référence de
consommation** décrit un résultat pour cette configuration, avec une source,
un protocole, une méthode et des unités. Une **révision** conserve l’état publié
de cette référence. Une correction de publication ne change pas l’identité du
véhicule et ne doit pas réécrire un choix du propriétaire.

Une configuration peut donc avoir plusieurs références valides, puis plusieurs
révisions de chacune. Deux nombres de sources ou de protocoles différents
restent deux résultats consultables. Ils ne deviennent ni une moyenne, ni une
plage censée prédire la consommation réelle. Des résultats contradictoires pour
le même protocole restent visibles avec leur provenance, en attente de
vérification ; le nombre le plus bas n’a aucune priorité.

## Rattachement au catalogue

| Structure existante | Rôle et limite |
| --- | --- |
| `vehicle_models` | Modèle et génération du catalogue. Le rattachement permet de chercher des candidats ; il ne certifie ni le moteur, ni le marché, ni l’applicabilité d’une cote. |
| `trims` | Finition/configuration connue du catalogue. Son nom commercial et ses valeurs par défaut ne prouvent pas à eux seuls la version de l’exemplaire. |
| `trim_applications` | Marché et plage de millésimes documentés pour une finition, avec ses sources et sa date de vérification. Une application documentée ne s’étend pas à toute une génération. |
| `engine_code` | Identifiant moteur supplémentaire lorsqu’il est réellement connu. Une cylindrée arrondie ne permet pas de le reconstruire. |
| `owned_vehicles.motorisation_id` | Référence explicitement choisie par le propriétaire. Ce choix est conservé comme tel ; ce n’est pas une vérification documentaire. |

Les liens `trim_id`, `trim_application_id` et `engine_code` d’une référence
restent facultatifs tant que leur concordance n’est pas démontrée. Ils ne sont
pas remplis par simple ressemblance entre noms. Le marché de commercialisation
est distinct du pays actuel d’immatriculation ; `manufacturing_year` est une
année de fabrication et ne prouve pas systématiquement le millésime.
Le garage peut conserver `model_year` et `specification_market` lorsqu’ils sont
explicitement renseignés ; ils ne sont pas déduits de l’année de fabrication
ni du pays d’immatriculation.

Le VIN peut aider à identifier un modèle ou une génération. Si plusieurs
motorisations restent possibles, la réponse reste « à préciser ». Ni un VIN
valide, ni une génération unique, ni un seul candidat dans une réponse tronquée
ne suffisent à certifier une cote.

## Stockage de ce lot

Le nom historique `motorisations` est conservé pour les clients existants. Une
ligne enrichie porte une référence et sa révision ; ce lot ne crée pas une
table de configurations universelle regroupant arbitrairement tous les
organismes.

| Champ | Signification |
| --- | --- |
| `identity_key` | Identité stable d’une configuration telle que cette source la distingue, en tenant compte des caractéristiques connues et du protocole. Ce n’est pas un identifiant universel entre sources. |
| `cle_source` | Identité de révision, distincte de l’identité stable. Une réimportation identique ne duplique pas la même révision. |
| `is_current` | Révision encore proposée pour cette identité. Une date de publication comparable est nécessaire pour en retirer une plus ancienne ; sans chronologie documentée, les alternatives restent visibles. Les anciennes lignes sélectionnées sont conservées. |
| `power_kw`, `displacement_cc` | Valeurs techniques conservées avant tout arrondi d’affichage. Deux puissances d’une même cylindrée doivent rester distinctes. |
| `market`, `year_kind`, `hybrid_type` | Marché, nature de l’année et hybridation lorsqu’ils sont documentés. Une information inconnue reste inconnue. |
| `method` | `test`, `adjustment`, `aggregation` ou `unknown`. Un ajustement historique n’est pas présenté comme un essai mesuré selon le nouveau protocole. |
| `reference_status` | `legacy`, `documented` ou `withdrawn`. Une donnée importée et traçable n’est pas pour autant vérifiée pour un exemplaire. |
| `provenance`, `verified_at` | Éléments réellement disponibles de la source et vérification documentaire distincte des dates techniques. Une date d’import n’est pas une date de vérification. |

L’ancien index d’unicité fondé sur huit colonnes est retiré : il empêchait de
conserver certaines nouvelles variantes ou révisions. Les clés de source
traitent explicitement les valeurs manquantes pour assurer la répétabilité de
l’import.

La provenance doit permettre de retrouver la ressource et la transformation :
URL, identifiant lorsqu’il existe, empreinte du contenu, année ou période,
protocole, valeurs originales et dates disponibles. Pour une agrégation,
conserver aussi la requête/les filtres, l’effectif et la limitation de
l’extraction. Une date ou une licence absente ne reçoit pas une valeur supposée.
Les contenus de source importés sont archivés sur le disque local Laravel dans
`consumption-sources/<sha256>` ; la provenance conserve leur empreinte pour
permettre l’examen d’un résultat même si le fichier distant est republié.

`consumption_imports` distingue les exécutions, leur source, leur périmètre,
leur état, leurs fichiers et leurs dates. Un import local ou partiel ne
certifie pas la fraîcheur de toute la source. La garde de fraîcheur repose sur
un import complet du périmètre concerné, jamais sur le maximum de
`motorisations.updated_at`.

## Correspondance et effets sur le suivi

- **Correspondante** : concordance documentée de la version et de son marché,
  sans contradiction ni ambiguïté non résolue. L’année doit avoir la bonne
  signification ; l’absence d’informations ne constitue pas une concordance.
- **Approchante** : référence utile à comparer, avec différences ou limites
  explicites. Une agrégation EEA est au mieux approchante, même avec un nom et
  une puissance concordants.
- **Incomplète** : identification insuffisante, héritage non requalifié,
  contradiction ou plusieurs variantes encore possibles. Aucune valeur
  principale n’est choisie automatiquement ; les détails peuvent conserver la
  valeur de la source et expliquer pourquoi elle n’est pas attribuée au véhicule.

Ces niveaux sont calculés côté serveur et ne deviennent pas « correspondants »
parce que l’utilisateur touche une ligne. Un marché inconnu ou une simple
année voisine interdit le statut correspondant. Les informations actuellement
portées par les véhicules du garage ne suffisent pas à démontrer une
configuration technique complète. Le niveau correspondant reste donc réservé
dans ce lot ; le simple ajout d’un marché et d’un millésime ne le débloque pas.

Une modification du modèle, de l’année ou de la motorisation doit réévaluer
l’applicabilité sur **l’état résultant**, y compris pour un PATCH partiel.
Une référence incompatible ne remplace pas silencieusement une autre. Une
nouvelle publication conserve la révision choisie et sa trace ; elle ne migre
pas automatiquement le propriétaire vers un nouveau nombre.

Le suivi des pleins mérite une protection spécifique : il utilisait la cote
sélectionnée pour exclure des intervalles sous un seuil relatif. Une référence
héritée, approchante, incomplète ou un mode de rechargeable non pertinent ne
doit pas servir de preuve pour rejeter une mesure réelle. Ce lot supprime
l’utilisation de la référence sélectionnée comme seuil et conserve les règles
internes sur les pleins et leur médiane ; il ne fabrique pas de cote de
remplacement. Cela conserve une limite connue : une majorité de pleins oubliés
peut déplacer la médiane et ressembler à une dérive de consommation. Rebrancher
un seuil externe demanderait des références admissibles et une validation
spécifique. Un changement de référence doit être testé aussi sur les
intervalles retenus et sur les alertes.

## Sources et conditions de réutilisation

Contrôle du 31 octobre 2026 : les accès HTTPS ci-dessous ont tous été refusés
par le proxy de cet environnement (`CONNECT 403`). **Aucune licence n’a été
vérifiée en ligne pendant cette session.** Ce refus est une limite d’accès,
pas une conclusion sur les droits de réutilisation. Une mention dans ce tableau
ne vaut pas autorisation d’importer.

| Source | Point d’entrée officiel à examiner | État et condition avant extension |
| --- | --- | --- |
| NRCan | [Métadonnées CKAN du jeu de cotes](https://open.canada.ca/data/api/3/action/package_show?id=98f1a129-f628-4ce4-b24d-6f16bf24dd64) | Connecteur existant. Vérifier la licence liée au jeu et aux ressources ; conserver `license_id`, `license_title`, `license_url` lorsqu’ils sont fournis. Vérifier les obligations d’attribution et la distinction historique/ajustement. |
| EEA | [Conditions officielles de copyright](https://www.eea.europa.eu/en/legal/copyright) et [jeu de données](https://www.eea.europa.eu/en/datahub/datahubitem-view/fa8b1229-3db6-495d-b18e-9c9b3267c02b) | Connecteur existant. Vérifier les conditions de la ressource exacte et les éventuelles exceptions de tiers ; documenter l’attribution et la méthode d’agrégation. La disponibilité d’un service SQL ne prouve pas une licence générale. |
| EPA/DOE | [Documentation FuelEconomy.gov](https://www.fueleconomy.gov/feg/ws/) | Non activé. Vérifier les conditions de la ressource/API exacte, l’attribution, les éventuelles données de tiers et les conventions d’unités. |
| MLIT | [Listes japonaises de consommation](https://www.mlit.go.jp/jidosha/jidosha_tk10_000050.html) | Non activé. Vérifier les conditions de réutilisation, les fichiers concernés, les exceptions de tiers et la correspondance des codes de type/version. |
| GVG | [Guide officiel australien](https://greenvehicleguide.gov.au/pages/ToolsAndCalculators/HowToGuide) | Non activé. Vérifier qu’une ressource réutilisable et un accès autorisé existent ; un site de consultation ne promet pas un fichier ouvert. |
| Constructeur régional | [Exemple Toyota Afrique du Sud](https://www.toyota.co.za/vehicles/hilux-double-cab?explore=models) | Non activé. Examiner les droits du document précis, sa révision et le droit de redistribuer son contenu ; aucune autorisation générique n’est déduite de sa publication sur le Web. |

Avant tout nouveau connecteur, conserver une preuve datée des conditions
applicables, définir le texte d’attribution et préciser les droits d’extraction,
de transformation et de redistribution. Si les droits restent inconnus, le
connecteur reste inactif. Les licences ne sont ni déduites du caractère public
d’un organisme, ni héritées d’un autre jeu de données du même organisme.

## Déploiement, reprise et critères de contrôle

Appliquer d’abord la migration additive, puis le serveur compatible et enfin
le mobile. Les champs existants restent lisibles par les anciens clients ;
les nouveaux champs expliquent le niveau et la provenance aux nouveaux
clients. Une valeur de source conservée pour examen doit rester distincte de
la valeur principale applicable. Le mobile ne doit pas interpréter l’absence
de métadonnées d’un ancien serveur comme une vérification.

Sauvegarder la base avant migration. Les anciennes références et leurs choix
restent en héritage ; les informations précédemment écrasées demandent une
source exploitable pour être réimportées. Pour revenir à l’application
précédente après de nouveaux imports, conserver le schéma enrichi et les
révisions. Une migration `down` qui supprime les colonnes de provenance n’est
pas un retour arrière sans perte de données.

Les vérifications attendues portent notamment sur :

1. Deux puissances EEA de même cylindrée conservées, y compris sous MySQL.
2. Réimport identique sans doublon ; nouvelle révision sans mutation de
   l’ancienne référence sélectionnée ; plusieurs protocoles conservés.
3. Année du registre distincte du millésime ; extraction EEA limitée déclarée
   comme telle ; échec partiel ne rendant pas toute la source fraîche.
4. Thermique, hybride et rechargeable non fusionnés ; marché inconnu, VIN
   ambigu et année voisine jamais présentés comme une correspondance vérifiée.
5. Changement de carburant, d’année ou de modèle requalifiant le choix ; aucune
   substitution silencieuse et aucun effet injustifié sur les pleins/alertes.
6. Présentation distincte des sources et protocoles, détails de provenance,
   états sans données et anciens contrats API, en français et en anglais.

Les tests et leur résultat effectif sont rapportés avec le changement ; cette
liste décrit les critères et ne prétend pas que chaque connecteur candidat ou
chaque plateforme mobile a été exécuté.

### Validation de cette réalisation — 1er novembre 2026

- Suite backend complète : 1 227 tests réussis, 6 875 assertions.
- Sur MariaDB : 39 tests d’import réussis ; le groupe imports, références,
  annonces et suivi a également passé 106 tests. Après les derniers contrôles
  de transmission et de finition, les 34 tests concernés ont été réexécutés
  avec succès sur MariaDB.
- Deux commandes d’import identiques lancées simultanément terminent sans
  erreur et conservent six références distinctes, sans doublon ni écrasement.
- Mobile : génération des traductions FR/EN et vérification de syntaxe/format
  Dart effectuées. Les tests Flutter et l’analyse avec dépendances restent
  non exécutés : le SDK disponible est Flutter 3.44.0, alors que le projet
  exige au moins 3.47.2. Les contraintes du projet ont été conservées.

Les contrôles utilisent les extraits de données et les réponses simulées des
tests. Ils ne valident ni l’accessibilité actuelle des organismes, ni les
licences non consultables depuis cet environnement, ni une couverture réelle
de toutes les versions.
