# Proposition : niveau de preuve `oem_supplier_catalog` (note de conception)

> **Note de conception seulement.** Cette PR ne modifie ni le code
> (`App\Support\FitmentEvidence`), ni la contrainte en base, ni les règles de
> confirmation. Les catalogues des équipementiers d'origine restent en
> `aftermarket_crossref`.

## Contexte

Toyota Boshoku fabrique des filtres de première monte pour Toyota et vend les
mêmes filtres sous sa marque (OS-004, OK-004…). Son catalogue écrit pour
chaque ligne « numéro Toyota → filtre Boshoku », avec le modèle, le moteur et
les **années d'immatriculation**. D'autres équipementiers d'origine font de
même : Denso, Aisin, Mahle ou Mann pour certains constructeurs.

Ce type de catalogue :

- est **plus fiable** qu'une table de correspondance après-vente quelconque,
  car l'éditeur fabrique la pièce d'origine ;
- **n'est pas** une source constructeur :
  - il ne donne ni châssis ni mois ;
  - il raisonne en années d'immatriculation et par marché régional (Asie du
    Sud-Est pour TBAS) ;
  - il peut regrouper plusieurs numéros Toyota, ici « 90915-TB001 /
    90915-YZZD2 », sans dire lequel s'applique à quel véhicule ;
  - il n'est pas exhaustif : aucun Prado n'y figure, par exemple.

## Options

| Option | Effet | Risque |
|---|---|---|
| A. Garder `aftermarket_crossref` (**retenu ici**) | Lignes candidates, aucune règle changée. | Classe ensemble un équipementier d'origine et un vendeur de copies. Le détail reste lisible dans `evidence_sources` et `notes`. |
| B. Ajouter `oem_supplier_catalog` **non confirmable** | Meilleure traçabilité, même verdict qu'en A. | Il faut toucher l'énumération, la contrainte en base (CHECK ou validation), les formulaires, les exports et l'inventaire. Le gain est seulement descriptif. |
| C. Ajouter `oem_supplier_catalog` **confirmable** | Les lignes Boshoku deviennent « confirmées ». | **Refusé** : ni châssis, ni mois, années d'immatriculation, numéros groupés, marché étranger. On confirmerait des lignes qu'aucune source constructeur n'atteste, par exemple un Hilux AN120 de 2021 en 2GD-FTV sur une borne 2020. |

## Recommandation

1. **Aujourd'hui** : option A, telle qu'appliquée par cette PR.
2. **Plus tard, si le besoin de distinguer se confirme** : option B, dans une
   PR dédiée, avec les éléments suivants.
   - Le nouveau niveau n'entre jamais dans `FitmentEvidence::SUFFICIENT`.
   - Une migration additive élargit la contrainte des valeurs admises.
   - Une requalification explicite fait passer les lignes en
     `aftermarket_crossref` dont la source est un équipementier d'origine
     identifié (liste fermée de domaines : `toyota-boshoku.com`, etc.).
   - Les tests vérifient que ce niveau ne confirme jamais, seul ou avec une URL.
3. **Ne jamais** dériver d'un tel catalogue un marché, des mois ou un châssis
   restreint, car ce sont des critères éliminatoires de
   `FitmentRestrictions`. Ces informations vont en note.
