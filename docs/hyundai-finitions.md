# Documenter les finitions Hyundai

Le fichier [`database/catalogue/hyundai-candidates.json`](../database/catalogue/hyundai-candidates.json) est un **backlog de recherche, pas un lot importable**. Il reprend les noms du fichier utilisateur `hyundai_trims_complets.php`, identifié par son empreinte SHA-256, sans exécuter ce fichier et sans lui attribuer une valeur de preuve constructeur.

Le décompte corrigé est de **33 noms proposés sur dix entrées modèle/génération**, auxquels s'ajoutent **sept finitions historiques à auditer** : Tucson TL (Active, Active X, Elite, Highlander) et Santa Fe TM (Essential, Comfort, Premium). Les douze entrées Hyundai du seeder sont donc suivies. Ce constat concerne le code du référentiel, pas l'état d'une base de production. Aucune de ces lignes n'est déclarée vérifiée par le backlog.

## Vérifier le périmètre avant les ajouts

Pour chaque appellation, relever une brochure ou un catalogue constructeur, le marché, les millésimes couverts, l'URL HTTPS, les pages utiles et la date réelle de consultation. Une brochure d'un millésime ne valide pas toute la génération. La date de vérification ne doit être ajoutée qu'après lecture des preuves.

Deux points de classement demandent une attention particulière :

- Creta n'a aucune génération discriminée dans le référentiel actuel : identifier la génération avant de lui attribuer une gamme commerciale documentée.
- Les bornes de production i10 BA, i20 IB et Accent RB sont ouvertes. Elles ne prouvent pas que les finitions proposées sont commercialisées jusqu'à aujourd'hui.

Le statut de chaque entrée reste `proposed_pending_review` ou `existing_pending_review`. Les marchés et millésimes vérifiés sont absents, les listes de sources sont vides et aucun `verified_on` n'est inventé. Les dates `catalogue_production_start` et `catalogue_production_end` décrivent uniquement les bornes déjà présentes dans `VehicleModelsSeeder`.

## Préserver l'identité technique

Les valeurs dites « typiques » de la proposition ne sont pas reprises comme caractéristiques validées. Un nom tel que Limited ou N Line ne suffit pas à déterminer un carburant, un moteur, une boîte ou des roues motrices. Les nouveaux trims doivent conserver leurs défauts moteur et motricité à `null` tant qu'une preuve n'en justifie pas une valeur unique.

La boucle historique de `TrimsSeeder` ne stocke pas puissance, cylindrée, nombre de cylindres et boîte. Elle enregistre en revanche les défauts de carburant et de motricité : copier les lignes proposées leur donnerait un effet réel sur le contexte de consommation et certains contrôles de véhicules. La recherche actuelle de pièces utilise les valeurs explicites, sans déduire ces défauts depuis la finition.

Les anciennes finitions TL/TM restent à auditer ; leur présence ne les rend pas exactes. Une correction de leurs valeurs existantes doit suivre le mécanisme contrôlé `catalog:correct-trims`, avec les preuves et la simulation correspondantes.

## Préparer puis simuler le lot vérifié

Créer un **autre fichier** au format décrit dans [`catalogue-finitions.md`](catalogue-finitions.md), avec `version: 1`, des entrées par marché et millésime, les noms attestés et leurs sources effectivement consultées. Le backlog ne peut pas être converti en lot vérifié par un simple renommage de clés.

```bash
php artisan catalog:import-trims /chemin/lot-hyundai-verifie.json --dry-run
```

Examiner les créations et conflits avant l'import réel sur la base choisie. L'import conserve les caractéristiques inconnues à `null` et rattache les marchés, millésimes et preuves à `trim_applications`. La commande valide la structure et la cohérence ; elle ne consulte pas les sources et ne prouve pas leur authenticité.

Ne pas ajouter les candidats non vérifiés à `TrimsSeeder` : ce seeder est rejoué au démarrage de Render. La préparation de ce backlog n'importe rien, ne modifie aucune finition et n'annonce aucune gamme Hyundai exhaustive.

## État de la préparation au 3 novembre 2026

L'accès à `https://www.hyundainews.com/` a été refusé par le proxy de l'environnement (`CONNECT 403`). Aucun document constructeur Hyundai disponible localement n'a été trouvé. Le nombre d'applications documentées reste donc **zéro**.

Le brouillon de configuration réseau ajoute les sites constructeur et leurs hôtes documentaires : `hyundainews.com`, `www.hyundainews.com`, `hyundaiusa.com`, `www.hyundaiusa.com`, `hyundaicanada.com`, `www.hyundaicanada.com`, `hyundai.com`, `www.hyundai.com`, `dmassets.hyundai.com` et `s7d1.scene7.com`. Les destinations existantes sont conservées. Ces adresses sont des points de recherche, pas des preuves consultées. La sauvegarde du brouillon n'ouvre pas à elle seule l'accès dans l'instance courante.

Après activation de l'accès, commencer par vérifier les appellations sur des brochures datées, puis réviser les candidats selon les noms effectivement attestés. Les règles d'import ne doivent pas être assouplies pour contourner l'absence de preuves.

L'application mobile actuelle ne filtre pas encore les finitions selon `trim_applications`. Un lot limité à un marché ou à un millésime devra conserver son périmètre clairement identifié ; l'import documentaire seul ne constitue pas un filtrage de la liste dans le mobile.
