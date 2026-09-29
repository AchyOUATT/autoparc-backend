<?php

namespace App\Http\Resources;

use App\Enums\CheckReason;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleCheckResource extends JsonResource
{
    /**
     * Les points a reprendre, quand l'appelant les a demandes.
     *
     * Une propriete plutot que additional() : additional() fusionne ses cles a
     * la racine de la reponse, a cote de « data », et non dans la fiche.
     *
     * @var array<int, array<string, mixed>>|null
     */
    private ?array $aReprendre = null;

    /** @param  array<int, array<string, mixed>>  $points */
    public function avecPointsAReprendre(array $points): static
    {
        $this->aReprendre = $points;

        return $this;
    }

    /**
     * Le motif du passage, qui commande le vocabulaire du verdict.
     *
     * Un motif inconnu — une valeur ecrite par une version plus recente de
     * l'application, ou a la main en base — retombe sur le voyage plutot que de
     * faire echouer la lecture d'un historique.
     */
    private function motif(): CheckReason
    {
        return CheckReason::tryFrom((string) $this->reason) ?? CheckReason::Trip;
    }

    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'owned_vehicle_id' => $this->owned_vehicle_id,
            'reason'           => $this->reason,
            'trip_distance_km' => $this->trip_distance_km,
            'mileage_km'       => $this->mileage_km,
            'performed_at'     => $this->performed_at?->toIso8601String(),
            'note'             => $this->note,

            // Le libelle et la phrase viennent de l'enum, et jamais de
            // l'application : une application qui redige elle-meme ce verdict
            // finirait par annoncer que le vehicule est en bon etat, ce que
            // personne n'a constate.
            'verdict' => [
                'value'  => $this->verdict?->value,
                'label'  => $this->verdict?->libelle($this->motif()),
                'detail' => $this->verdict?->detail(
                    $this->blocking_count,
                    $this->watch_count,
                    $this->checked_count,
                    $this->motif(),
                ),
            ],

            'counts' => [
                'blocking' => $this->blocking_count,
                'watch'    => $this->watch_count,
                'checked'  => $this->checked_count,
            ],

            // Absent de l'historique, present sur une fiche : recopier l'aide et
            // la categorie de pieces de chaque point rate pour vingt passages a
            // la fois n'aurait servi a rien.
            'to_fix' => $this->when($this->aReprendre !== null, fn () => $this->aReprendre),

            'answers' => $this->whenLoaded(
                'answers',
                fn () => $this->answers->map(fn ($reponse) => [
                    'item_code' => $reponse->item_code,
                    'title'     => $reponse->title,
                    'category'  => $reponse->category,
                    'severity'  => $reponse->severity,
                    'status'    => $reponse->status,
                    'note'      => $reponse->note,
                ])->values(),
            ),
        ];
    }
}
