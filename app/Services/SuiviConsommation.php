<?php

namespace App\Services;

use App\Models\FuelFill;
use App\Models\OwnedVehicle;

/**
 * La consommation reelle d'un vehicule, et son cout, a partir de ses passages
 * a la pompe.
 *
 * TOUT le calcul tient ici, et c'est la raison d'etre de la classe. La
 * ressource API et la tache d'alerte liront le meme nombre, produit par le meme
 * code — comme deadlines() l'est deja pour les echeances. Le projet a deja paye
 * le prix du contraire : les kilometres avant vidange n'existaient qu'a
 * l'interieur du libelle « Dans 4 000 km », que la tache de rappel relisait par
 * expression reguliere, et une reformulation du libelle a eteint le rappel sans
 * qu'aucune erreur ne soit levee. Un seuil ou une formule recopie ailleurs finit
 * toujours par diverger, et l'utilisateur voit alors deux verites pour un meme
 * vehicule.
 *
 * LA METHODE. Les passages sont ordonnes par compteur croissant, jamais par
 * date ni par identifiant : une saisie faite trois jours plus tard doit
 * s'inserer a sa place reelle dans la chaine, sinon elle coupe le mauvais
 * intervalle. Un intervalle court entre deux pleins consecutifs A et B ; on y
 * compte les litres de TOUS les passages posterieurs a A jusqu'a B inclus,
 * demi-pleins compris. C'est juste parce que le niveau du reservoir est le meme
 * en A et en B — la pompe s'y est arretee seule dans les deux cas — donc tout
 * ce qui a ete achete entre les deux est passe dans le moteur. Les demi-pleins
 * ne sont ni ecartes ni interpoles : ils sont comptes.
 *
 * Et puisque le niveau est le meme a chaque plein, un plein intermediaire peut
 * etre SAUTE sans rien biaiser : A plein, B plein, C plein, mesurer A->C donne
 * exactement la meme physique que A->B puis B->C. C'est ce qui permet a un
 * intervalle trop court de se prolonger au lieu d'etre jete — deux pleins
 * rapproches de 30 puis 40 km rendaient auparavant 70 km de mesure
 * parfaitement valides a la poubelle.
 *
 * L'ASYMETRIE QUI REND L'ALERTE SURE. Un passage non enregistre laisse une
 * distance reelle et des litres incomplets : le l/100 calcule s'effondre, et
 * l'intervalle est ecarte. Un oubli tire donc toujours le resultat vers le BAS.
 * Il produit un silence, jamais une fausse alerte — et c'est sur cette
 * asymetrie, et non sur la discipline du proprietaire, que repose la confiance
 * qu'on peut accorder a l'alerte.
 *
 * Elle ne couvre QUE l'oubli. Le DOUBLON fait l'inverse : les memes litres
 * comptes deux fois gonflent l'intervalle sans toucher a la distance. Mesure de
 * la revue — un demi-plein de 16 L saisi deux fois dans un intervalle de 600 km
 * a 48 L le porte a 64 L, soit 10,7 l/100 contre 8,0, et deux intervalles ainsi
 * doubles franchissent les deux seuils. Ici, rien ne peut le voir : 10,7 est un
 * chiffre parfaitement vraisemblable. Le doublon se traite donc a l'ENTREE, par
 * la cle d'idempotence du telephone et par la fenetre de dix minutes de
 * FuelFillController::store(), et nulle part ailleurs.
 *
 * Le plancher ABSOLU de 3,0 ne suffit pas a tenir cette promesse : pour qu'un
 * vehicule a 8,0 l/100 descende sous 3,0, il faut que plus de 62 % du carburant
 * manque. Un oubli sur deux passages produit 4,0 l/100 (1 000 km pour 40 L),
 * qui franchit le plancher sans difficulte, entre dans la mediane de reference
 * et l'abaisse — apres quoi les intervalles CORRECTEMENT saisis a 8,0 sortent
 * du regime et l'alerte part sur un vehicule dont la consommation n'a jamais
 * varie. D'ou le plancher RELATIF de la seconde passe, a 60 % de la mediane :
 * un 4,0 dans une histoire a 8,0 est la signature d'un oubli, pas d'une
 * conduite econome. Sa portee exacte — et le cas qu'il ne rattrape pas, celui
 * ou les intervalles amputes sont majoritaires — est decrite sur
 * ecarterLesAnormalementBas().
 *
 * LE COMPTEUR REMPLACE. Un compteur neuf repart d'un petit nombre, et trier par
 * kilometrage seul renvoie alors toute la vie d'avant EN FIN de chaine : 7
 * pleins a 11,0 depuis 200 000 km suivis de 4 pleins reels a 7,0 depuis 1 800
 * faisaient afficher 11,0 et « dernier passage 203 600 km » a un proprietaire
 * dont le compteur lit 1 800 — et aucune derive future n'aurait jamais pu
 * declencher l'alerte. La chaine se coupe donc en series, et seule la plus
 * recente est calculee.
 */
class SuiviConsommation
{
    /**
     * En dessous de 50 km, la variation du point de coupure de la pompe — deux
     * ou trois litres d'un passage a l'autre — pese plus que la consommation
     * mesuree. Ce n'est PAS un motif d'ecart : l'intervalle se prolonge jusqu'au
     * plein suivant, parce que sauter un plein intermediaire ne biaise rien
     * (meme niveau de reservoir aux deux bornes). Jeter ces intervalles coutait
     * 70 km de mesure valide des que deux pleins tombaient a 30 puis 40 km, et
     * laissait au passage une division par zero possible sur deux pleins au
     * meme compteur — cas normal a la station quand on complete en deux fois.
     *
     * Au-dela de 2 000 km, l'hypothese « aucun passage n'a ete oublie » devient
     * moins probable que son contraire, et la l'intervalle est bien ecarte.
     */
    private const DISTANCE_MIN_KM = 50;

    private const DISTANCE_MAX_KM = 2000;

    /**
     * Le plancher n'est pas une borne de vraisemblance mecanique : c'est la
     * signature d'un passage manquant. Aucun vehicule du parc ne tient 3,0
     * l/100 km, mais un plein oublie en produit un a tous les coups.
     */
    private const L100_MIN = 3.0;

    private const L100_MAX = 30.0;

    /**
     * Le plancher RELATIF, seconde passe : un intervalle sous 60 % de la mediane
     * du vehicule est ecarte comme 'anormalement_bas'.
     *
     * Le plancher absolu de 3,0 ne voit que les oublis massifs — il faut perdre
     * plus de 62 % du carburant d'un vehicule a 8,0 pour descendre sous 3,0. Un
     * oubli sur deux rend 4,0 l/100 (1 000 km pour 40 L), parfaitement retenu
     * jusqu'ici, et c'est lui qui abaissait la mediane de reference au point de
     * faire sonner l'alerte sur les intervalles JUSTES.
     *
     * 60 % et pas plus haut : un trajet d'autoroute a 6,0 contre une mediane
     * urbaine a 8,0 doit survivre (6,0 > 4,8), car c'est de la conduite, pas une
     * saisie manquante. Un demi-passage, lui, divise le taux par deux environ.
     */
    /**
     * Part de la cote constructeur en dessous de laquelle un intervalle est
     * une mesure perdue. Voir plancherDeVraisemblance().
     */
    private const PART_MIN_COTE = 0.60;

    private const PART_MIN_MEDIANE = 0.60;

    /**
     * La seconde passe ne s'applique qu'a partir de quatre intervalles retenus.
     * En dessous, la mediane repose sur deux ou trois valeurs : un seul long
     * trajet d'autoroute la deplacerait assez pour ecarter les autres.
     */
    private const INTERVALLES_MIN_MEDIANE = 4;

    /**
     * Le chiffre affiche porte sur les cinq derniers intervalles retenus. Plus
     * court, il saute a chaque plein ; plus long, il met un trimestre a refleter
     * un filtre a air change.
     */
    private const FENETRE_INTERVALLES = 5;

    /** En dessous, la mediane de reference porterait sur moins de quatre valeurs. */
    private const INTERVALLES_MIN_ALERTE = 6;

    /**
     * Les deux seuils se cumulent, et aucun ne suffit seul. Le relatif seul
     * ferait sonner un vehicule a 4,0 l/100 pour un litre d'ecart ; l'absolu
     * seul laisserait passer un 4x4 a 18 l/100 qui derive de deux litres.
     */
    private const SEUIL_RELATIF = 1.25;

    private const SEUIL_ABSOLU = 1.5;

    /**
     * Au-dela de cette dispersion, la serie ne porte aucun verdict.
     *
     * Le plancher relatif de la passe 2 ecarte un intervalle anormalement bas
     * en le comparant a la mediane du vehicule. Il ne protege donc que tant
     * que les intervalles fautifs sont MINORITAIRES : quand un proprietaire
     * oublie un passage sur deux, la mediane descend avec eux et ne denonce
     * plus rien. Mesure : un vehicule reellement a 8,0 l/100 qui produit
     * 4 4 4 4 8 8 donne une mediane de 4,0 ; 60 % de 4,0 font 2,4, aucun 4,0
     * n'est sous ce plancher, et les deux intervalles CORRECTEMENT saisis a
     * 8,0 franchissent alors 4,0 x 1,25 et 4,0 + 1,5. L'alerte partait sur une
     * consommation qui n'avait jamais bouge.
     *
     * Ce qui distingue cette serie d'une vraie derive n'est pas son niveau,
     * c'est sa FORME : elle se scinde en deux groupes eloignes de taille
     * comparable. Une derive reelle, elle, garde une majorite stable et un
     * petit nombre de valeurs recentes au-dessus.
     *
     * L'ecart absolu median a la mediane, rapporte a la mediane, lit
     * exactement cette difference. Valeurs mesurees :
     *
     *   8 8 8 8 11 11   derive reelle          0,00  -> on alerte
     *   8 8 8 11 11 11  derive reelle, 3-3     0,16  -> on alerte
     *   8 8 8 8 16 16   derive brutale         0,00  -> on alerte
     *   4 4 4 8 8 8     oublis, moitie         0,33  -> on se tait
     *   4 4 4 4 8 8     oublis majoritaires    0,00  -> ON ALERTE ENCORE
     *
     * La derniere ligne est la limite de ce garde-fou, et elle est de fond :
     * quatre valeurs a 4,0 et deux a 8,0 ont EXACTEMENT la meme forme que
     * quatre a 8,0 et deux a 16,0. Quatre valeurs sur six fixent la mediane,
     * l'ecart median vaut alors zero dans les deux cas, et aucune mesure de
     * forme ne peut les distinguer — l'une est une serie d'oublis, l'autre un
     * doublement reel qu'il faut justement signaler.
     *
     * Ce qui les separe n'est pas la forme mais le NIVEAU : 4,0 l/100 n'existe
     * pas sur une voiture thermique, 16,0 si. D'ou le plancher de
     * vraisemblance adosse a la cote constructeur, dans
     * ecarterLesAnormalementBas() : un repere exterieur aux donnees du
     * proprietaire, que ses oublis ne peuvent pas deplacer.
     *
     * Le seuil est pose entre 0,16 et 0,33. Ce garde-fou coute quelques vraies
     * derives sur des series chaotiques ; il rend en echange le silence, qui
     * est le cote sur : la regle que toute cette fonction defend est qu'un
     * passage oublie ne doit jamais produire une fausse alerte.
     */
    private const DISPERSION_MAX = 0.25;

    /**
     * Un intervalle par paire de pleins consecutifs, dans l'ordre du compteur.
     *
     * Les demi-pleins n'apparaissent pas dans cette liste : ils n'ouvrent ni ne
     * ferment rien, ils sont deja comptes dans les litres de l'intervalle qui
     * les contient.
     *
     * borne_debut et borne_fin portent l'identifiant des deux pleins. C'est
     * borne_fin que la ressource API interroge : un passage affiche le chiffre
     * de l'intervalle qui FINIT sur lui, puisque c'est lui qui l'a ferme.
     *
     * @return array<int, array{borne_debut:int,borne_fin:int,distance_km:int,litres:float,l_100km:float,montant_fcfa:?int,retenu:bool,motif_ecart:?string}>
     */
    public function intervalles(OwnedVehicle $vehicule): array
    {
        return $this->ecarterLesAnormalementBas($this->chaine($this->passages($vehicule)), $vehicule);
    }

    /**
     * La premiere passe : un intervalle par couple de pleins, avec les seuls
     * motifs qui se jugent sur l'intervalle seul — plafond de distance et
     * bornes absolues de vraisemblance.
     *
     * @param  array<int, FuelFill>  $passages
     * @return array<int, array<string, mixed>>
     */
    private function chaine(array $passages): array
    {
        $intervalles = [];

        $ouverture = null;   // le dernier plein qui a ferme un intervalle
        $litres = 0.0;
        $montant = 0;
        $montantComplet = true;

        foreach ($passages as $passage) {
            // Tant qu'aucun plein n'a ete vu, rien ne peut etre mesure : on
            // ignore le niveau de depart du reservoir. Ces litres-la ne sont pas
            // perdus pour autant, ils restent dans les totaux bruts.
            if ($ouverture === null) {
                if ($passage->is_full) {
                    $ouverture = $passage;
                }

                continue;
            }

            $litres += (float) $passage->litres;

            // Un seul montant manquant suffit a rendre le cout de l'intervalle
            // faux. Mieux vaut ne rien afficher qu'un cout aux 100 km ampute
            // d'un passage : le proprietaire le croirait, et conclurait que son
            // vehicule lui coute moins cher qu'il ne lui coute.
            if ($passage->amount_fcfa === null) {
                $montantComplet = false;
            } else {
                $montant += (int) $passage->amount_fcfa;
            }

            if (! $passage->is_full) {
                continue;
            }

            // Trop court : on ne FERME pas, on prolonge. Le niveau du reservoir
            // est le meme a ce plein qu'a l'ouverture, donc le sauter ne biaise
            // rien — c'est physiquement la meme borne. Deux pleins a 30 puis
            // 40 km rendent ainsi un intervalle de 70 km qui porte tous les
            // litres, la ou l'ancien code jetait les deux et perdait la mesure.
            // Deux pleins au meme compteur (distance 0) tombent ici aussi, ce
            // qui supprime du meme coup la division par zero.
            if ((int) $passage->mileage_km - (int) $ouverture->mileage_km < self::DISTANCE_MIN_KM) {
                continue;
            }

            $intervalles[] = $this->intervalle(
                $ouverture,
                $passage,
                $litres,
                $montantComplet ? $montant : null,
            );

            // Le plein qui ferme un intervalle ouvre le suivant : ses litres
            // appartiennent a celui qui se termine, son niveau a celui qui
            // commence.
            $ouverture = $passage;
            $litres = 0.0;
            $montant = 0;
            $montantComplet = true;
        }

        return $intervalles;
    }

    /**
     * La seconde passe : ecarter les intervalles anormalement BAS, ceux dont le
     * taux trahit un passage oublie juste avant eux.
     *
     * Deux passes et non une, parce que la reference n'existe pas avant d'avoir
     * trie le reste : on retient d'abord selon les bornes absolues, on en tire
     * une mediane provisoire, puis on ecarte ce qui tombe sous 60 % d'elle.
     * L'ordre est fixe, donc le resultat est reproductible — deux executions sur
     * les memes passages rendent les memes motifs.
     *
     * Ce que cela repare : le plancher absolu de 3,0 laissait passer les 4,0
     * l/100 d'un oubli sur deux (1 000 km pour 40 L) sur un vehicule qui fait
     * 8,0 — il faut perdre plus de 62 % du carburant pour descendre sous 3,0.
     * Ces 4,0 entraient dans la mediane de reference et l'abaissaient ; les
     * intervalles JUSTES a 8,0 franchissaient alors les deux seuils, et
     * l'alerte partait sur un vehicule dont la consommation n'avait pas bouge
     * d'un dixieme. Serie 8 8 8 4 8 8 : la mediane vaut 8,0, le plancher 4,8,
     * le 4,0 est ecarte, et la reference reste 8,0.
     *
     * CE QUE CELA NE REPARE PAS, et il faut le savoir en lisant l'alerte : la
     * mediane est elle-meme la grandeur empoisonnee. Des que les intervalles
     * amputes sont MAJORITAIRES, elle descend avec eux et le plancher relatif
     * descend avec elle. Serie mesuree 4 4 4 4 8 8 : mediane 4,0, plancher 2,4,
     * aucun 4,0 ecarte, baseline 4,0, et l'alerte part toujours a tort. Aucun
     * facteur applique a la mediane ne peut rattraper ce cas — il demanderait
     * une reference qui resiste a plus de 50 % de contamination, par exemple la
     * mediane de la moitie haute. Le choix de la mediane est ici une consigne
     * explicite, pas un oubli ; la limite est mesuree et documentee par un test.
     *
     * Le taux compare est le taux PUBLIE, celui arrondi au dixieme : le motif
     * affiche a l'ecran doit se verifier sur les nombres de l'ecran.
     *
     * @param  array<int, array<string, mixed>>  $intervalles
     * @return array<int, array<string, mixed>>
     */
    private function ecarterLesAnormalementBas(array $intervalles, OwnedVehicle $vehicule): array
    {
        $plancher = $this->plancherDeVraisemblance($intervalles, $vehicule);

        if ($plancher === null) {
            return $intervalles;
        }

        foreach ($intervalles as $rang => $intervalle) {
            if ($intervalle['retenu'] && $intervalle['l_100km'] < $plancher) {
                $intervalles[$rang]['retenu'] = false;
                $intervalles[$rang]['motif_ecart'] = 'anormalement_bas';
            }
        }

        return $intervalles;
    }

    /**
     * En dessous de quel taux un intervalle est une mesure perdue.
     *
     * Deux reperes, et l'ordre compte.
     *
     * LA COTE CONSTRUCTEUR D'ABORD, quand le proprietaire a choisi sa
     * motorisation. C'est le seul repere que ses oublis ne peuvent pas
     * deplacer, et c'est precisement ce qui manquait : le plancher relatif
     * seul se calcule sur la mediane du vehicule, donc il descend avec les
     * valeurs fausses qu'il devrait denoncer. Mesure : un vehicule a 8,0 l/100
     * qui oublie un passage sur deux produit 4 4 4 4 8 8, la mediane tombe a
     * 4,0, et 60 % de 4,0 font 2,4 — aucun 4,0 n'est ecarte. Avec une cote a
     * 7,0, le plancher vaut 4,2 : les quatre valeurs fausses sortent, et les
     * deux mesures honnetes a 8,0 cessent de passer pour anormales.
     *
     * La cote est volontairement divisee par un facteur large. Elle est
     * etablie sur cycle d'homologation, et la conduite reelle s'en ecarte de
     * 20 a 30 % VERS LE HAUT : rouler nettement EN DESSOUS d'une cote
     * d'homologation n'arrive pas, et ce qui s'en approche par le bas est un
     * litre manquant, pas une conduite econome.
     *
     * LA MEDIANE DU VEHICULE ENSUITE, pour les modeles qu'aucune source
     * publique ne couvre — Fortuner, Prado, D-Max, une bonne part du parc
     * ici. Le repere est alors interne, donc faillible de la facon decrite
     * plus haut ; il vaut mieux que rien tant que les oublis restent
     * minoritaires, et le garde-fou de dispersion couvre une partie du reste.
     */
    private function plancherDeVraisemblance(array $intervalles, OwnedVehicle $vehicule): ?float
    {
        $cote = $vehicule->motorisation?->consumption_combined;

        if ($cote !== null && (float) $cote > 0) {
            return self::PART_MIN_COTE * (float) $cote;
        }

        $retenus = array_filter($intervalles, fn (array $i) => $i['retenu']);

        if (count($retenus) < self::INTERVALLES_MIN_MEDIANE) {
            return null;
        }

        return self::PART_MIN_MEDIANE * $this->mediane(array_column($retenus, 'l_100km'));
    }

    /**
     * La mediane d'une liste de taux, sans arrondi.
     *
     * Mediane et non moyenne partout ou une reference est tiree de l'histoire du
     * vehicule : sur une dizaine de valeurs, une seule faute de frappe au
     * compteur deplace la moyenne de plusieurs litres et emporte l'alerte avec
     * elle. La mediane ne bouge pas.
     *
     * @param  array<int, float>  $valeurs  non vide
     */
    private function mediane(array $valeurs): float
    {
        sort($valeurs);

        $milieu = intdiv(count($valeurs), 2);

        return count($valeurs) % 2 === 1
            ? (float) $valeurs[$milieu]
            : ((float) $valeurs[$milieu - 1] + (float) $valeurs[$milieu]) / 2;
    }

    /**
     * Le suivi tel que l'application l'affiche : les cles de 'fuel_tracking'.
     *
     * Toujours rendu en entier, meme sans un seul passage. L'application doit
     * pouvoir distinguer « ce vehicule n'a aucun passage » de « le serveur n'a
     * rien dit sur ce point » — une cle absente ne dit ni l'un ni l'autre.
     *
     * @return array<string, mixed>
     */
    public function resume(OwnedVehicle $vehicule): array
    {
        // Les series sont calculees une fois : resume() a besoin de la plus
        // recente ET de savoir s'il y en a eu plusieurs, et les redemander
        // reparcourrait toute la table une seconde fois pour chaque vehicule du
        // balayage quotidien.
        $series = $this->series($vehicule);
        $passages = $series === [] ? [] : $series[count($series) - 1];

        $intervalles = $this->ecarterLesAnormalementBas($this->chaine($passages), $vehicule);

        $retenus = array_values(array_filter($intervalles, fn (array $i) => $i['retenu']));
        $fenetre = array_slice($retenus, -self::FENETRE_INTERVALLES);

        $distanceFenetre = array_sum(array_column($fenetre, 'distance_km'));

        // Somme sur somme, et non moyenne des taux. Une moyenne de taux donne le
        // meme poids a un intervalle de 80 km qu'a un de 600 — or c'est le court
        // qui porte le plus de bruit, puisque la meme incertitude de coupure de
        // pompe s'y repartit sur sept fois moins de kilometres.
        $l100 = $distanceFenetre > 0
            ? round(100 * array_sum(array_column($fenetre, 'litres')) / $distanceFenetre, 1)
            : null;

        $avecMontant = array_filter($fenetre, fn (array $i) => $i['montant_fcfa'] !== null);
        $distanceAvecMontant = array_sum(array_column($avecMontant, 'distance_km'));

        $fcfa100 = $distanceAvecMontant > 0
            ? (int) round(100 * array_sum(array_column($avecMontant, 'montant_fcfa')) / $distanceAvecMontant)
            : null;

        $base = $this->base($retenus);
        $anormal = $this->anormal($retenus, $base);
        $dernier = $passages === [] ? null : $passages[count($passages) - 1];

        return [
            'l_100km'            => $l100,
            'fcfa_100km'         => $fcfa100,
            'based_on_fills'     => count($fenetre),
            'total_fills'        => count($passages),
            'full_fills'         => count(array_filter($passages, fn (FuelFill $p) => (bool) $p->is_full)),
            'excluded_intervals' => count($intervalles) - count($retenus),
            'last_filled_at'     => $this->derniereDate($passages),
            'last_mileage_km'    => $dernier === null ? null : (int) $dernier->mileage_km,

            // Deja arrondie par base(), et publiee telle quelle : c'est la meme
            // valeur qui a servi au verdict. Arrondir une seconde fois ici
            // produisait un nombre que le proprietaire ne pouvait pas refaire —
            // base reelle 7,15, publiee 7,2, et le seuil publie valait alors
            // exactement 9,0 quand le vrai valait 8,9375. Avec 9,0 mesure,
            // l'alerte partait sans que les nombres montres l'expliquent.
            'baseline_l_100km'   => $base,
            'abnormal'           => $anormal,

            // Le chiffre QUI A TRANCHE, et non celui qui s'affiche. 'l_100km'
            // porte les cinq derniers intervalles, le verdict se prend sur les
            // DEUX derniers : serie 8 8 8 8 3,1 3,1 11 11 -> baseline 8,0,
            // abnormal vrai, l_100km 7,2, et la notification disait « 7,2 l/100
            // km, contre 8,0 d'habitude » sous le titre « Consommation en
            // hausse ». Elle annoncait une baisse.
            'alerte_l_100km'     => $anormal ? $this->tauxDeLAlerte($retenus) : null,

            // Vrai quand un compteur a ete remplace : l'histoire d'avant existe
            // toujours en base, mais elle n'entre plus dans aucun de ces
            // chiffres. L'ecran doit pouvoir le dire, sinon le proprietaire voit
            // ses totaux fondre sans explication.
            'series_rompue'      => count($series) > 1,
            'totals'             => $this->totaux($passages),
        ];
    }

    /**
     * Le taux sur lequel l'alerte s'est prononcee : somme sur somme des DEUX
     * derniers intervalles retenus, exactement ceux que anormal() juge.
     *
     * Somme sur somme et non moyenne des deux taux, pour la meme raison que
     * partout ailleurs : un intervalle de 80 km et un de 600 ne pesent pas
     * pareil.
     *
     * @param  array<int, array<string, mixed>>  $retenus
     */
    private function tauxDeLAlerte(array $retenus): ?float
    {
        $juges = array_slice($retenus, -2);
        $distance = array_sum(array_column($juges, 'distance_km'));

        return $distance > 0
            ? round(100 * array_sum(array_column($juges, 'litres')) / $distance, 1)
            : null;
    }

    /**
     * La derniere date vecue par le vehicule.
     *
     * Le maximum des dates, et non la date du dernier passage de la chaine :
     * celle-ci se lit au plus haut COMPTEUR, et un ticket retrouve dans une
     * poche se saisit avec un compteur intermediaire. L'ecran annoncait alors
     * une date anterieure a la derniere saisie, sur un vehicule qui avait bien
     * roule depuis.
     *
     * created_at prend le relais quand filled_at manque — il est facultatif,
     * parce que c'est le compteur qui porte le calcul, pas la date. Un passage
     * sans date a eu lieu au plus tard le jour de sa saisie, ce qui est une
     * meilleure reponse que « aucune date ».
     *
     * @param  array<int, FuelFill>  $passages
     */
    private function derniereDate(array $passages): ?string
    {
        $dates = [];

        foreach ($passages as $passage) {
            $date = $passage->filled_at ?? $passage->created_at;

            if ($date !== null) {
                $dates[] = $date->toDateString();
            }
        }

        return $dates === [] ? null : max($dates);
    }

    /**
     * Les faits bruts : ce qui a ete achete, et ce qui a ete roule.
     *
     * Ils sont calcules meme sans aucun intervalle retenu, parce qu'ils servent
     * l'ecran du proprietaire qui ne fait jamais le plein — celui pour qui le
     * l/100 restera toujours nul. On lui montre sa depense et sa distance sans
     * jamais appeler cela une moyenne.
     *
     * Et on n'en tire aucun taux : entre le premier et le dernier passage, le
     * niveau du reservoir a varie d'une quantite inconnue, bornee par une
     * capacite que nous ne connaissons pas davantage. Un l/100 calcule la-dessus
     * serait faux d'autant, et rien ne permettrait de dire de combien.
     *
     * @param  array<int, FuelFill>  $passages
     * @return array{litres:float,amount_fcfa:int,distance_km:int}
     */
    private function totaux(array $passages): array
    {
        $litres = 0.0;
        $montant = 0;

        foreach ($passages as $passage) {
            $litres += (float) $passage->litres;
            $montant += (int) ($passage->amount_fcfa ?? 0);
        }

        $distance = count($passages) < 2
            ? 0
            : (int) $passages[count($passages) - 1]->mileage_km - (int) $passages[0]->mileage_km;

        return [
            'litres'      => round($litres, 2),
            'amount_fcfa' => $montant,
            'distance_km' => $distance,
        ];
    }

    /**
     * La reference a laquelle se compare un ecart : la MEDIANE des intervalles
     * retenus, les deux derniers exclus.
     *
     * ARRONDIE ICI, et une seule fois. C'est cette valeur-la qui sert au
     * verdict et qui est publiee, parce que les deux doivent etre le meme
     * nombre : avec les retenus 7,0 / 7,1 / 7,2 / 7,2 / 9,0 / 9,0, la mediane
     * pleine vaut 7,15 et le seuil 8,9375, que 9,0 franchit ; la valeur publiee
     * etait 7,2, dont le seuil vaut exactement 9,0, que 9,0 ne franchit pas. Le
     * proprietaire qui refaisait le calcul sur les nombres affiches ne
     * retrouvait pas son alerte, et une alerte qu'on ne peut pas verifier ne se
     * croit pas.
     *
     * Mediane et non moyenne : sur une serie d'une dizaine de valeurs, une seule
     * faute de frappe au compteur deplace la moyenne de plusieurs litres et
     * emporte l'alerte avec elle. La mediane ne bouge pas.
     *
     * Les deux derniers sont exclus parce que ce sont eux qu'on juge : les
     * inclure reviendrait a comparer une derive a elle-meme, et plus elle
     * durerait moins elle se verrait.
     *
     * Le vehicule se compare a sa propre histoire, jamais a la cote
     * constructeur : les cycles d'homologation s'ecartent de 20 a 30 % de la
     * conduite reelle, et une alerte gagee dessus sonnerait en permanence sur
     * tout le parc.
     *
     * @param  array<int, array<string, mixed>>  $retenus
     */
    private function base(array $retenus): ?float
    {
        if (count($retenus) < self::INTERVALLES_MIN_ALERTE) {
            return null;
        }

        return round($this->mediane(array_column(array_slice($retenus, 0, -2), 'l_100km')), 1);
    }

    /**
     * La consommation est-elle sortie de son regime habituel ?
     *
     * Deux intervalles consecutifs, et non un seul : un ecart isole s'explique
     * aussi bien par un chiffre de compteur mal tape que par un filtre encrasse,
     * et une alerte qu'on a vue se tromper une fois ne se lit plus jamais.
     *
     * @param  array<int, array<string, mixed>>  $retenus
     */
    private function anormal(array $retenus, ?float $base): bool
    {
        if ($base === null || $base <= 0) {
            return false;
        }

        // Une serie trop dispersee ne porte pas de verdict : voir
        // DISPERSION_MAX. Le controle vient AVANT la comparaison aux seuils,
        // parce que c'est la base elle-meme qui n'est pas fiable.
        if ($this->dispersion($retenus) > self::DISPERSION_MAX) {
            return false;
        }

        foreach (array_slice($retenus, -2) as $intervalle) {
            if ($intervalle['l_100km'] <= $base * self::SEUIL_RELATIF
                || $intervalle['l_100km'] <= $base + self::SEUIL_ABSOLU) {
                return false;
            }
        }

        return true;
    }

    /**
     * L'ecart absolu median a la mediane, rapporte a la mediane.
     *
     * Robuste la ou l'ecart-type ne l'est pas : sur six valeurs, une seule
     * faute de frappe deplace un ecart-type, pas une mediane d'ecarts. Rend 0
     * pour une serie dont la majorite est groupee, quelle que soit l'ampleur
     * de ce qui depasse — c'est voulu : une derive est faite pour etre vue.
     */
    private function dispersion(array $retenus): float
    {
        $taux = array_column($retenus, 'l_100km');

        if (count($taux) < 2) {
            return 0.0;
        }

        $mediane = $this->mediane($taux);

        if ($mediane <= 0) {
            return 0.0;
        }

        $ecarts = array_map(fn ($t) => abs((float) $t - $mediane), $taux);

        return $this->mediane($ecarts) / $mediane;
    }

    /**
     * Un intervalle, et le motif qui l'ecarte le cas echeant.
     *
     * La distance vaut toujours au moins DISTANCE_MIN_KM : en dessous, chaine()
     * a prolonge au lieu de fermer. Il n'y a donc plus ni motif 'trop_court' ni
     * division par zero a redouter.
     *
     * @return array{borne_debut:int,borne_fin:int,distance_km:int,litres:float,l_100km:float,montant_fcfa:?int,retenu:bool,motif_ecart:?string}
     */
    private function intervalle(FuelFill $debut, FuelFill $fin, float $litres, ?int $montant): array
    {
        $distance = (int) $fin->mileage_km - (int) $debut->mileage_km;

        // Arrondi AVANT d'etre juge, et non apres : le motif se decide sur le
        // chiffre que l'ecran montre. 29,6 L sur 1 000 km font 2,96, publies
        // 3,0 — et l'ecran affichait « 3,0 l/100 km, ecarte, invraisemblable »
        // a cote d'une regle annoncee comme « retenu a partir de 3,0 ». Il se
        // contredisait sur la meme ligne.
        $l100 = round($litres * 100 / $distance, 1);

        $motif = match (true) {
            $distance > self::DISTANCE_MAX_KM => 'trop_long',
            $l100 < self::L100_MIN,
            $l100 > self::L100_MAX            => 'invraisemblable',
            default                           => null,
        };

        return [
            'borne_debut'  => (int) $debut->id,
            'borne_fin'    => (int) $fin->id,
            'distance_km'  => $distance,
            'litres'       => round($litres, 2),
            'l_100km'      => $l100,
            'montant_fcfa' => $montant,
            'retenu'       => $motif === null,
            'motif_ecart'  => $motif,
        ];
    }

    /**
     * Les passages qui portent le calcul : ceux de la serie la plus recente,
     * dans l'ordre du compteur.
     *
     * Le tri est refait ici plutot que confie a la seule relation : le service
     * est appele avec des vehicules charges de plusieurs facons — index, show,
     * tache planifiee — et un intervalle calcule sur une collection arrivee dans
     * l'ordre des identifiants serait faux sans rien lever. L'identifiant ne
     * departage que les compteurs egaux, pour que deux executions rendent la
     * meme chose.
     *
     * @return array<int, FuelFill>
     */
    private function passages(OwnedVehicle $vehicule): array
    {
        $series = $this->series($vehicule);

        return $series === [] ? [] : $series[count($series) - 1];
    }

    /**
     * Les passages decoupes en series, une par compteur : la vie d'avant le
     * remplacement, puis celle d'apres. Chaque serie est rendue dans l'ordre du
     * compteur, et la derniere est la plus recente.
     *
     * LA REGLE DE DETECTION, et c'est elle qui fait tout le travail : un passage
     * cree APRES les autres dont le compteur est strictement inferieur au
     * MINIMUM de tous les compteurs deja vus ne peut pas s'inserer dans la serie
     * existante — aucun kilometrage de cette serie ne lui est anterieur. C'est
     * un compteur neuf. Un ticket retrouve et saisi en retard, lui, tombe DANS
     * la plage deja connue : il s'insere, et il ne rompt rien. La distinction
     * est mecanique, pas une supposition sur l'intention du proprietaire.
     *
     * Sans ce decoupage, le tri par kilometrage seul renvoyait toute l'histoire
     * d'avant EN FIN de chaine : 7 pleins a 11,0 l/100 depuis 200 000 km suivis
     * de 4 pleins reels a 7,0 depuis 1 800 km faisaient afficher 11,0 et
     * « dernier passage 203 600 km » a un proprietaire dont le compteur lit
     * 1 800 — et plus aucune derive future n'aurait pu declencher l'alerte,
     * puisque les deux derniers intervalles de la chaine etaient a jamais ceux
     * du vieux compteur.
     *
     * @return array<int, array<int, FuelFill>>
     */
    private function series(OwnedVehicle $vehicule): array
    {
        $series = [];
        $courante = [];
        $minimum = null;

        // Par created_at, parce que la regle porte sur l'ordre de SAISIE : c'est
        // le seul ordre ou « un compteur plus bas que tout ce qui precede »
        // veut dire quelque chose. L'identifiant departage les saisies de la
        // meme seconde, frequentes quand on rattrape plusieurs tickets.
        foreach ($vehicule->fuelFills->sortBy([['created_at', 'asc'], ['id', 'asc']]) as $passage) {
            $km = (int) $passage->mileage_km;

            if ($minimum !== null && $km < $minimum) {
                $series[] = $courante;
                $courante = [];
                $minimum = null;
            }

            $courante[] = $passage;
            $minimum = $minimum === null ? $km : min($minimum, $km);
        }

        if ($courante !== []) {
            $series[] = $courante;
        }

        return array_map(
            fn (array $serie) => collect($serie)
                ->sortBy([['mileage_km', 'asc'], ['id', 'asc']])
                ->values()
                ->all(),
            $series,
        );
    }
}
