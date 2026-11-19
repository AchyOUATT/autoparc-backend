# Import vérifié du 90915-10003

Date de revue : 2026-11-19.

Le 90915-10003 n’existait ni comme article ni comme numéro OEM. Il équipe, sur le marché Japon, des châssis lancés à partir de 2000 avec des moteurs que le 90915-10001 équipe déjà sur des châssis plus anciens. La règle n’est pas « par moteur » : Toyota a changé de référence par châssis.

## Sources

- Catalogue CUSCO, [oilfilter_toyota.pdf](https://www.cusco.co.jp/products/pdf/oilfilter_toyota.pdf), consulté le 2026-11-19. C’est la source de chaque ligne retenue.
- Tableau HKS, [OIL FILTER APPLICATION CHART](https://www.hks-power.co.jp/en/product_search/product/935/media/print), consulté le même jour, uniquement pour recouper une ligne CUSCO déjà retenue. Quand CUSCO laisse la période ouverte, la date de fin HKS du même châssis et du même moteur est retenue, et la note le dit.

Aucune application n’est inventée. Aucun modèle absent du catalogue n’est créé.

## Article créé

- SKU et numéro OEM primaire : `90915-10003`
- Catégorie : Filtre à huile (`filtre-huile`)
- Fabricant : celui du 90915-10001 s’il existe, sinon « Chine »
- Type : aftermarket, état neuf
- `is_active = false`, `selling_price = 0`, `stock_quantity = 0` — même convention que les filtres industriels : rien n’est promis au client tant que le commerçant n’a pas posé le prix et le stock, puis activé la fiche.

## Lignes ajoutées (modèles déjà au catalogue)

| Modèle catalogue | Moteur | Années | Châssis | Source |
| --- | --- | --- | --- | --- |
| Corolla E120 | 1ZZ-FE | 2000–2006 | ZZE122/ZZE124 | CUSCO p. 4 (08/2000–10/2006) ; HKS ZZE122 08/2000–09/2006, ZZE124 12/2003–09/2006 |
| Corolla E120 | 1NZ-FE | 2000–2006 | NZE121/NZE124 | CUSCO p. 4 ; HKS 08/2000–09/2006 |
| Corolla E120 | 2NZ-FE | 2000–2006 | NZE120 | CUSCO p. 4 imprime « 2NZFE », normalisé en 2NZ-FE ; HKS 08/2000–09/2006 |
| Corolla E140 | 1NZ-FE | 2006–2012 | NZE141/NZE144 | CUSCO p. 4 COROLLA AXIO 10/2006– (période ouverte) ; fin 04/2012 d’après HKS |
| Yaris XP90 | 1NZ-FE | 2005–2010 | NCP91 | CUSCO p. 2 VITZ 02/2005– (période ouverte) ; fin 12/2010 d’après HKS |
| Yaris XP90 | 2NZ-FE | 2005–2010 | NCP95 | CUSCO p. 2 ; HKS 02/2005–12/2010 |
| Yaris XP90 | 2SZ-FE | 2005–2010 | SCP90 | CUSCO p. 2 ; HKS 02/2005–12/2010 |

CUSCO liste aussi, pour les mêmes moteurs de Corolla E120, les variantes Allex, Runx, Spacio, Fielder et Sprinter. Elles ne sont pas des modèles séparés du catalogue ; elles sont mentionnées dans la note de la ligne E120, pas ajoutées.

La ligne Corolla E140 1NZ-FE ne touche pas à la ligne 90915-YZZN1 déjà en base sur la Corolla E140 1ZZ-FE.

## Codes châssis du 90915-10001 déjà en base

Les cinq lignes existantes reçoivent le code châssis déjà porté par le manifeste CUSCO du 9 novembre 2026, uniquement si la colonne est encore vide :

- Corolla E100 4A-FE 1991–1995 : AE101/AE104
- Corolla E110 4A-FE 1995–2000 : AE111/AE114
- Camry XV10 5S-FE 1992–1996 : SXV10/SXV15
- Camry XV20 5S-FE 1996–2001 : SXV20/SXV20W
- RAV4 XA10 3S-FE 1994–2000 : SXA10/SXA11

## Modèles CUSCO volontairement non ajoutés

### Pour le 90915-10003

Présents dans CUSCO, absents du catalogue (ou trop différents d’un modèle déjà nommé) :

- bB (NCP30, NCP31, NCP34, NCP35)
- RAV4 2e génération XA20 (ZCA25W, ZCA26W) — le catalogue a XA10, XA40 et XA50, pas XA20
- Isis, Allion, Premio, ist, Wish, Will VS, Opa, Caldina, Succeed, Sienta, Belta, Porte, Raum, Ractis, Probox, Voxy
- Vitz XP10 (NCP13, SCP13) — première génération, distincte de la Yaris XP90
- Auris E150 (NZE151H, NZE154H) — le catalogue n’a que l’Auris E180 (2012–2019)
- Prius NHW11 / NHW20 (XW10 / XW20) — le catalogue a les XW30 et XW50
- Corolla Rumion (NZE151)

### Pour le 90915-10001 (châssis 1ZZ-FE / 1NZ-FE et voisins)

CUSCO les donne au 10001, ils ne sont pas au catalogue, rien n’a été créé :

- Vista ZZV50 (1ZZ-FE)
- MR-S ZZW30 (1ZZ-FE, jusqu’à 08/2000)
- Celica ZZT230 (1ZZ-FE)
- Vitz NCP10 / NCP15 (2NZ-FE)
- Platz NCP12 (1NZ-FE)
- Funcargo NCP21 / NCP25 (1NZ-FE)

## Décisions laissées ouvertes

1. HKS liste aussi la Vitz NCP131 (Yaris XP130, 1NZ-FE, 12/2010–01/2020) et la NHP130 (hybride) sous 90915-10003. Le PDF CUSCO consulté ne les porte pas. Le 90915-YZZN1 a déjà une ligne Yaris XP130 1NZ-FE 2011–2019. Aucune ligne 10003 n’a été ajoutée sur l’XP130, pour ne pas créer de conflit non tranché et pour ne pas toucher aux lignes YZZN1.
2. Les dates de fin 2010 (XP90) et 2012 (Axio E140) viennent de HKS, pas de CUSCO. Si l’on préfère laisser `year_to` vide comme CUSCO, on peut les rouvrir.
3. La fiche reste inactive, sans prix ni photo, jusqu’à ce que le commerçant la publie.
