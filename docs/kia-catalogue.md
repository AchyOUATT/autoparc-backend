# Catalogue Kia — lot du 5 novembre 2026

Le lot passe de 10 à 26 entrées modèle/génération. Après contre-vérification,
il documente les 75 finitions retenues : 75 applications dans 19 lots
marché/millésime, réparties sur 18 générations. Les anciennes appellations
Sportage QL SX et GT Line sont désormais documentées pour l'Australie MY2020.
Ce lot ne constitue pas une gamme exhaustive de la marque.

## Générations et noms commerciaux

`database/catalogue/kia-models.json` centralise les définitions et les liens
constructeur. Les dix entrées existantes gardent leurs noms et slugs.
Les bornes ont été revues avec leurs périmètres documentaires.
L'upsert conserve leurs IDs et dates de création. Le lot ajoute Picanto SA,
Rio JB, Cerato LD/TD/YD, Sportage KM/SL, Sorento BL/XM/MQ4, Optima TF/JF,
K5 DL3 et Soul AM/PS/SK3.

Les alias Morning, Forte et K5 sont attachés aux générations concernées.
Le nom canonique reste Picanto, Cerato ou Optima : les alias ne créent aucune
seconde ligne. K3 n'est volontairement pas un alias universel de Cerato :
ce nom désigne aussi une famille plus récente distincte. Les alias n'établissent
ni compatibilité de pièces ni correspondance exacte pour la consommation.

Les années de génération servent de repères de sélection. Les bornes de
transition retenues ne certifient pas la fin de production dans toutes les
usines, ni les dates de commercialisation de tous les marchés. Une fin nulle
signifie que le catalogue ne fixe pas de fin ; elle ne prouve pas une production
encore active. Une année de fabrication et un millésime doivent rester distincts.
En particulier, YD débute en 2012 ; le MY2013 américain n'est pas sa naissance
mondiale. Le Sorento XM commence en 2009 dans les références européennes,
alors que l'historique Service 5+ commence sa liste en 2010.

TD reste couvert jusqu'en 2013 alors que YD commence en 2012. La couverture
documentaire YD va jusqu'en 2019 dans le menu d'entretien Kia saoudien.
Les autres fins corrigées sont Sportage SL 2016 (référence Europe, distincte
de 2015 en Corée), Optima TF 2016, Soul AM 2014 et Soul SK3 2025.
Les cinq recouvrements sont consignés dans `documented_overlaps`.
L'import des
consommations conserve désormais un rattachement nul lorsque plusieurs
générations correspondent à l'année, y compris les années de transition :
l'ordre des lignes en base ne constitue pas une preuve. Les alias commerciaux
ne sont pas utilisés pour promouvoir automatiquement une cote en correspondance
technique exacte.

La liste [Kia Service 5+](https://www.kia.com/fr/services/apres-vente/service-offers/kia-service-5-plus/)
confirme de nombreux codes historiques, mais s'arrête à 2017 pour plusieurs
modèles : cette coupure ne justifie aucune fin de génération. Elle a été retirée
des citations génériques qui ne prouvaient pas les données des modèles récents.
Les archives [Forte 2008](https://worldwide.kia.com/en/brand/our-brand/heritage/vehicles/forte),
[K5 2015](https://worldwide.kia.com/en/brand/our-brand/heritage/vehicles/all-new-k5-2nd-gen)
et [K5 2019](https://worldwide.kia.com/en/brand/our-brand/heritage/vehicles/all-new-k5-3rd-gen/)
complètent les lancements et noms régionaux. Les références Navigation Kia
corroborent les codes des modèles récents.

## Finitions documentées

`database/catalogue/kia-trims-verified.json` utilise le format existant
`catalog:import-trims` : chaque application cite son marché, un seul millésime,
une URL constructeur et les dates de consultation/vérification. Les marchés
retenus sont US, ES pour Picanto JA et SK pour Stonic. Aucun millésime n'est
étendu à toute la génération et aucun marché n'est déduit du pays actuel.

Chaque lot retenu possède maintenant un document constructeur complet lu en PDF.
Les brochures récentes proviennent directement de Kia ; certaines anciennes
brochures Kia sont des copies archivées sur des CDN de concessionnaires.
L'émetteur, l'hébergement, les pages, la taille et l'empreinte SHA-256 sont
distingués dans `database/catalogue/kia-verification.json`. Un hébergement tiers
n'est pas présenté comme le site officiel Kia. Les pages Kia America qui
répondent 403 ne constituent plus l'unique preuve d'un nom importé.

Le document Picanto espagnol mentionne explicitement MY20, et le Stonic slovaque
« MODELOVÝ ROK 2021 ». La fiche australienne Sportage porte MODEL YEAR 2020,
KMAU4662 / MSHQL201906, imprimée décembre 2019 ; deux copies identiques ont été
comparées. Pour Forte MY2019, le bulletin technique Kia conservé par la NHTSA
confirme explicitement BDm et les noms FE/LXS/S/EX : il tranche la divergence
avec une brochure initiale nommant LX. BDm est rattaché à la famille BD,
sans affirmer une identité technique entre toutes ses variantes.

Les noms de niveaux sont regroupés sans suffixes moteur pour Sorento UM,
Seltos SP2 et Optima JF ; les variantes moteur restent à préciser séparément.
Le nom commercial SX Turbo est conservé. Pour Optima TF MY2015, le lot retient
Limited, nom de la brochure complète, et ne déduit pas silencieusement un alias
depuis le libellé SXL Turbo du tarif indexé. Pour Soul MY2020, GT-Line Turbo
remplace EX Designer Collection : la brochure finale prévaut sur cette
appellation annoncée au lancement. Aucun nom ne renseigne un champ technique
automatiquement.
Picanto TA et les générations anciennes sans preuve de finition suffisante
restent sans nouvelle finition. Les IDs SX et GT Line historiques du Sportage QL
ne sont ni supprimés ni renommés ; le tiret de GT-Line dans la source est une
normalisation typographique explicitée dans le dossier de vérification.

## Valeurs techniques et déploiement

Tous les nouveaux défauts moteur et motricité restent nuls. La migration
`2026_11_05_000002` retire uniquement les quatre couples de défauts QL historiques
reconnus par marque, génération, nom, code et anciennes valeurs. Un couple
personnalisé est préservé et demande un examen distinct. Aucun véhicule,
aucune lecture de compteur et aucune caractéristique explicite ne sont modifiés.
La [gamme US MY2020](https://www.kiamedia.com/us/en/models/sportage/2020)
montre notamment que les niveaux LX/EX/SX existent en FWD ou AWD.

`VehicleModelsSeeder` charge les générations ; `TrimsSeeder` conserve les quatre
noms historiques avec des défauts nuls sur une base neuve. `KiaCatalogueSeeder`
importe le lot documenté avec la validation existante, sans autorisation de
remplacer des descriptions/codes personnalisés. Il est appelé après ces deux
seeders au démarrage Docker et dans `DatabaseSeeder`. Un rejeu identique
conserve IDs et horodatages. Un lot refusé est signalé dans le journal.

L'API modèles et `/api/catalog/sync` exposent `aliases`. Le delta réannonce les
modèles dont les alias changent et les finitions nouvellement documentées.
Le mobile doit lire ce champ pour afficher les noms régionaux ; les anciennes
versions continuent à lire le nom canonique. Les nouvelles générations et
finitions arrivent par synchronisation sur les clients existants.

## Portée de la confirmation

Le dossier versionné `kia-verification.json` conserve les constats par champ,
les corrections et les documents employés. Les 26 identités et les noms retenus
ont été contrôlés. Les classifications internes telles que `suv` pour CUV,
ou C pour une berline compacte BD, sont identifiées comme correspondances
éditoriales lorsqu'une lettre constructeur n'est pas explicitement trouvée.
Les bornes de référence régionales sont documentées ; les dates mondiales de
fabrication restent expressément non certifiées. Une fin inconnue reste nulle.
Les tests vérifient la cohérence du lot et sa traçabilité, pas le contenu futur
de sites externes ni une configuration mécanique précise d'un exemplaire.
