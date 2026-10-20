<?php

namespace App\Http\Resources;

use App\Support\PhotoPartage;
use App\Support\VersionPartage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;


class AccessoryResource extends JsonResource
{
    /**
     * Combien de vehicules le resume porte au plus.
     *
     * Trois, comme PartResource. Ce n'est pas la carte qui l'impose — elle
     * tronque a deux lignes quoi qu'il arrive — mais le poids de la reponse.
     */
    private const VEHICULES_EN_VIGNETTE = 3;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'sku'         => $this->sku,
            'name'        => $this->name,
            'description' => $this->description,

            'category' => [
                'value' => $this->category->value,
                'label' => $this->category->label(),
            ],

            'manufacturer'    => $this->whenLoaded('manufacturer', fn () => $this->manufacturer?->name),
            'manufacturer_id' => $this->manufacturer_id,
            'weight_kg'       => $this->weight_kg,
            'dimensions'      => $this->dimensions,
            'warranty_months' => $this->warranty_months,

            'pricing' => [
                'selling_price'       => $this->selling_price,
                'currency'            => $this->currency,
                'vat_rate'            => $this->vat_rate,
                'price_including_vat' => $this->price_including_vat,
            ],

            'stock' => [
                'is_available'    => $this->is_available,
                'quantity'        => $this->stock_quantity,
                'alert_threshold' => $this->stock_alert_threshold,
                'is_low'          => $this->isLowStock(),
                'storage_location'=> $this->storage_location,
                'location_id'     => $this->location_id,
                'location'        => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'   => $this->location->id,
                    'name' => $this->location->name,
                    'city' => $this->location->city,
                ] : null),
            ],

            // Meme filtre que VehicleResource, et pour la meme raison : une
            // entree dont le chemin est inexploitable ('' ou '0') sort une URL
            // NON VIDE qui pointe un 404, que le filtre `u.isNotEmpty` des
            // modeles Dart laisse donc passer. Le predicat et le `values()`
            // sont expliques la-bas.
            'media' => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => PhotoPartage::cheminExploitable($m->path))
                ->map(fn ($m) => [
                    'id'       => $m->id,
                    'url'      => $m->url,
                    'is_cover' => $m->is_cover,
                    'position' => $m->position,
                ])
                ->values()),
            'is_active' => $this->is_active,

            // Le RESUME, pour la carte du catalogue : « Toyota Corolla »,
            // « Hyundai Tucson ». La carte n'a pas la place d'une liste de
            // compatibilites, mais savoir a quoi un accessoire se destine est
            // ce qui evite d'ouvrir une fiche pour rien.
            //
            // Jumeau de PartResource::resumeCompatibilite, et la ressemblance
            // est voulue : les deux cartes affichent la meme ligne, et deux
            // formes differentes se seraient mises a diverger.
            'compatibility' => $this->whenLoaded(
                'fitments',
                fn () => $this->resumeCompatibilite(),
            ),

            // Compatibilités détaillées : chargées sur le détail et, depuis la
            // ligne de compatibilite des cartes, sur la liste aussi.
            // Voir PartResource : les identifiants sont ce qui permet a
            // l'ecran d'edition de relire la liste et de la renvoyer sans
            // perte. Un accessoire n'a ni code moteur ni position.
            'fitments' => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'id'               => $f->id,
                'vehicle_model_id' => $f->vehicle_model_id,
                'vehicle_model'    => $f->vehicleModel?->full_name ?? null,
                'brand_id'         => $f->vehicleModel?->brand_id,
                'trim_id'          => $f->trim_id,
                'trim'             => $f->trim?->name,
                'engine_type_id'   => $f->engine_type_id,
                'drivetrain_id'    => $f->drivetrain_id,
                'year_from'        => $f->year_from,
                'year_to'          => $f->year_to,
                'notes'            => $f->notes,
                'source'           => $f->source?->value,
            ])),
            'partners' => PartnerResource::collection($this->whenLoaded('partners')),

            // Le marqueur de l'adresse de partage : « /a/7?m=t3uwow ». Meme
            // cle et meme raison que VehicleResource, ou tout est explique.
            //
            // Sans whenLoaded, et deliberement : `updated_at` est une colonne,
            // pas une relation. Elle couvre les deux chemins de l'accessoire,
            // catalogue et fiche.
            //
            // A noter pour ce type-la : `toggleAvailability` ecrit sur le
            // modele, donc deplace `updated_at`, donc change le marqueur. Un
            // accessoire retire du catalogue puis reapprovisionne se repartage
            // ainsi a une adresse neuve — sa page etant redevenue 200, la
            // vignette se reconstruit au lieu de ressortir celle d'avant la
            // rupture.
            'share_version' => VersionPartage::pour($this->updated_at),
        ];
    }

    /**
     * Les vehicules auxquels cet accessoire se destine, pour la carte.
     *
     * Copie fidele de PartResource::resumeCompatibilite, meme plafond compris.
     * Les deux cartes du catalogue affichent la meme ligne : deux resumes
     * differents se seraient mis a diverger, et c'est l'ecran qui l'aurait
     * montre.
     *
     * Le plafond ne sert pas la mise en page — la carte tronque elle-meme a
     * deux lignes — mais le POIDS de la liste : une reponse de catalogue porte
     * vingt articles, et un accessoire universel peut declarer des dizaines de
     * modeles dont la carte ne montrera jamais le troisieme.
     *
     * @return array{count: int, vehicles: list<string>}
     */
    private function resumeCompatibilite(): array
    {
        $vehicules = $this->fitments
            // Le modele seul, sans la marque — voir PartResource pour la
            // mesure qui l'a decide. Les deux cartes affichent la meme ligne ;
            // deux formes differentes se seraient mises a diverger.
            ->map(fn ($f) => trim($f->vehicleModel?->name ?? ''))
            ->filter()
            ->unique()
            ->values();

        return [
            'count' => $vehicules->count(),
            'vehicles' => $vehicules->take(self::VEHICULES_EN_VIGNETTE)->all(),
        ];
    }
}
