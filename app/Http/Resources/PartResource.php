<?php

namespace App\Http\Resources;

use App\Support\PhotoPartage;
use App\Support\VersionPartage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
// PartnerResource est dans le même namespace, résolu automatiquement par Laravel

class PartResource extends JsonResource
{
    /**
     * Combien de vehicules nommer sur une vignette avant de compter.
     *
     * Trois tiennent sur deux lignes a la largeur d'une carte de la grille,
     * echelle de texte agrandie comprise. Au-dela, le nom de la piece se fait
     * chasser de la carte.
     */
    private const VEHICULES_EN_VIGNETTE = 3;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'sku'         => $this->sku,
            'name'        => $this->name,
            'description' => $this->description,
            'compatibility_assessment' => $this->when(
                $this->resource->getAttribute('compatibility_assessment') !== null,
                fn () => $this->resource->getAttribute('compatibility_assessment'),
            ),
            'type'        => $this->type->value,
            'condition'   => $this->condition->value,
            'category'        => $this->whenLoaded('category', fn () => [
                'id'   => $this->category->id,
                'name' => $this->category->name,
            ]),
            'category_id'     => $this->part_category_id,
            'manufacturer'    => $this->whenLoaded('manufacturer', fn () => $this->manufacturer?->name),
            'manufacturer_id' => $this->manufacturer_id,
            'manufacturer_reference' => $this->manufacturer_reference,
            'oem_numbers'  => OemNumberResource::collection($this->whenLoaded('oemNumbers')),
            'donor_vehicle' => $this->whenLoaded('donorVehicle', fn () => $this->donorVehicle?->reference),

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
                'storage_location' => $this->storage_location,
                'location'        => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'   => $this->location->id,
                    'name' => $this->location->name,
                    'city' => $this->location->city,
                ] : null),
                'location_id'     => $this->location_id,
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
            'warranty_months' => $this->warranty_months,
            'weight_kg'       => $this->weight_kg,
            'dimensions'      => $this->dimensions,
            'is_active'       => $this->is_active,
            // Les identifiants comptent autant que les libelles : l'ecran
            // d'edition relit cette liste et la renvoie telle quelle. Rendre
            // « Toyota Corolla » et « 1.4 D-4D » sans les identifiants rendait
            // l'aller-retour impossible — l'ecriture, elle, attend trim_id et
            // engine_type_id. Toute cle absente ici est une donnee perdue au
            // premier enregistrement.
            // Resume court, pour la vignette de la liste.
            //
            // La liste complete des compatibilites est trop lourde pour une
            // grille de vingt pieces, et illisible sur une carte de 190 points
            // de large. On rend donc les premiers vehicules et le compte total,
            // de quoi se faire une idee sans ouvrir la fiche.
            //
            // Volontairement sans annees ni code moteur : c'est une indication,
            // pas une promesse. La reponse ferme se lit sur la fiche, ou dans
            // la confrontation au garage du client.
            'compatibility'   => $this->whenLoaded('fitments', fn () => $this->resumeCompatibilite()),

            'fitments'        => $this->whenLoaded('fitments', fn () => $this->fitments->map(fn ($f) => [
                'id'               => $f->id,
                'vehicle_model_id' => $f->vehicle_model_id,
                'vehicle_model'    => $f->vehicleModel?->full_name,
                'brand_id'         => $f->vehicleModel?->brand_id,
                'trim_id'          => $f->trim_id,
                'trim'             => $f->trim?->name,
                'engine_type_id'   => $f->engine_type_id,
                'engine_type'      => $f->engineType?->label,
                'drivetrain_id'    => $f->drivetrain_id,
                'engine_code'      => $f->engine_code,
                'year_from'        => $f->year_from,
                'year_to'          => $f->year_to,
                'position'         => $f->position,
                'notes'            => $f->notes,
                'source'           => $f->source?->value,
            ])),
            'partners'        => PartnerResource::collection($this->whenLoaded('partners')),

            // Le marqueur de l'adresse de partage : « /p/12?m=t3uwow ». Meme
            // cle et meme raison que VehicleResource, ou tout est explique —
            // WhatsApp cle sa vignette sur l'adresse APPELEE, donc une piece
            // modifiee doit se partager a une adresse differente.
            //
            // Sans whenLoaded, et deliberement : `updated_at` est une colonne,
            // pas une relation. La cle doit etre presente aussi bien sur la
            // liste du catalogue que sur la fiche ET sur la recherche de
            // compatibilite — c'est la piece qui a le plus de chemins des
            // trois, et c'est par un de ces chemins qu'une cle manquante
            // passerait inapercue.
            'share_version'   => VersionPartage::pour($this->updated_at),
        ];
    }

    /**
     * Les vehicules compatibles, en court, pour une vignette de liste.
     *
     * La liste complete est trop lourde pour une grille de vingt pieces et
     * illisible sur une carte de 190 points de large. On rend donc les
     * premiers vehicules distincts et le compte total.
     *
     * Deux choix a expliquer.
     *
     * Les doublons sont ecartes : un meme modele apparait plusieurs fois des
     * qu'il a recu plusieurs moteurs — le Hilux AN10 porte le 1KD diesel et le
     * 1GR essence sur deux references differentes. Afficher « Hilux, Hilux »
     * n'apprendrait rien.
     *
     * Ni annees ni code moteur : c'est une indication destinee a faire ouvrir
     * la fiche, pas une reponse. La reponse ferme demande le moteur du
     * vehicule, et elle se lit sur la fiche ou dans la confrontation au garage.
     *
     * @return array{count: int, vehicles: list<string>}
     */
    private function resumeCompatibilite(): array
    {
        $vehicules = $this->fitments
            // LE MODELE SEUL, SANS LA MARQUE. Le resume composait
            // « Toyota Corolla » ; mesure sur la carte, trois noms de cette
            // longueur ne tenaient que dans 3 cases sur 12, et la ligne
            // affichait surtout « +N ». « Corolla » laisse la place a
            // plusieurs modeles, ce qui est tout l'interet de la ligne.
            //
            // Le risque assume : deux marques peuvent nommer un modele
            // pareillement, et `unique()` les confondrait alors en une seule
            // entree — « +N » comprise. Sur ce referentiel le cas n'existe
            // pas ; s'il apparaissait, c'est ici qu'il faudrait distinguer.
            ->map(fn ($f) => trim($f->vehicleModel?->name ?? ''))
            ->filter()
            ->unique()
            ->values();

        return [
            'count'    => $vehicules->count(),
            'vehicles' => $vehicules->take(self::VEHICULES_EN_VIGNETTE)->all(),
        ];
    }
}
