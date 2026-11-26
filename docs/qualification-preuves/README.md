# Qualification des 143 compatibilités historiques

Ce chantier est distinct de la fusion de #42 à #44. **Il ne modifie aucune donnée.** Ce dossier ne contient que l'inventaire et la procédure.

## Pourquoi

Avec la preuve stricte (#42), une compatibilité ne rend « confirmé » que dans deux cas :
- son `evidence_level` vaut `manufacturer_official`, `manufacturer_epc` ou `dealer_catalog`, et `evidence_sources` contient au moins une URL http(s) ;
- son niveau est `human_verified`, avec une URL, le compte du valideur (`verified_by_id`) et la date (`verified_on`).

En production, les 143 lignes de `part_fitments` n'ont aucune preuve structurée. Elles deviennent donc « candidat » au mieux. Le texte des notes, même s'il cite une source, ne compte pas. Les 5 couples véhicule × pièce du parc qui passent de « confirmé » à « candidat » en font partie (lignes 507, 494 et 509).

Rien n'est rempli automatiquement, sauf les 15 lignes dont l'URL a été **relue page par page** : c'est la proposition séparée `feat/preuves-retro-remplissage-notes`, au commit 69a55b1, sans PR, à ne fusionner que sur décision. Elle couvre :
- 3 lignes constructeur, posées en `manufacturer_official` :
  - Caterpillar 435-6493 sur la 140 GC ;
  - John Deere RE504836 sur la 5090M ;
  - John Deere RE59754 sur la 5410.
- 12 lignes CUSCO et HKS, posées en `aftermarket_crossref`. Ce niveau est insuffisant : ces lignes restent « candidat ». Un tableau d'après-vente qui recopie un numéro Toyota n'est ni le constructeur ni un concessionnaire.

Les cinq lignes CUSCO du filtre 90915-10001 (fitments 424 à 428, priorité P2) sont qualifiées par la migration `2026_11_26_210000_qualifier_les_preuves_aftermarket_du_filtre_90915_10001` : `aftermarket_crossref`, URL CUSCO, marché JP, mois des périodes source. Elles restent candidates. Ce n'est pas le lot de rétro-remplissage `feat/preuves-retro-remplissage-notes`. L'inventaire ci-dessous reflète cet état pour ces cinq lignes ; les autres colonnes restent celles de l'export du 26/11/2026.

L'ancienne règle « par domaine » (deere.com ⇒ constructeur) a été retirée. Elle aurait promu toute future ligne citant ce domaine sans que la page ait été lue.

## Inventaire : `inventaire-143-compatibilites.csv`

Il est produit par `generer-inventaire.py` à partir des exports en lecture seule (`scripts/verification-deploiement/export-*.sql`) du 26/11/2026. Il est reproductible à l'identique.

| Colonne | Contenu |
|---|---|
| `priorite` | **P1** : la ligne s'applique à un véhicule réel (parc `vehicles` ou garage `owned_vehicles`, même modèle, année dans la plage, moteur compatible ou inconnu). **P2** : pièce active en stock. **P3** : pièce active sans stock. **P4** : pièce inactive ou supprimée. |
| ordre dans une priorité | nombre de véhicules réels touchés, puis valeur du stock (stock × prix). La production n'a **aucune vente** (`sales`, `part_orders`, `part_order_items` et `stock_movements` sont vides au 26/11/2026). Le tri par ventes est donc remplacé par le stock. |
| `vehicules_reels_touches` | `parc:id` ou `garage:id`, avec l'année et le moteur connu. Aucun VIN, aucune plaque. |
| `nature_source_actuelle` | ce que les notes citent aujourd'hui (voir ci-dessous). |
| `niveau_justifie_aujourd_hui` | renseigné seulement pour les 15 lignes relues. Vide ailleurs : aucun niveau n'est supposé. |
| `preuve_requise` / `action_proposee` | ce qu'il faut trouver pour confirmer, et que faire à défaut. |
| `notes_actuelles` | les notes de la ligne, complètes. |

### Répartition

| Priorité | Lignes | Nature de la source |
|---|---|---|
| P1 | 15 | 10 document fournisseur sans URL ; 4 sans source (PE01-18-221 et 04152-YZZA4) ; 1 « MazdaPartsNow / Mazda USA » sans URL |
| P2 | 103 | 64 document fournisseur ; 27 sans source ; 6 Mazda sans URL ; 1 Bosch ; 5 CUSCO relues |
| P3 | 1 | sans source |
| P4 | 24 | 3 constructeur relues ; 7 CUSCO/HKS relues ; 14 sans source |

Les 15 lignes P1 couvrent les 14 couples véhicule × pièce non « inconnu » du parc et du garage :
- les 5 couples qui étaient « confirmé » ;
- les 9 couples déjà « candidat ».

Les véhicules concernés sont parc 86, 89, 93, 97, 101, 103, 111, 113 et 116, plus garage 2 et 6.

Les codes moteur d'un seul caractère saisis au garage (« 7 », « E », « K » : un caractère du VIN) sont traités comme inconnus, comme le fait le moteur de compatibilité.

## Ce qui compte comme preuve

| Niveau | Source acceptée | Exemples |
|---|---|---|
| `manufacturer_epc` | catalogue électronique de pièces du constructeur, page ou capture hébergée avec URL | EPC Toyota/Lexus, EPC Mazda |
| `manufacturer_official` | document public du constructeur du véhicule qui nomme le numéro pour le modèle | guides de filtres John Deere, Cat Parts Store |
| `dealer_catalog` | catalogue en ligne d'un **concessionnaire agréé de la marque** qui interroge le catalogue d'origine (modèle, année, moteur ou VIN) | boutique pièces d'un concessionnaire Toyota ou Mazda agréé |
| `human_verified` | contrôle physique ou recoupement fait par un membre du personnel, avec URL du support (photo, document) | pièce déposée comparée, étiquette d'origine |
| `aftermarket_crossref` (**insuffisant**) | catalogues d'équipementiers et de revendeurs, tableaux de correspondance, fiches marketplace | CUSCO, HKS, Bosch, documents fournisseurs |

À noter :
- Un revendeur de pièces d'origine n'est `dealer_catalog` que s'il est un concessionnaire agréé de la marque. « MazdaPartsNow / Mazda USA » est cité sans URL dans 7 lignes. Il faut ouvrir la page de la pièce et vérifier qui l'édite avant de choisir le niveau.
- Le « catalogue de correspondance du 18/10/2026 » est un document fournisseur, cité sans URL dans 74 lignes. Il ne confirme rien seul. À la rigueur, une fois archivé avec une URL, il vaut `aftermarket_crossref`.
- Le moteur, le châssis, le marché et le mois de début ou de fin s'enregistrent dans les colonnes de restriction de #42, pas dans les notes.

## Procédure, ligne par ligne

1. Prendre la ligne suivante de l'inventaire, dans l'ordre (P1 d'abord).
2. Chercher la source au meilleur niveau possible : EPC ou document constructeur d'abord, concessionnaire agréé ensuite, contrôle humain à défaut. Vérifier que la page nomme **ce numéro** pour **ce modèle, ces années et ce moteur**.
3. Selon le résultat :
   - **Source trouvée** : noter l'URL exacte, le niveau, la date de consultation et ce que dit la page (une phrase). Si la page restreint l'application (moteur, châssis, marché, mois), noter la restriction.
   - **La source contredit la ligne** : proposer la correction (années, moteur) ou le retrait, avec l'URL.
   - **Rien trouvé** : laisser la ligne « candidat ». Ne pas poser de niveau.
4. Pour `human_verified` : qui a validé (compte du personnel), quand, et l'URL du support. Le compte n'est jamais déduit : l'API le prend de l'utilisateur connecté.
5. Faire relire le lot par une seconde personne avant application.

### Application des niveaux

Il y a deux voies. Aucune ne fait d'écriture directe en production.

- **Migration de lot, recommandée.** Elle suit le modèle de `2026_11_26_230000_retro_remplir_les_preuves_depuis_les_notes.php` (branche ci-dessus) :
  - elle contient une liste explicite de lignes, visées par SKU, slug du modèle, moteur et années ;
  - elle n'écrit que si la ligne n'a encore aucune preuve et que l'URL est bien celle relue ;
  - son `down()` retire exactement ce qu'elle a posé ;
  - elle passe en PR, avec un test et la répétition sur copie (`scripts/verification-deploiement/`).
- **API `PUT /api/parts/{id}`** avec `fitments[*].evidence_level`, `evidence_sources[]` et `verified_on` (`verified_by_id` est interdit en entrée, il est pris du compte connecté). **Attention :** ce point d'accès remplace toutes les compatibilités de la pièce. Elles sont supprimées puis recréées et changent d'identifiant. Il faut donc renvoyer toutes les lignes de la pièce. Cette voie convient à une pièce isolée, pas à un lot.

Après chaque lot :
- relancer `instantane-verdicts.php` et `compare-verdicts.php` (voir `docs/deploiement-compatibilites-pr42-a-44.md`) ;
- seuls des passages « candidat → confirmé » sur les lignes du lot sont attendus.

## Régénérer l'inventaire

```bash
# 1. Exports lecture seule (query_render_postgres), enregistrés en JSON
#    scripts/verification-deploiement/export-catalogue.sql → catalogue.json
#    scripts/verification-deploiement/export-vehicules.sql → vehicules.json
# 2. Inventaire
python3 docs/qualification-preuves/generer-inventaire.py inventaire.csv catalogue.json vehicules.json
```

Les deux SQL d'export sont sur la branche `docs/procedure-deploiement-compatibilites`.
