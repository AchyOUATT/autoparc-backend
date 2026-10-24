<?php

namespace App\Http\Controllers\Web;

use App\Enums\PartCondition;
use App\Enums\Transmission;
use App\Enums\VehicleCondition;
use App\Enums\VehicleStatus;
use App\Http\Controllers\Controller;
use App\Models\Accessory;
use App\Models\Media;
use App\Models\Part;
use App\Models\Vehicle;
use App\Support\ArticlePartage;
use App\Support\PartnerListingPresentation;
use App\Support\PartnerMedia;
use App\Support\PhotoPartage;
use App\Support\VersionPartage;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

/**
 * Les pages publiques d'un article, faites pour etre collees dans une
 * conversation.
 *
 * L'application sait partager un article, mais il n'existait aucune page par
 * article : le message ne pouvait porter que le lien du magasin
 * d'applications, et le destinataire devait installer l'application puis
 * retrouver l'article de lui-meme. Sur une place de marche, le partage est le
 * canal principal.
 *
 * Tout ce qui compte ici est rendu PAR LE SERVEUR. Le robot de WhatsApp ou de
 * Facebook qui va chercher la page pour en fabriquer la vignette n'execute pas
 * de JavaScript : ce qu'il ne trouve pas dans le HTML n'existe pas pour
 * l'apercu.
 */
class ArticleController extends Controller
{
    /**
     * Duree de mise en cache de ces pages.
     *
     * Le robot demande la page, puis l'humain qui a recu le lien la demande a
     * son tour, souvent dans les secondes qui suivent — et l'instance gratuite
     * de Render met plusieurs secondes a se reveiller quand elle dort. Cinq
     * minutes absorbent cette rafale.
     *
     * Le compromis se joue dans l'autre sens : un prix corrige en boutique
     * resterait faux dans les apercus aussi longtemps que l'en-tete le dit, et
     * un apercu faux circule — il est recopie dans d'autres conversations. Une
     * heure serait indefendable pour cette raison ; quelques minutes couvrent
     * la rafale sans survivre a une correction.
     */
    private const CACHE_SECONDES = 300;

    /**
     * Les statuts qui RETIRENT la page d'un vehicule.
     *
     * `published_at` etait le seul retrait regarde ici, mais le vrai geste de
     * sortie du parc est la vente : SaleController fait
     * update(['status' => VehicleStatus::Sold]) et ne touche jamais
     * published_at. Mesure, un vehicule publie, par statut : in_stock 200,
     * reserved 200, sold 200, rented 200, maintenance 200, in_transit 200,
     * archived 200 — et le HTML du vendu portait
     * « og:title content="Toyota Corolla 2015 — 4 500 000 FCFA" ».
     *
     * Concretement : la voiture part lundi, le lien continue de circuler, et
     * jeudi un client ecrit pour une Corolla a 4 500 000 FCFA qui n'est plus la.
     *
     * Une liste de ce qui FERME la page, et non de ce qui l'ouvre : un statut
     * ajoute demain a l'enum doit garder sa page par defaut. Fermer par defaut
     * ferait disparaitre sans bruit des liens deja partages, ce qui est le
     * defaut qu'on corrige, a l'envers.
     *
     * Vendu et archive seulement. Les quatre autres restent ouverts a dessein :
     *   — `reserved` : une reservation tombe, et le lien deja envoye est par ou
     *     le client suivant arrive ;
     *   — `rented`, `maintenance`, `in_transit` : le vehicule est au parc, il
     *     revient ou il arrive, et la page sert a se manifester pour apres.
     * Aucun de ces quatre ne fait ecrire un client pour un vehicule qui n'est
     * plus la — c'est le seul tort qu'on repare ici.
     */
    private const STATUTS_RETIRES = [
        VehicleStatus::Sold,
        VehicleStatus::Archived,
    ];

    /**
     * LA REGLE, UNE SEULE : une page de partage existe SI ET SEULEMENT SI le
     * catalogue de l'application montre l'article.
     *
     * C'est elle qui garantit que « Ouvrir dans AutoParc », le dernier bouton
     * de la page, ne soit jamais une impasse. Le visiteur arrive ici parce
     * qu'il n'a PAS l'application ; l'envoyer l'installer pour y chercher un
     * article que ses ecrans ne listent pas est le pire resultat possible — il
     * a fait l'effort, et il ne trouve rien.
     *
     * Elle etait vraie mais ecrite trois fois, en trois `abort_if` independants
     * dont rien ne disait qu'ils etaient la meme phrase. Un controle
     * adversarial a d'ailleurs signale l'asymetrie comme un defaut : un
     * accessoire indisponible rend 404, une piece indisponible rend 200. Ce
     * n'en est pas un, et il ne faut surtout pas « corriger par symetrie » :
     * l'asymetrie est HERITEE DES CATALOGUES, et la regle la reproduit
     * fidelement.
     *
     *   — Vehicule : SEUL le statut retire la page. Voir STATUTS_RETIRES.
     *
     *     `published_at` a d'abord servi de retrait ici, et c'etait une
     *     ERREUR MESUREE EN PRODUCTION : personne ne pose jamais ce champ.
     *     L'action existe cote serveur — POST /staff/vehicles/{id}/publish —
     *     mais AUCUN ecran de l'application ne l'appelle (verifie : grep
     *     publish sur lib/ ne rend que des commentaires). Tout vehicule
     *     enregistre depuis l'application a donc published_at a null.
     *
     *     Consequence constatee sur la production : la seule annonce du parc,
     *     une Mazda CX-9 in_stock et a vendre, rendait 404 sur /v/86 et
     *     voyait son bouton de partage masque. La fonction entiere etait
     *     morte, pour tous les vehicules, des le premier jour.
     *
     *     La regle de ce fichier — une page existe si et seulement si le
     *     catalogue montre l'article — commande donc d'ignorer ce champ :
     *     /api/catalog/vehicles ne le filtre pas, le catalogue montre ces
     *     vehicules, la page doit exister. Aligner le catalogue sur
     *     published_at serait l'autre reparation coherente, mais elle
     *     retirerait du catalogue tout le parc existant tant que personne
     *     n'aurait de quoi publier : c'est un choix de produit, pas un
     *     correctif.
     *   — Piece : PartController::index filtre active() par defaut, mais
     *     `is_available` n'y est qu'un filtre OPTIONNEL (`in_stock`). Le
     *     catalogue des pieces MONTRE les ruptures de stock, donc la page
     *     existe.
     *   — Accessoire : AccessoryController::catalog filtre active() ET
     *     where('is_available', true), et la route des categories applique
     *     explicitement les deux. Ce catalogue-la MASQUE les ruptures, donc la
     *     page disparait avec elles.
     *
     * Une piece servie en rupture doit alors le DIRE sur sa page : voir
     * piece(). Servir sans prevenir ferait ecrire un client pour rien, ce qui
     * est le meme tort que l'impasse, par l'autre bout.
     */
    private function catalogueMontre(Vehicle|Part|Accessory $article): bool
    {
        if (PartnerListingPresentation::isPartnerListing($article)
            && ! $article->newQuery()->visibleToPublic()->whereKey($article->id)->exists()) {
            return false;
        }

        return match (true) {
            $article instanceof Vehicle => ! in_array(
                $article->status,
                self::STATUTS_RETIRES,
                true,
            ),

            $article instanceof Part => (bool) $article->is_active,

            $article instanceof Accessory => $article->is_active && $article->is_available,
        };
    }

    /** GET /v/{vehicle} */
    public function vehicule(Vehicle $vehicle): Response
    {
        abort_unless($this->catalogueMontre($vehicle), 404);

        $vehicle->load([
            // 'media' en premier, et jamais implicite : ce depot a deja livre
            // trois fois une ressource dont la relation n'etait pas chargee. La
            // collection vide est alors indistinguable d'un article sans photo
            // — aucune erreur, aucune trace, et ici c'est og:image qui
            // disparaitrait de l'apercu.
            'media',
            'brand', 'vehicleModel', 'trim', 'engineType', 'color', 'location',
            // Le kilometrage ne vit pas sur le vehicule mais dans l'un de ces
            // deux blocs, selon qu'il est immatricule ou importe (voir
            // Vehicle::getMileageKmAttribute).
            'registrationDetail', 'importDetail',
        ]);

        return $this->page(chemin: '/v/'.$vehicle->id, source: $vehicle, article: new ArticlePartage(
            // Espaces resserres : Vehicle::getDesignationAttribute compose
            // « marque modele finition annee » par sprintf et ne retire que les
            // espaces de bord. Sans finition — la moitie du parc importe —, il
            // reste un espace double au milieu. Le HTML l'avale a l'affichage,
            // mais og:title non : c'est la ligne en gras de la conversation.
            titre:       preg_replace('/\s+/', ' ', $vehicle->designation),
            prix:        $vehicle->availability?->allowsSale() && $vehicle->sale_price !== null
                ? (float) $vehicle->sale_price
                : null,
            description: $this->extrait($vehicle->description),
            photo:       $this->couverture($vehicle),
            // Les tarifs, et seulement quand la location est ouverte :
            // un vehicule « sale » peut porter un tarif residuel en base, et
            // l'annoncer proposerait une location que le parc refuse.
            tarifLocation: $vehicle->availability?->allowsRent() && $vehicle->rental_daily_rate !== null
                ? (float) $vehicle->rental_daily_rate
                : null,
            tarifLocationMensuel: $vehicle->availability?->allowsRent() && $vehicle->rental_monthly_rate !== null
                ? (float) $vehicle->rental_monthly_rate
                : null,
            precisions:  array_filter([
                'Année'        => $vehicle->manufacturing_year ? (string) $vehicle->manufacturing_year : null,
                'État'         => $this->etatVehicule($vehicle->condition),
                'Kilométrage'  => $vehicle->mileage_km !== null
                    ? number_format((int) $vehicle->mileage_km, 0, ',', "\u{A0}")."\u{A0}km"
                    : null,
                'Boîte'        => $this->boite($vehicle->transmission),
                'Carburant'    => $vehicle->engineType?->label,
                'Couleur'      => $vehicle->color?->name,
                'Ville'        => $vehicle->location?->city,
            ]),
        ));
    }

    /** GET /p/{part} */
    public function piece(Part $part): Response
    {
        abort_unless($this->catalogueMontre($part), 404);

        $part->load(['media', 'category', 'manufacturer', 'location']);

        return $this->page(chemin: '/p/'.$part->id, source: $part, article: new ArticlePartage(
            titre:       $part->name,
            prix:        $part->selling_price !== null ? (float) $part->selling_price : null,
            description: $this->extrait($part->description),
            photo:       $this->couverture($part),
            // La piece est le SEUL des trois types que la regle sert en
            // rupture, parce que son catalogue la montre (voir
            // catalogueMontre). Servie, elle doit le dire : un apercu qui
            // annonce « Filtre a huile — 3 000 FCFA » sur un article en rupture
            // fait ecrire un client, attendre une reponse, et repartir. Le
            // libelle remplace le prix dans og:title ; le prix reste affiche
            // dans le corps, car c'est l'information qui decide d'attendre le
            // reapprovisionnement ou non.
            //
            // `is_available` et non `stock_quantity` : c'est le drapeau que le
            // catalogue filtre, et c'est lui que toggleAvailability pose. Un
            // nombre d'unites ne sort pas d'ici — c'est une information de
            // gestion, surveillee par le test de fuite.
            indisponibilite: $part->is_available ? null : 'Rupture de stock',
            precisions:  array_filter([
                'Catégorie'  => $part->category?->name,
                'Fabricant'  => $part->manufacturer?->name,
                'Référence'  => $part->manufacturer_reference,
                'État'       => $this->etatPiece($part->condition),
                'Garantie'   => $part->warranty_months ? $part->warranty_months.' mois' : null,
                'Ville'      => $part->location?->city,
            ]),
        ));
    }

    /** GET /a/{accessory} */
    public function accessoire(Accessory $accessory): Response
    {
        // Mesure du defaut d'origine : accessoire is_available=false,
        // stock_quantity=0 -> 200, alors que le catalogue des accessoires les
        // masque. Le bouton « Ouvrir dans AutoParc » conduisait a une
        // application ou l'article est introuvable.
        abort_unless($this->catalogueMontre($accessory), 404);

        $accessory->load(['media', 'manufacturer', 'location']);

        return $this->page(chemin: '/a/'.$accessory->id, source: $accessory, article: new ArticlePartage(
            titre:       $accessory->name,
            prix:        $accessory->selling_price !== null ? (float) $accessory->selling_price : null,
            description: $this->extrait($accessory->description),
            photo:       $this->couverture($accessory),
            precisions:  array_filter([
                'Catégorie' => $accessory->category?->label(),
                'Fabricant' => $accessory->manufacturer?->name,
                'Garantie'  => $accessory->warranty_months ? $accessory->warranty_months.' mois' : null,
                'Ville'     => $accessory->location?->city,
            ]),
        ));
    }

    /**
     * Rend la page et l'accompagne de son en-tete de cache.
     *
     * Les trois actions — WhatsApp, appel, ouverture de l'application — sont
     * calculees ici et non dans la vue : le message pre-rempli nomme l'article
     * et porte l'adresse de la page, deux valeurs que la vue n'aurait pas a
     * recomposer.
     *
     * DEUX ADRESSES SORTENT D'ICI, ET LA DIFFERENCE EST TOUT LE SUJET.
     *
     * L'article est passe en plus de son chemin pour une seule raison : le
     * marqueur doit etre calcule A LA VOLEE, ici, au moment du rendu. Le faire
     * aux trois points d'appel aurait ete trois occasions de l'oublier, et une
     * page muette ne se voit pas en relisant le code.
     *
     *   — `$url`, NUE, pour <link rel="canonical"> : un article est UNE page
     *     pour un moteur de recherche, pas une par modification. Sans cela, un
     *     vehicule retouche dix fois presenterait dix adresses indexables du
     *     meme contenu, ce qui est du contenu duplique et se paie au
     *     classement.
     *
     *   — `$urlPartage`, MARQUEE, pour og:url et pour le lien WhatsApp. Elle
     *     porte TOUJOURS le marqueur COURANT, jamais celui que l'appelant a
     *     envoye dans `m` : un vieux lien visite apres une modification annonce
     *     donc le marqueur NEUF. C'est voulu, et c'est le mecanisme meme du
     *     correctif — Facebook identifie un objet par son og:url, il relit
     *     celui-la, et re-cle l'objet au lieu de figer l'ancien.
     *
     * Effet de bord utile, et il est reel : la cle primaire d'un cache HTTP est
     * l'URI COMPLETE, chaine de requete comprise. Une adresse marquee a neuf ne
     * peut donc pas etre servie depuis l'entree que CACHE_SECONDES a laissee
     * pour l'ancienne, chez Cloudflare comme chez un mandataire d'operateur. Ce
     * n'est pas la raison du marqueur, mais cela repare sur le chemin le
     * compromis assume plus haut.
     *
     * Le parametre `m` n'est lu NULLE PART dans cette classe, et c'est
     * deliberement qu'il n'est ni valide ni normalise. Il ne decide ni du
     * routage — la liaison de modele se fait sur le segment de chemin — ni du
     * contenu : la page rendue pour /v/86?m=nimporte-quoi est la MEME, au
     * caractere, que celle de /v/86. Un marqueur recu ne peut donc rien
     * empoisonner, puisque rien ne le relit.
     */
    private function page(
        ArticlePartage $article,
        string $chemin,
        Vehicle|Part|Accessory $source,
    ): Response {
        $url = $this->adresse($chemin);

        $version = VersionPartage::pour($source->updated_at);

        $urlPartage = $version === null ? $url : $url.'?m='.$version;

        // L'adresse MARQUEE dans le message pre-rempli, et c'est un choix.
        //
        // Ce message part de la page vers la boutique, et WhatsApp cle la
        // vignette qu'il affiche sur l'adresse qu'il a APPELEE — la raison
        // meme pour laquelle l'application doit partager une adresse qui
        // change. Un lien nu ferait voir au vendeur la vignette d'une version
        // precedente de son propre article, c'est-a-dire le defaut qu'on
        // corrige, retourne vers la boutique. Le texte circule par ailleurs :
        // un destinataire le recopie, et il doit se comporter comme un partage
        // fait depuis l'application.
        //
        // Le cout assume : routes/partage.php a choisi des chemins d'une lettre
        // parce qu'ils « voyagent a la main », parfois lus au telephone, et six
        // caracteres de marqueur se lisent mal a voix haute. Ce lien-ci est
        // clique, pas dicte — et l'adresse nue reste celle du canonique, donc
        // celle qu'on donne quand on en donne une a la voix.
        //
        // rawurlencode et non urlencode : wa.me recoit le texte dans une
        // chaine de requete, ou le « + » que rend urlencode pour un espace
        // s'affiche tel quel dans la conversation.
        $message = rawurlencode(
            "Bonjour, je suis intéressé par : {$article->titreAvecPrix()}\n{$urlPartage}"
        );

        $seller = PartnerListingPresentation::seller($source);
        $phone = $seller === null ? config('boutique.telephone') : $seller['phone'];
        $whatsapp = $seller === null
            ? config('boutique.whatsapp')
            : ($seller['whatsapp'] ?: $phone);
        $whatsapp = preg_replace('/\D+/', '', (string) $whatsapp);

        return response()
            ->view('partage.article', [
                'article'    => $article,
                'url'        => $url,
                'urlPartage' => $urlPartage,
                'whatsapp'   => $whatsapp === '' ? null : 'https://wa.me/'.$whatsapp.'?text='.$message,
                'telephone'  => $phone,
                'vendeur'    => $seller,
                'storeUrl'   => config('mobile.store_url'),
            ])
            ->header('Cache-Control', 'public, max-age='.self::CACHE_SECONDES);
    }

    /**
     * L'adresse CANONIQUE de cette page, composee depuis la configuration.
     *
     * Jamais depuis la requete. url()->current() suivait l'hote de la requete,
     * et bootstrap/app.php pose trustProxies(at: '*') — choix assume et
     * commente pour $request->ip() —, ce qui fait aussi confiance a
     * X-Forwarded-Host. Mesure : GET /v/1 avec
     * « X-Forwarded-Host: pharmacie-cadeaux.example » rendait
     * <meta property="og:url" content="http://pharmacie-cadeaux.example/v/1">,
     * et le lien WhatsApp pre-rempli portait la meme adresse. Combine a une
     * reponse mise en cache, c'est un apercu empoisonne qui se stocke et se
     * ressert.
     *
     * L'adresse d'une page de partage ne depend pas de la facon dont on l'a
     * demandee : elle est ce qu'on colle dans une conversation, donc une
     * propriete du service et non de la requete.
     *
     * Le chemin est fourni par l'appelant et non lu dans la requete, pour la
     * meme raison, et parce qu'il doit etre NU : /v/1?utm_source=x et /v/1
     * etaient deux pages pour l'indexation, et c'est cette adresse-ci que
     * <link rel="canonical"> designe.
     *
     * APP_URL est obligatoire en ligne, et le commentaire qui tenait cette
     * place affirmait l'inverse de la mesure. Il disait : « non renseigne,
     * Laravel vaut http://localhost, donc bruyant, immediat et sans danger ».
     * C'EST FAUX dans le cas que Render fabrique. Un champ `sync: false` laisse
     * blanc dans le tableau de bord arrive DEFINI ET VIDE, et le defaut du
     * second argument d'env() ne couvre que l'absence. Mesure, serveur demarre
     * avec APP_URL= :
     *
     *     config('app.url') -> ''      (var_export : '')
     *     HTTP 200
     *     <meta property="og:url" content="/v/1">
     *     <link rel="canonical" href="/v/1">
     *     <meta property="og:image" content="/storage/vehicles/1/photo.jpg">
     *     lien WhatsApp = https://wa.me/226...?text=...%0A%2Fv%2F1
     *
     * Rien de bruyant : une page qui rend 200, parait normale, n'ecrit dans
     * aucun journal, et dont la vignette est morte pour toujours. Le silence
     * etait du MEME cote que l'adresse prise dans la requete, pas en face.
     *
     * Deux corrections tiennent desormais ce cas, et ni l'une ni l'autre ne
     * vit ici : config/app.php se replie par `?:` plutot que par le defaut
     * d'env(), et AppServiceProvider::exigerUneAdressePubliqueAbsolue() refuse
     * de demarrer en production sur une adresse vide, relative ou locale.
     */
    private function adresse(string $chemin): string
    {
        return rtrim((string) config('app.url'), '/').'/'.ltrim($chemin, '/');
    }

    /**
     * L'URL ABSOLUE de la photo de couverture, ou null.
     *
     * Absolue parce qu'og:image l'exige : le robot ne resout pas de chemin
     * relatif. Les photos sont sur Cloudflare R2, dont les URL publiques le
     * sont deja.
     *
     * Null quand il n'y a pas de photo, et c'est ce que la vue attend pour
     * OMETTRE la balise. Pointer une image de remplacement qui n'existe pas
     * donne une vignette cassee dans la conversation, ce qui est pire que pas
     * de vignette : l'apercu a l'air d'une page en panne.
     *
     * Ce n'est plus une simple chaine : PhotoPartage porte aussi les
     * dimensions et le type de ce qui est servi, prefere la variante bornee a
     * 1200x630 a l'original — que WhatsApp refuse des quelques centaines de ko,
     * la ou les originaux pesent des megaoctets —, encode le chemin,
     * et rend `null` quand le disque n'offre aucune adresse publique. Chacun de
     * ces quatre points est un defaut mesure ; ils sont documentes la-bas.
     */
    private function couverture(Vehicle|Part|Accessory $article): ?PhotoPartage
    {
        // La premiere photo EXPLOITABLE, et non la premiere photo. L'ordre des
        // deux operations est tout le correctif : on filtre d'abord, on choisit
        // ensuite. Mesure avant correction, sur une galerie dont la premiere
        // ligne est inexploitable et la suivante valable :
        //
        //     path = ''  -> aucune balise og:image dans la page
        //     path = '0' -> og:image="http://localhost:8000/storage/0"
        //
        // Deux resultats opposes et tous deux faux : une photo valable attendait
        // derriere dans les deux cas. Le premier perd la vignette qu'il y avait,
        // le second en annonce une qui rend 404 — et twitter:card restait a
        // summary_large_image, donc en grand format.
        //
        // CE FILTRE N'A PAS D'EQUIVALENT COTE APPLICATION, et le commentaire
        // qui tenait cette place le laissait croire. Il disait que le lot
        // mobile avait corrige « le meme defaut » sur Vehicle, Part et
        // Accessory, et que le serveur « suivait ici ». Les trois modeles Dart
        // filtrent sur `u.isNotEmpty` (voir Part.fromJson) — c'est-a-dire sur
        // l'URL, pas sur le chemin. Mesure de ce que rend Media::url pour les
        // deux chemins inexploitables, sur les deux disques en service :
        //
        //     public path=''  -> 'http://localhost:8000/storage/'
        //     public path='0' -> 'http://localhost:8000/storage/0'
        //     s3     path=''  -> 'https://pub-abc.r2.dev/'
        //     s3     path='0' -> 'https://pub-abc.r2.dev/0'
        //
        // Aucune de ces quatre adresses n'est vide : le filtre de l'application
        // les laisse TOUTES passer, et `coverUrl` designe un 404. Il ne peut pas
        // faire mieux — rien dans une URL ne dit que le fichier derriere est
        // absent, et l'application ne recoit que des URL. Le seul endroit qui
        // connait le chemin, c'est le serveur.
        //
        // La correction est donc A LA SOURCE, et elle est double : les trois
        // ressources d'API n'emettent plus d'entree inexploitable (voir le
        // commentaire de VehicleResource), et cette methode-ci tient le meme
        // predicat pour la page de partage. Les deux appellent
        // PhotoPartage::cheminExploitable, ce qui est la seule garantie que
        // l'API et la page designent la meme photo.
        $exploitables = $article->media->filter(
            fn (Media $media) => PhotoPartage::cheminExploitable($media->path),
        )->filter(fn (Media $media) => ! PartnerListingPresentation::isPartnerListing($article)
            || PartnerMedia::allows($media, $article));

        // `media` est trie par position : a defaut de couverture declaree, la
        // premiere photo est celle que les ecrans du catalogue montrent deja.
        $photo = $exploitables->firstWhere('is_cover', true) ?? $exploitables->first();

        return PhotoPartage::depuis($photo);
    }

    /**
     * Description ramenee a la longueur d'un apercu.
     *
     * Facebook et WhatsApp coupent au-dela de deux a trois lignes ; au-dela de
     * 200 caracteres on ecrit donc pour personne. Les balises sont retirees
     * avant la coupe, parce qu'une description saisie en back-office peut en
     * contenir et qu'une balise tronquee par Str::limit ne se referme pas.
     */
    private function extrait(?string $description): ?string
    {
        $texte = trim(strip_tags((string) $description));

        return $texte === '' ? null : Str::limit($texte, 200);
    }

    /**
     * Libelles francais des etats et des boites.
     *
     * Ces trois enums n'ont pas de label() — l'application mobile traduit les
     * valeurs de son cote. La page web est lue sans l'application : « used »
     * ou « dual_clutch » affiches tels quels ne diraient rien a son lecteur.
     */
    private function etatVehicule(?VehicleCondition $condition): ?string
    {
        return match ($condition) {
            VehicleCondition::New      => 'Neuf',
            VehicleCondition::Used     => "D'occasion",
            VehicleCondition::Damaged  => 'Accidenté',
            VehicleCondition::ForParts => 'Pour pièces',
            default                    => null,
        };
    }

    private function etatPiece(?PartCondition $condition): ?string
    {
        return match ($condition) {
            PartCondition::New         => 'Neuve',
            PartCondition::Refurbished => 'Reconditionnée',
            PartCondition::Used        => "D'occasion",
            default                    => null,
        };
    }

    private function boite(?Transmission $transmission): ?string
    {
        return match ($transmission) {
            Transmission::Manual      => 'Manuelle',
            Transmission::Automatic   => 'Automatique',
            Transmission::Cvt         => 'CVT',
            Transmission::DualClutch  => 'Double embrayage',
            Transmission::SingleSpeed => 'Réducteur simple',
            default                   => null,
        };
    }
}
