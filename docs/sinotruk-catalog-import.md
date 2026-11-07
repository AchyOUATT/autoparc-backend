# Import ciblé Sinotruk

Préparé sur branche uniquement : aucune écriture de production, fusion ou demande de déploiement.

- Migration `2026_11_07_000001_add_sinotruk_catalog` : import transactionnel via
  `SinotrukCatalogSeeder`. Les exceptions remontent : pas de succès partiel silencieux.
- Le même seeder figure dans `DatabaseSeeder` pour les installations neuves.
  Ne pas exécuter le peuplement global sur une production pour cet ajout.
- Pays CN résolu par code (créé si absent), marque Sinotruk, préfixe OEM ZZ.
- Douze gammes commerciales HOWO/SITRAK/HOHAN, noms complets et slugs préfixés.
  Ce ne sont pas des générations : `generation = null`.
- Nouveaux modèles : `default_vehicle_type = heavy`, `body_type = camion`,
  `segment = null`, `production_start = production_end = null`.
  Les dates sont inconnues/non vérifiées : null ne signifie pas « encore produit ».
- Aucun moteur, filtre ou rattachement de compatibilité ajouté.
- Import strictement additif : aucune mise à jour ni réactivation des fiches existantes,
  y compris si leurs attributs diffèrent. Les collisions nom/slug entre deux marques
  Sinotruk provoquent un échec explicite, sans écriture partielle.
- Retour arrière volontairement non destructif : `down()` conserve les données,
  car ni la provenance ni les références client ne peuvent être déduites du slug.
  Toute suppression éventuelle doit faire l'objet d'une opération revue séparément.

Tests ciblés (SQLite en mémoire uniquement) :
`php artisan test --filter=SinotrukCatalogTest`

La migration sera exécutée seulement lors d'un futur déploiement autorisé contenant
ce commit. Cette PR ne demande ni fusion ni déploiement.
