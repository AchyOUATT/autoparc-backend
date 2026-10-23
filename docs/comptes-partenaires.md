# Comptes partenaires et annonces

Les entreprises peuvent demander un compte pour proposer des véhicules à
la vente ou à la location, des pièces détachées et des accessoires. Chaque
compte gère ses annonces, ses prix, ses photos et ses disponibilités.

Ce module backend est préparé sur la branche
`codex/catalogue-finitions-rapport-2026-10-21`. Son ajout ne constitue pas un
déploiement ni une modification de l'application Flutter.

## Inscription et validation

1. Le partenaire s'inscrit avec les coordonnées de son entreprise.
2. Son compte est créé en `pending`. Il peut consulter son statut, mais pas
   gérer ou publier des annonces.
3. Un administrateur ou un responsable ayant `manage-partners` examine la
   demande, puis l'approuve ou la refuse avec un motif.
4. Après approbation, le partenaire crée ses annonces en brouillon et choisit
   celles qu'il publie.
5. Une suspension retire ses annonces du catalogue public, bloque la gestion
   de l'inventaire et révoque ses jetons de connexion.

L'annuaire existant `/api/partners` conserve son rôle de fournisseurs et
contacts. L'association à un fournisseur n'accorde aucun droit d'accès.
Les comptes vendeurs sont stockés séparément dans `partner_accounts`.

### Routes du partenaire

| Méthode et route | Usage |
|---|---|
| `POST /api/partner/register` | Demander un compte |
| `POST /api/partner/login` | Se connecter avec email et mot de passe |
| `GET /api/partner/me` | Consulter son profil et l'état de validation |
| `POST /api/partner/logout` | Fermer la session courante |

Exemple d'inscription, avec des coordonnées fictives :

```json
{
  "company_name": "Garage Exemple",
  "contact_name": "Responsable du garage",
  "email": "partenaire@garage.example",
  "phone": "+22670000000",
  "whatsapp": "+22670000000",
  "password": "VotreMotDePasse2026",
  "password_confirmation": "VotreMotDePasse2026",
  "device_name": "application-partenaire"
}
```

Le mot de passe doit contenir au moins 12 caractères, des lettres et des
chiffres. La réponse contient `user`, `partner_account` et un `token`.
Le statut est dans `partner_account.status`. Le jeton s'envoie ensuite dans
`Authorization: Bearer ...`. Un jeton créé avant approbation ne contourne
pas l'attente : le statut est vérifié à chaque appel.

Un compte refusé ou suspendu ne reçoit pas de nouveau jeton de connexion.
La connexion du personnel `/api/login` reste distincte.

### Routes de validation

Ces routes exigent une connexion du personnel et `manage-partners` :

| Méthode et route | Usage |
|---|---|
| `GET /api/partner-applications?status=pending` | Examiner la file des demandes |
| `GET /api/partner-applications/{id}` | Voir une demande |
| `POST /api/partner-applications/{id}/approve` | Approuver ou réactiver |
| `POST /api/partner-applications/{id}/reject` | Refuser |
| `POST /api/partner-applications/{id}/suspend` | Suspendre |

Pour un refus ou une suspension, fournir un motif :

```json
{
  "review_reason": "Coordonnées de l'entreprise à vérifier."
}
```

L'identité du responsable de la décision et sa date sont conservées.
Aucun email ou SMS n'est envoyé automatiquement par ce module.

## Gestion des annonces

Après approbation, les trois familles disposent des mêmes opérations :

| Famille | Base des routes |
|---|---|
| Véhicules en vente ou location | `/api/partner/vehicles` |
| Pièces détachées | `/api/partner/parts` |
| Accessoires | `/api/partner/accessories` |

Pour chacune : `GET` liste, `POST` crée, `GET /{id}` consulte,
`PATCH /{id}` ou `PUT /{id}` modifie, `DELETE /{id}` archive.
`POST /{id}/publish` publie et `POST /{id}/unpublish` retire du catalogue.

Les champs métier sont ceux des formulaires existants du parc. Les véhicules
acceptent `availability: sale`, `rent` ou `both`. Pour la location, il suffit
d'un `rental_daily_rate` ou d'un `rental_monthly_rate` ; les deux peuvent
coexister. Voir [les tarifs de location](tarifs-location-vehicules.md).

La propriété est imposée par le serveur. Le partenaire ne peut pas envoyer
un autre propriétaire, un créateur, une date de publication ou un emplacement
interne. Un véhicule donneur d'une pièce doit appartenir au même partenaire.
Les champs obligatoires des formulaires du parc restent requis en édition.

### Photos, stocks et disponibilité

- `GET {base}/{id}/media` : liste des photos.
- `POST {base}/{id}/media` : ajout de photos en multipart dans `photos[]`
  (JPEG, PNG ou WebP, au plus 10 par envoi). Les documents privés ne sont pas
  acceptés dans cet espace.
- `PATCH {base}/{id}/media/reorder` : ordre des photos avec `ids: [1, 2]`.
- `PATCH /api/partner/media/{id}/cover` : photo de couverture.
- `DELETE /api/partner/media/{id}` : suppression d'une photo.
- `POST /api/partner/parts/{id}/stock` et l'équivalent pour `accessories` :
  ajustement du stock selon `operation`, `quantity` et `reason`.
- `PATCH /api/partner/parts/{id}/availability` et l'équivalent pour
  `accessories` : disponibilité commerciale.

Dans les trois premières lignes, `{base}` désigne la base complète
`/api/partner/vehicles`, `/api/partner/parts` ou `/api/partner/accessories`.

Les photos sont destinées à une galerie publique. Retirer une annonce masque
ses liens dans le catalogue et les pages partagées ; cela ne révoque pas une
adresse d'image déjà transmise ou conservée dans un cache externe.

## Visibilité et confidentialité

Une annonce partenaire est publique seulement si elle est publiée et si son
compte reste approuvé et actif. La règle s'applique au catalogue, aux résultats
de compatibilité et aux pages partagées. Le stock historique du personnel
conserve sa visibilité existante.

Les réponses publiques présentent les coordonnées commerciales du vendeur.
Les pages partagées utilisent son téléphone et son WhatsApp. Les informations
de gestion du partenaire restent privées : documents, VIN et immatriculation,
coûts et formalités d'importation, emplacement et quantité exacte du stock,
fournisseurs et motif de validation. Le propriétaire et le personnel conservent
les données utiles à la gestion.

Dans son espace de gestion, un partenaire ne peut pas consulter ou modifier
les brouillons, stocks et photos d'un autre partenaire ou du stock interne.
Son rôle ne donne aucun
accès aux routes du personnel, aux clients, à la comptabilité ou aux commandes
globales. Le canal d'authentification client Firebase ne peut pas récupérer
un compte partenaire par rapprochement d'adresse email.

## Parcours commercial et mise en service

Le parcours livré est celui d'annonces avec prise de contact du vendeur.
Il ne crée pas de paiement en ligne, de commission, de commande multivendeur
ou de gestion des contrats dans l'espace partenaire. Le forfait mensuel
reste un tarif d'annonce ; sa facturation automatique n'est pas incluse.

La mise en service demande la migration de base, le déploiement du backend
et les écrans Flutter : inscription, attente de validation, validation par le
personnel et gestion de l'inventaire partenaire. Aucun compte de production
ni identifiant partagé de démonstration n'est créé automatiquement.

## Vérifications du backend

- Suite complète : 764 tests réussis, 4 927 assertions et les deux échecs
  préexistants des proportions d'aperçus dans `PagesProduitTest`.
- 90 tests ciblés réussis, 809 assertions : isolation des annonces, photos et
  stocks, brouillons et suspensions, confidentialité, classification et droits.
- Les migrations ont été vérifiées sur SQLite, y compris une base historique.
  Les branches PostgreSQL et MySQL n'ont pas été exécutées sur ces moteurs.
- Les vérifications Flutter et les captures ont leur propre prérequis SDK ;
  ces résultats PHP ne constituent pas une validation de l'application mobile.
