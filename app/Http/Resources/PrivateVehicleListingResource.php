<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PrivateVehicleListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = (int) $request->user()?->id === (int) $this->user_id
            || ($request->user()?->isStaff() && $request->user()->role->canManageCatalog());

        // An allowlist prevents new private garage/moderation fields leaking into the public API.
        return [
            'id' => $this->id, 'seller_type' => 'private', 'title' => $this->title,
            'brand_id' => $this->brand_id, 'vehicle_model_id' => $this->vehicle_model_id,
            'model_libre' => $this->model_libre, 'trim_id' => $this->trim_id,
            'engine_type_id' => $this->engine_type_id, 'drivetrain_id' => $this->drivetrain_id,
            'color_id' => $this->color_id, 'vehicle_identity' => $this->vehicle_identity,
            'year' => $this->year, 'mileage_km' => $this->mileage_km,
            'price' => $this->price, 'currency' => $this->currency, 'city' => $this->city,
            'fuel_type' => $this->fuel_type, 'transmission' => $this->transmission,
            'description' => $this->description, 'seller_name' => $this->seller_name,
            // LE NUMERO N'EST PAS DANS LA LISTE, et ce n'est pas une precaution
            // de style.
            //
            // `GET private-listings` ne porte ni `firebase` ni `auth:sanctum`
            // (routes/private-sales.php) et rend vingt fiches par page sous un
            // plafond de 120 requetes par minute. Avec le numero dans chaque
            // fiche, c'etait un annuaire de particuliers burkinabe — nom, ville,
            // telephone — moissonnable sans compte, a raison d'un seul appel
            // pour vingt vendeurs.
            //
            // LA CONDITION, ET POURQUOI CELLE-LA. Pas `$request->user() !== null`
            // : aucun intergiciel ne resout de compte sur ces routes, et les
            // boutons « Appeler » et « WhatsApp » disparaitraient pour TOUS les
            // acheteurs. C'est la presence du parametre de route qui separe la
            // liste de la fiche : l'index n'a pas de `{listing}`, la fiche et la
            // page de partage en ont un. L'acheteur qui ouvre une annonce a donc
            // le numero, celui qui parcourt la liste ne l'a pas.
            //
            // CE QUE CELA NE FERME PAS, et il faut le savoir : la fiche reste
            // publique, et les identifiants sont sequentiels. Un moissonneur
            // obstine passe d'un appel pour vingt vendeurs a un appel par
            // vendeur. C'est un cout multiplie par vingt, pas une porte fermee.
            // La porte ne se ferme qu'en posant `firebase` sur la lecture
            // publique — une decision de produit, pas une correction : voir
            // docs/ventes-particuliers.md, qui soutient aujourd'hui que le
            // telephone verifie est public.
            'phone' => $this->when(
                $private || $request->route('listing') !== null,
                $this->verified_phone,
            ),
            'whatsapp_enabled' => $this->whatsapp_enabled,
            'phone_verified' => $this->phone_verified_at !== null,
            'published_at' => $this->published_at?->toIso8601String(),
            'share_url' => rtrim(config('app.url'), '/').'/annonces/'.$this->id,
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->map(fn ($photo) => [
                'id' => $photo->id, 'url' => Storage::disk($photo->disk)->url($photo->path),
            ])->values()),
            $this->mergeWhen($private, [
                'status' => $this->status, 'review_reason' => $this->review_reason,
                'revision' => $this->revision,
                'owned_vehicle_id' => $this->owned_vehicle_id,
                // Le numero SAISI, distinct du `phone` plus haut, qui est le
                // numero VERIFIE. Deux lecteurs en ont besoin et aucun n'est le
                // public : le vendeur, pour relire et corriger ce qu'il a tape,
                // et le moderateur, pour savoir quel numero composer — avant
                // qu'aucune verification n'existe, donc quand `phone` est vide.
                'phone_claimed' => $this->phone,
                'phone_verified_at' => $this->phone_verified_at?->toIso8601String(),
            ]),
        ];
    }
}
