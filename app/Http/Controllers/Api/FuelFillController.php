<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFuelFillRequest;
use App\Http\Resources\FuelFillResource;
use App\Models\FuelFill;
use App\Models\OwnedVehicle;
use App\Services\SuiviConsommation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Les passages a la pompe d'un vehicule du garage.
 *
 * Trois verbes seulement : lire, ajouter, supprimer. Pas de mise a jour, et
 * c'est voulu — corriger un passage reviendrait a deplacer une borne de mesure,
 * donc a changer deux intervalles a la fois sans que l'utilisateur voie lequel.
 * Supprimer puis ressaisir dit exactement ce qui s'est passe. C'est aussi
 * pourquoi la table n'a pas de SoftDeletes : une saisie fautive doit vraiment
 * disparaitre pour que la moyenne redevienne juste.
 *
 * Aucun calcul ici. Les chiffres viennent de SuiviConsommation, qui est la
 * seule source : l'ecran de la liste, la fiche du vehicule et la tache d'alerte
 * doivent afficher le meme l/100, faute de quoi le proprietaire lit deux
 * verites pour un seul vehicule.
 */
class FuelFillController extends Controller
{
    use AuthorizesRequests;

    /**
     * La fenetre du doublon : deux passages identiques du meme vehicule a moins
     * de dix minutes l'un de l'autre sont le meme passage.
     *
     * CE QU'ELLE PROTEGE. La cle d'idempotence du telephone ne survit pas a la
     * fermeture de la boite de saisie : sans reseau, le proprietaire ferme,
     * ressaisit, et repart avec une cle neuve. Mesure de la revue sur un
     * vehicule stable a 8,0 l/100 — 48 L sur 600 km — un demi-plein de 16 L
     * compte deux fois porte l'intervalle a 64 L, soit 10,7 l/100 ; deux
     * intervalles ainsi doubles franchissent les deux seuils (8,0 x 1,25 = 10,0
     * et 8,0 + 1,5 = 9,5) et l'alerte part sur un vehicule qui n'a pas bouge.
     * Un doublon est le seul defaut de saisie qui tire le chiffre vers le HAUT,
     * donc le seul qui produise une FAUSSE alerte : l'asymetrie decrite dans
     * SuiviConsommation ne couvre que les oublis.
     *
     * CE QU'ELLE LAISSE PASSER, et c'est assume. Une ressaisie faite le soir
     * pour un envoi du matin n'est plus rattrapee ici — dix minutes ne couvrent
     * que le geste repete dans la foulee, et allonger la fenetre finirait par
     * avaler des passages reels. Deux passages VRAIMENT distincts de memes
     * compteur, litres et nature en dix minutes sont eux confondus : completer
     * en deux fois a la station donne bien deux lignes au meme compteur, mais
     * rarement au litre pres, et perdre cette ligne-la coute moins cher qu'une
     * alerte fausse. Rien de tout cela ne dispense le telephone de garder sa
     * cle : c'est elle qui couvre le rejeu, celle-ci couvre le pouce.
     */
    private const FENETRE_DOUBLON_MINUTES = 10;

    public function __construct(private readonly SuiviConsommation $suivi) {}

    /**
     * L'historique des passages, du compteur le plus haut au plus bas.
     *
     * Par compteur decroissant et non par date : la date est facultative et
     * souvent saisie en retard, le compteur ne l'est jamais. C'est aussi
     * l'ordre dans lequel la chaine des intervalles se lit, a l'envers.
     */
    public function index(Request $request, OwnedVehicle $ownedVehicle): AnonymousResourceCollection
    {
        $this->authorize('view', $ownedVehicle);

        // reorder() et non orderByDesc() seul : la relation fuelFills porte deja
        // son propre tri croissant — celui dont le calcul depend — et un
        // orderBy ajoute par-dessus serait un second critere, sans effet. La
        // liste serait restee du plus ancien au plus recent sans qu'aucune
        // erreur ne le signale.
        $page = $ownedVehicle->fuelFills()
            ->reorder()
            ->orderByDesc('mileage_km')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return FuelFillResource::collection($this->avecIntervalles($page, $ownedVehicle));
    }

    /**
     * Enregistre un passage.
     *
     * 'update' et non 'view' : l'enregistrement releve le kilometrage du
     * vehicule, donc il ecrit — meme raison que le controle avant voyage.
     */
    public function store(StoreFuelFillRequest $request, OwnedVehicle $ownedVehicle): JsonResponse
    {
        $this->authorize('update', $ownedVehicle);

        $donnees = $request->validated();
        $reference = $donnees['client_reference'] ?? null;

        // Un envoi rejoue apres une coupure retrouve son passage au lieu d'en
        // creer un second. La saisie se fait a la station, ou le reseau est
        // mauvais : le rejeu est le cas normal, pas le cas rare. La reponse est
        // alors 200 et non 201, pour que l'application sache qu'elle n'a rien
        // cree — sans quoi un pouce impatient doublerait les litres du mois, et
        // la moyenne avec.
        $existant = $reference === null ? null : $ownedVehicle->fuelFills()
            ->where('client_reference', $reference)
            ->first();

        // La cle ci-dessus ne protege que tant qu'elle survit. Elle ne survit
        // pas a la fermeture de la boite de saisie : le proprietaire qui, faute
        // de reseau, ferme et ressaisit arrive avec une SECONDE cle, et les deux
        // envois sont alors deux passages distincts pour le serveur. D'ou cette
        // seconde barriere, qui ne regarde que le contenu.
        $existant ??= $this->passageJumeau($ownedVehicle, $donnees);

        // Le repli de date vit ICI et pas dans la requete : la colonne est
        // nullable, le champ facultatif, et l'application ne l'envoyait pas —
        // resultat mesure, filled_at restait NULL pour TOUT passage saisi depuis
        // le telephone, l'historique n'affichait aucune date et last_filled_at
        // valait null en permanence, alors que le contrat l'illustre par une
        // date. Les deux dates ne se valent pas et il faut savoir laquelle on
        // tient : celle du client est le jour vecu par le proprietaire, dans SON
        // fuseau ; ce repli est le jour du serveur, donc faux d'un jour pour une
        // saisie de fin de soiree. Une date approximative vaut mieux qu'une
        // colonne vide — le calcul se classe au compteur, pas a la date.
        $passage = $existant ?? $ownedVehicle->fuelFills()->create(array_merge($donnees, [
            'filled_at' => $donnees['filled_at'] ?? today(),
        ]));

        if ($existant === null) {
            $this->releverLeCompteur($ownedVehicle, $passage);
        }

        return $this->fiche($passage, $ownedVehicle)
            ->response()
            ->setStatusCode($existant === null ? 201 : 200);
    }

    /**
     * Efface un passage.
     *
     * Pas de FuelFillPolicy : un passage n'a pas de proprietaire a lui, il tient
     * celui de son vehicule. L'autorisation remonte donc au vehicule, et c'est
     * 'update' parce que supprimer un passage change la consommation affichee de
     * ce vehicule.
     */
    public function destroy(FuelFill $fuelFill): JsonResponse
    {
        $vehicule = $fuelFill->ownedVehicle;

        // owned_vehicles porte des SoftDeletes : un vehicule retire du garage
        // laisse ses passages derriere lui et la relation rend null. Refuser
        // plutot que laisser authorize() travailler sur un vehicule absent, qui
        // leverait une erreur au lieu d'un 403.
        abort_if($vehicule === null, 403);

        $this->authorize('update', $vehicule);

        $fuelFill->delete();

        // Le kilometrage du vehicule n'est pas rabaisse : il reste le plus haut
        // compteur jamais vu, et c'est la verite — le vehicule a bien roule
        // jusque-la, meme si la saisie qui l'attestait etait fautive.
        return response()->json(['message' => 'Passage à la pompe supprimé.']);
    }

    /**
     * Reporte le compteur du passage sur le vehicule quand il le depasse.
     *
     * Un kilometrage saisi a deux endroits qui divergent est un defaut : c'est
     * le meme nombre. Effet voulu et non accessoire — le rappel de vidange se
     * nourrit de mileage_km, donc chaque passage a la pompe le remet a jour
     * sans que le proprietaire ait a y penser.
     *
     * Strictement superieur : un passage saisi en retard porte un compteur plus
     * bas que le dernier releve, et l'abaisser ferait reculer le vehicule.
     */
    private function releverLeCompteur(OwnedVehicle $vehicule, FuelFill $passage): void
    {
        if ((int) $passage->mileage_km > (int) $vehicule->mileage_km) {
            $vehicule->update(['mileage_km' => (int) $passage->mileage_km]);
        }
    }

    /**
     * Le jumeau recent d'un envoi : meme vehicule, meme compteur, memes litres,
     * meme nature, cree dans la fenetre ci-dessus.
     *
     * La date n'entre PAS dans la comparaison : le repli serveur la renseigne,
     * donc deux ressaisies du meme passage peuvent porter deux dates
     * differentes a minuit pile sans cesser d'etre le meme passage. Le montant
     * non plus : on le retape de memoire, et un doublon ou le ticket a ete lu
     * la seconde fois reste un doublon.
     *
     * Les litres sont compares a la precision de la colonne, decimal(6,2) :
     * compares en flottant, « 16 » envoye par le telephone et « 16.00 » relu de
     * la base ne se seraient pas toujours reconnus selon le moteur.
     *
     * @param  array<string, mixed>  $donnees
     */
    private function passageJumeau(OwnedVehicle $vehicule, array $donnees): ?FuelFill
    {
        return $vehicule->fuelFills()
            ->where('mileage_km', (int) $donnees['mileage_km'])
            ->where('litres', number_format((float) $donnees['litres'], 2, '.', ''))
            ->where('is_full', (bool) $donnees['is_full'])
            ->where('created_at', '>=', now()->subMinutes(self::FENETRE_DOUBLON_MINUTES))
            ->first();
    }

    /** Un passage seul, porteur du chiffre de l'intervalle qu'il ferme. */
    private function fiche(FuelFill $passage, OwnedVehicle $vehicule): FuelFillResource
    {
        [$intervalles, $bornes] = $this->chaine($vehicule);

        return (new FuelFillResource($passage))
            ->avecIntervalle($intervalles[$passage->id] ?? null, $bornes[$passage->id] ?? 0);
    }

    /**
     * Accroche a chaque ligne de la page l'intervalle qu'elle ferme.
     *
     * La chaine est calculee UNE fois pour tout le vehicule, puis distribuee :
     * un intervalle depend de tous les passages, pas seulement des deux qui le
     * bornent, donc le calculer par ligne reviendrait a le refaire vingt fois
     * et a obtenir vingt fois le meme resultat.
     *
     * @param  LengthAwarePaginator<int, FuelFill>  $page
     * @return LengthAwarePaginator<int, FuelFillResource>
     */
    private function avecIntervalles(LengthAwarePaginator $page, OwnedVehicle $vehicule): LengthAwarePaginator
    {
        [$intervalles, $bornes] = $this->chaine($vehicule);

        return $page->setCollection(
            $page->getCollection()->map(fn (FuelFill $passage) => (new FuelFillResource($passage))
                ->avecIntervalle($intervalles[$passage->id] ?? null, $bornes[$passage->id] ?? 0)),
        );
    }

    /**
     * La chaine du vehicule, sous les deux formes dont la ressource a besoin :
     * les intervalles indexes par le plein qui les FERME, et le nombre
     * d'intervalles RETENUS que chaque passage borne — d'un cote ou de l'autre.
     *
     * Les deux sortent du meme calcul parce qu'ils sortent de la meme chaine :
     * la refaire une seconde fois pour compter les bornes couterait un second
     * parcours de tous les passages du vehicule a chaque page de vingt lignes.
     *
     * La relation est relue de la base a chaque appel : apres une creation,
     * celle qui etait en memoire ne contient pas le passage qu'on vient
     * d'ajouter, et l'intervalle qu'il ferme n'existerait pas dans la reponse
     * meme envoi.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, int>}
     */
    private function chaine(OwnedVehicle $vehicule): array
    {
        $vehicule->load('fuelFills');

        $parBorneFin = [];
        $bornes = [];

        foreach ($this->suivi->intervalles($vehicule) as $intervalle) {
            $parBorneFin[$intervalle['borne_fin']] = $intervalle;

            // Seuls les intervalles RETENUS sont comptes : avertir avant de
            // supprimer un passage n'a de sens que pour les mesures qui entrent
            // dans le chiffre affiche. Un passage qui borne deux intervalles
            // ecartes ne coute rien a supprimer, et l'annoncer apprendrait a
            // l'utilisateur a passer outre la confirmation.
            if (! $intervalle['retenu']) {
                continue;
            }

            foreach (['borne_debut', 'borne_fin'] as $cote) {
                $bornes[$intervalle[$cote]] = ($bornes[$intervalle[$cote]] ?? 0) + 1;
            }
        }

        return [$parBorneFin, $bornes];
    }
}
