<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FuelFillResource extends JsonResource
{
    /**
     * L'intervalle qui FINIT sur ce passage, tel que SuiviConsommation l'a
     * calcule — ou null quand il n'y en a pas.
     *
     * Injecte par le controleur plutot que calcule ici, et ce n'est pas un
     * detail d'implementation : l'intervalle d'un passage depend de TOUS les
     * autres passages du vehicule. Une ressource qui irait les rechercher
     * referait la chaine entiere pour chaque ligne d'une page de vingt, et
     * surtout elle recopierait les seuils — 50 km, 3,0 l/100 — a cote de ceux
     * du service. Deux copies d'un seuil finissent toujours par diverger, et
     * l'utilisateur voit alors un intervalle « retenu » dans la liste qui ne
     * compte pas dans sa moyenne.
     *
     * @var array<string, mixed>|null
     */
    private ?array $intervalle = null;

    /**
     * Combien d'intervalles RETENUS ce passage borne — 0, 1 ou 2.
     *
     * Injecte par le controleur pour la meme raison que l'intervalle : la
     * reponse depend de toute la chaine, pas de la ligne.
     */
    private int $bornesRetenues = 0;

    /** @param  array<string, mixed>|null  $intervalle */
    public function avecIntervalle(?array $intervalle, int $bornesRetenues = 0): static
    {
        $this->intervalle = $intervalle;
        $this->bornesRetenues = $bornesRetenues;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'      => $this->id,
            'is_full' => (bool) $this->is_full,

            // Une date nue, sans heure ni fuseau : le passage est saisi a
            // posteriori et personne ne sait a quelle minute il a eu lieu.
            // Pretendre le contraire ferait basculer le passage d'un jour a
            // l'autre selon le fuseau du telephone.
            'filled_at'  => $this->filled_at?->toDateString(),
            'mileage_km' => $this->mileage_km,

            // litres et price_per_litre sortent en CHAINES : ce sont des
            // colonnes decimal, et Laravel les serialise ainsi. On ne les
            // convertit pas ici — la fixture du contrat capture la vraie sortie,
            // et le modele Dart les lit avec un parseur tolerant. Les forcer en
            // float cote serveur masquerait la difference ici pour la laisser
            // exploser ailleurs.
            'litres'          => $this->litres,
            'amount_fcfa'     => $this->amount_fcfa,
            'price_per_litre' => $this->price_per_litre,
            'note'            => $this->note,
            'created_at'      => $this->created_at?->toIso8601String(),

            // Le chiffre de l'intervalle que ce passage a FERME. Null sur un
            // demi-plein — il ne ferme rien — et null sur le premier plein de
            // la serie, qui ouvre la chaine sans rien clore : avant lui, le
            // niveau du reservoir est inconnu.
            'l_100km'     => $this->intervalle['l_100km'] ?? null,
            'distance_km' => $this->intervalle['distance_km'] ?? null,

            // false en l'absence d'intervalle, et non null : la question posee
            // est « ce passage pese-t-il sur la moyenne affichee ? », et la
            // reponse pour un demi-plein est non. Un motif, en revanche, reste
            // null : il n'y a rien a expliquer a l'ecran quand rien n'a ete
            // mesure.
            'retenu'      => (bool) ($this->intervalle['retenu'] ?? false),
            'motif_ecart' => $this->intervalle['motif_ecart'] ?? null,

            // Ce que la suppression de CE passage detruirait : 0, 1 ou 2
            // mesures retenues. 'retenu' ne suffit pas a repondre — il ne parle
            // que de l'intervalle qui FINIT ici, et un plein du milieu en ouvre
            // un second qui disparaitrait avec lui.
            //
            // Supprimer un plein CORRECT ne retire pas une mesure, il en fusionne
            // deux, et la fusion ment. Mesure : 90 000 / 40 L, 90 600 / 48 L,
            // 91 200 / 50 L affichent 8,2 l/100 km ; en effacant celui du milieu
            // il ne reste qu'un intervalle de 1 200 km pour 50 L, soit 4,2 —
            // retenu, plausible, et faux de moitie. L'ecran doit pouvoir
            // prevenir avant, et il lui faut ce nombre pour rediger sa
            // confirmation.
            'bornes_intervalles' => $this->bornesRetenues,
        ];
    }
}
