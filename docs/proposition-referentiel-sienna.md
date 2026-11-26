# Proposition : restructurer le référentiel Toyota Sienna

> **Statut : proposition, rien n'est appliqué.** Ce document ne contient ni
> migration ni seeder, et ne modifie aucune donnée. Chaque étape attend
> l'accord du propriétaire.

## 1. État actuel (production, lecture seule, 26/11/2026)

| Élément | Valeur |
| --- | --- |
| Ligne `vehicle_models` | une seule ligne, slug `sienna`, génération vide, production 2007 à ouvert, active, sans alias |
| Finitions, véhicules, véhicules enregistrés | 0 / 0 / 0 |
| `oem_number_fitments`, `accessory_fitments`, besoins clients, annonces | 0 |
| `motorisations` | 86 lignes (source nrcan, marché CA, 2007–2026 ; 2,5 / 2,7 / 3,5 l ; 43 en `model_year`, 43 en `unknown`) |
| `part_fitments` | 4 lignes, toutes déclarées, détaillées ci-dessous |

| Pièce | Moteur | Années |
| --- | --- | --- |
| 90915-YZZN1 | A25A-FXS | 2021– |
| 04152-YZZA1 | 2GR-FE | 2007–2016 |
| 04152-YZZA1 | 1AR-FE | 2011–2013 |
| 04152-YZZA1 | 2GR-FKS | 2017–2020 |

La PR #41 ajoute `sienna-xl10` (1997–2002). Elle ne touche pas à la ligne nue.

Le problème : la ligne nue regroupe sous une seule fiche, en démarrant en
2007, la fin de la XL20, la XL30 et la XL40. Il manque aussi les
années 2003–2006 de la XL20.

## 2. Générations selon l'EPC Toyota (production, marché nord-américain)

| Génération | Châssis | Moteur | Production EPC | Catalogue |
| --- | --- | --- | --- | --- |
| XL10 | MCL10 | 1MZ-FE | 08/1997 – 12/2002 | 622410 |
| XL20 | MCL20/23/25 | 3MZ-FE | 01/2003 – 12/2006 | 622420 |
| XL20 | GSL20/23/25 | 2GR-FE | 12/2006 – 01/2010 | 622420 |
| XL30 | GSL30/33/35 | 2GR-FE puis 2GR-FKS | 01/2010 – 09/2020 | 622430 |
| XL30 | ASL30 | 1AR-FE | 05/2010 – 08/2013 | 622430 |
| XL40 | AXLH40/45 | A25A-FXS (hybride) | 11/2020 – | 622440 |

Les pages de catalogue sont sous
`https://toyota-epc.aftermarketcatalog.com/vehicle/US/<catalogue>/info`.
Wikipedia n'est qu'une source secondaire.

## 3. Cible

On suit la même méthode que pour le Tacoma (`tacoma-i` à `tacoma-iv` plus
une ligne nue inactive).

| Slug | Génération | Production |
| --- | --- | --- |
| `sienna-xl10` | XL10 | 1997–2002 (PR #41) |
| `sienna-xl20` | XL20 | 2003–2010 |
| `sienna-xl30` | XL30 | 2010–2020 |
| `sienna-xl40` | XL40 | 2020– |
| `sienna` | ligne nue | conservée, rendue inactive ou laissée en recherche seulement, **pas supprimée** et **pas renommée** |

Les années partagées (2010 et 2020) sont réelles et restent des deux côtés.

## 4. Étapes proposées

Chaque étape fait l'objet d'une PR séparée. Les migrations sont additives et
idempotentes, et ciblent les lignes par slug, référence et code moteur, sans
identifiant en dur.

1. **Créer** `sienna-xl20`, `sienna-xl30` et `sienna-xl40` (seeder et
   migration `firstOrCreate` par slug). Aucun effet sur l'existant.
2. **Dupliquer** les compatibilités vers les nouvelles générations. Les
   lignes de la ligne nue restent en place pour l'instant.
   - 04152-YZZA1, 2GR-FE : 2007–2010 vers XL20 (GSL2x) et 2010–2016 vers
     XL30 (GSL3x), après vérification de chaque partie dans l'EPC.
   - 04152-YZZA1, 1AR-FE : 2010–2013 vers XL30 (ASL30). La ligne actuelle
     dit 2011–2013 et l'EPC donne 05/2010 ; il faut relire le schéma 15-02
     avant de corriger.
   - 04152-YZZA1, 2GR-FKS : 2017–2020 vers XL30.
   - 90915-YZZN1, A25A-FXS : 2021– vers XL40. L'EPC donne 11/2020, donc
     2020 est à valider.
   - Ajout : 90915-YZZD1 sur la XL20, 3MZ-FE (MCL20/23/25) 2003–2006. La
     recherche inverse EPC US de 90915-20003 liste les MCL2x.
3. **Motorisations nrcan** : rattacher chacune des 86 lignes à la génération
   qui couvre son année. Une ligne `model_year` N correspond le plus souvent
   à une fabrication en N-1 ou N. Les lignes `unknown` sont rattachées par
   cylindrée (2,7 l = XL30 1AR-FE ; 2,5 l = XL40 ; 3,5 l = XL20 ou XL30
   selon l'année). En cas de doute, la ligne reste sur la ligne nue.
4. **Ligne nue** : une fois les étapes 2 et 3 vérifiées, au choix du
   propriétaire :
   - (a) la rendre inactive, comme `tacoma` ;
   - (b) vider ses bornes de production et la garder comme entrée de
     recherche.

   Les lignes `part_fitments` de la ligne nue ne sont supprimées qu'avec un
   accord explicite. Si on les garde, elles sont filtrées par l'inactivité
   de la ligne nue.
5. **Contrôle** : test d'invariant des chevauchements
   (`CatalogueModelesTest`) ; tests API (XL20 3MZ-FE 2005 confirmé avec
   YZZD1, XL30 2GR-FE 2012 confirmé avec 04152-YZZA1, XL40 2019 absent) ;
   vérification en lecture seule du nombre de lignes avant et après.

## 5. Risques

- **Véhicules enregistrés futurs** : il n'y en a aucun aujourd'hui. Il
  faut restructurer avant qu'un utilisateur rattache une Sienna à la ligne
  nue.
- **Décodage VIN** : avec une ligne nue active, un VIN de Sienna 2015
  pourrait encore pointer vers elle. Rendre la ligne nue inactive (option a)
  règle ce cas.
- **Millésime ou fabrication** : nrcan donne des années-modèle et l'API
  compare des années de fabrication. Les mois d'écart en limite de
  génération doivent être notés et vérifiés au VIN.

## 6. Décisions attendues du propriétaire

1. Valider le découpage XL20, XL30 et XL40, et ses bornes.
2. Choisir l'option (a) ou (b) pour la ligne nue.
3. Dire si l'on conserve ou supprime les 4 lignes `part_fitments` de la ligne
   nue une fois dupliquées.
4. Dire si l'on corrige 1AR-FE à 2010 et A25A-FXS à 2020 (selon l'EPC)
   après relecture du schéma.
