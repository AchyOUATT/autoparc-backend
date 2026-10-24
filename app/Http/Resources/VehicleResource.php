<?php

namespace App\Http\Resources;

use App\Support\PartnerListingPresentation;
use App\Support\PartnerMedia;
use App\Support\PhotoPartage;
use App\Support\VersionPartage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $private = PartnerListingPresentation::canSeePrivateData($this->resource, $request);

        $data = [
            'id'           => $this->id,
            'brand_id' => $this->brand_id,
            'vehicle_model_id' => $this->vehicle_model_id,
            'trim_id' => $this->trim_id,
            'engine_type_id' => $this->engine_type_id,
            'drivetrain_id' => $this->drivetrain_id,
            'color_id' => $this->color_id,
            'reference'    => $this->reference,
            'vin'          => $this->vin,
            'designation'  => $this->designation,
            'vehicle_type' => $this->vehicle_type ?? 'passenger',
            'body_style'   => $this->body_style,

            'registration_status' => [
                'value' => $this->registration_status?->value,
                'label' => $this->registration_status?->label(),
            ],

            'identity' => [
                'brand'       => $this->whenLoaded('brand', fn () => $this->brand->name),
                // Le slug identifie le fichier de logo cote application
                // (assets/brands/<slug>.svg). Le nom seul ne suffit pas :
                // « Mercedes-Benz » ou « Citroen » ne donnent pas un nom de
                // fichier fiable une fois accentues ou espaces.
                'brand_slug'      => $this->whenLoaded('brand', fn () => $this->brand->slug),
                'brand_logo_path' => $this->whenLoaded('brand', fn () => $this->brand->logo_path),
                'model'       => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->name),
                'generation'  => $this->whenLoaded('vehicleModel', fn () => $this->vehicleModel->generation),
                'trim'        => $this->whenLoaded('trim', fn () => $this->trim?->name),
                'engine_type' => $this->whenLoaded('engineType', fn () => $this->engineType->label),
                'drivetrain'  => $this->whenLoaded('drivetrain', fn () => $this->drivetrain?->code),
                'color'       => $this->whenLoaded('color', fn () => $this->color?->name),
                // Code hexadecimal et finition : permettent d'afficher une
                // pastille de la teinte reelle a cote du nom. « Bordeaux » ou
                // « Gris titanium » ne se devinent pas.
                'color_hex'    => $this->whenLoaded('color', fn () => $this->color?->hex_code),
                'color_finish' => $this->whenLoaded('color', fn () => $this->color?->finish),
                'year'        => $this->manufacturing_year,
            ],

            'technical' => [
                'transmission'           => $this->transmission?->value,
                'gear_count'             => $this->gear_count,
                'engine_displacement_cc' => $this->engine_displacement_cc,
                'engine_code'            => $this->engine_code,
                'power_hp'               => $this->power_hp,
                'power_kw'               => $this->power_kw,
                'torque_nm'              => $this->torque_nm,
                'cylinders'              => $this->cylinders,
                'seats'                  => $this->seats,
                'doors'                  => $this->doors,
                'mileage_km'             => $this->mileage_km,
            ],

            'consumption' => [
                'urban_l_100km'       => $this->consumption_urban,
                'extra_urban_l_100km' => $this->consumption_extra_urban,
                'combined_l_100km'    => $this->consumption_combined,
                'electric_kwh_100km'  => $this->electric_consumption_kwh,
                'battery_kwh'         => $this->battery_capacity_kwh,
                'electric_range_km'   => $this->electric_range_km,
                'co2_g_km'            => $this->co2_g_km,
                'fuel_tank_liters'    => $this->fuel_tank_liters,

                // Fourchette officielle du modele, sur la fiche detaillee
                // uniquement. Une annonce connait son modele et son
                // millesime, rarement sa motorisation : annoncer une valeur
                // unique reviendrait a choisir un moteur au hasard pour le
                // compte de l'acheteur.
                'official'            => $this->resource->cotesOfficielles,
            ],

            'commercial' => [
                'condition'          => $this->condition?->value,
                'status'             => $this->status?->value,
                'status_label'       => $this->status?->label(),
                'availability'       => $this->availability?->value,
                'sale_price'         => $this->sale_price,
                'rental_daily_rate'  => $this->rental_daily_rate,
                'rental_weekly_rate' => $this->rental_weekly_rate,
                'rental_monthly_rate' => $this->rental_monthly_rate,
                'rental_deposit'     => $this->rental_deposit,
                'currency'           => $this->currency,
                'price_negotiable'   => $this->price_negotiable,
                // Mise en avant commerciale. Null = pas de promotion. Le
                // libelle accompagne la valeur pour que l'application n'ait
                // pas a dupliquer la traduction des trois types.
                'deal_type'          => $this->deal_type?->value,
                'deal_label'         => $this->deal_type?->label(),
                'site'               => $this->site,
                'location'           => $this->whenLoaded('location', fn () => $this->location ? [
                    'id'      => $this->location->id,
                    'name'    => $this->location->name,
                    'city'    => $this->location->city,
                    'address' => $this->location->address,
                    'phone'   => $this->location->phone,
                ] : null),
                'location_id'        => $this->location_id,
                'partner'            => $this->whenLoaded('partner', fn () => $this->partner ? [
                    'id'           => $this->partner->id,
                    'company_name' => $this->partner->company_name,
                    'contact_name' => $this->partner->contact_name,
                    'phone'        => $this->partner->phone,
                    'whatsapp'     => $this->partner->whatsapp,
                ] : null),
                'partner_id'         => $this->partner_id,
            ],

            // Bloc present uniquement pour un vehicule non immatricule
            'import' => $this->whenLoaded('importDetail', fn () => $this->importDetail ? [
                'origin_country'        => $this->importDetail->originCountry?->name,
                'origin_country_id'     => $this->importDetail->origin_country_id,
                'supplier_name'         => $this->importDetail->supplier_name,
                'port_of_loading'       => $this->importDetail->port_of_loading,
                'port_of_entry'         => $this->importDetail->port_of_entry,
                'bill_of_lading_no'     => $this->importDetail->bill_of_lading_no,
                'container_no'          => $this->importDetail->container_no,
                'shipping_date'         => $this->importDetail->shipping_date?->toDateString(),
                'arrival_date'          => $this->importDetail->arrival_date?->toDateString(),
                'customs_cleared'       => $this->importDetail->customs_cleared,
                'customs_declaration_no' => $this->importDetail->customs_declaration_no,
                'customs_duty_amount'   => $this->importDetail->customs_duty_amount,
                'freight_cost'          => $this->importDetail->freight_cost,
                'steering_side'         => $this->importDetail->steering_side,
                'odometer_at_import_km' => $this->importDetail->odometer_at_import_km,
                'foreign_plate'         => $this->importDetail->foreign_plate,
            ] : null),

            // Bloc present uniquement pour un vehicule deja immatricule
            'registration' => $this->whenLoaded('registrationDetail', fn () => $this->registrationDetail ? [
                'plate_number'            => $this->registrationDetail->plate_number,
                'registration_country'    => $this->registrationDetail->registrationCountry?->name,
                'certificate_no'          => $this->registrationDetail->registration_certificate_no,
                'first_registration_date' => $this->registrationDetail->first_registration_date?->toDateString(),
                'mileage_km'              => $this->registrationDetail->mileage_km,
                'previous_owners_count'   => $this->registrationDetail->previous_owners_count,
                'technical_inspection_expiry' => $this->registrationDetail->technical_inspection_expiry?->toDateString(),
                'inspection_expired'      => $this->registrationDetail->isInspectionExpired(),
                'insurance_expiry'        => $this->registrationDetail->insurance_expiry?->toDateString(),
                'service_book_available'  => $this->registrationDetail->service_book_available,
                'has_accident_history'    => $this->registrationDetail->has_accident_history,
            ] : null),

            'faults'       => VehicleFaultResource::collection($this->whenLoaded('faults')),
            'faults_count' => $this->whenCounted('faults'),
            'fault_score'  => $this->when($this->relationLoaded('faults'), fn () => $this->fault_score),

            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id'       => $f->id,
                'name'     => $f->name,
                'category' => $f->category,
            ])->values()),

            // Derives des equipements : absents de la liste du catalogue, qui ne
            // les charge pas — et n'en a pas besoin, le badge vit sur la fiche.
            'optional_feature_count' => $this->whenLoaded('features', fn () => $this->optional_feature_count),
            'is_full_option'         => $this->whenLoaded('features', fn () => $this->is_full_option),

            // Les media dont le CHEMIN est inexploitable sont ecartes ici, et
            // non laisses a l'application.
            //
            // L'application ne recoit que des URL. Mesure sur une galerie dont
            // la premiere ligne porte path='0' et dont la suivante est valable,
            // avant correction :
            //
            //     "media":[{"id":1,"url":"http://localhost:8000/storage/0",...},
            //              {"id":2,"url":".../storage/vehicles/1/ok.jpg",...}]
            //
            // Les trois modeles Dart filtrent sur `u.isNotEmpty` (voir
            // Part.fromJson) : « .../storage/0 » n'est pas vide, il passe, et
            // `coverUrl` designe un 404. Le filtre cote application ne PEUT pas
            // faire mieux — rien dans une URL ne dit que le fichier derriere
            // est absent. Le seul endroit qui connait le chemin, c'est ici.
            //
            // Le predicat est celui de PhotoPartage::cheminExploitable, et
            // c'est voulu : la page de partage et l'API doivent designer la
            // meme photo. Il rejette la chaine vide et '0' en clair, sans
            // empty() — « 0 » est faux en PHP, et c'est cette conversion
            // implicite qui a fabrique le defaut a l'origine.
            //
            // `values()` n'est pas cosmetique : apres un filter(), les cles de
            // la collection ne sont plus 0..n et json_encode rend un OBJET la
            // ou l'application attend un TABLEAU. `json['media'] as List?`
            // leverait alors cote Dart.
            'media'    => $this->whenLoaded('media', fn () => $this->media
                ->filter(fn ($m) => PhotoPartage::cheminExploitable($m->path))
                ->filter(fn ($m) => $private || PartnerMedia::allows($m, $this->resource))
                ->map(fn ($m) => [
                    'id'         => $m->id,
                    'url'        => $m->url,
                    'collection' => $m->collection,
                    'is_cover'   => $m->is_cover,
                ])
                ->values()),

            'description'  => $this->description,
            'published_at' => $this->published_at?->toIso8601String(),
            'created_at'   => $this->created_at?->toIso8601String(),

            // Le marqueur que l'application colle dans l'adresse de partage :
            // « /v/86?m=t3uwow ». Voir VersionPartage pour le format et pour
            // la mesure qui l'a decide.
            //
            // POURQUOI L'APPLICATION EN A BESOIN, alors que le serveur pose
            // deja le marqueur dans og:url. Facebook identifie un objet par
            // og:url, donc le serveur lui suffirait. WhatsApp non : il cle sa
            // vignette sur l'adresse qu'il a APPELEE. Tant que l'application
            // partage « /v/86 », WhatsApp ressort la vignette construite au
            // premier partage, quel que soit le contenu de la page. C'est le
            // canal principal de cette boutique, d'ou cette cle.
            //
            // `updated_at` N'EST EXPOSE NULLE PART AILLEURS, ni ici ni dans les
            // modeles Dart, et ce n'est pas lui qu'on expose : une chaine
            // opaque que l'application recopie telle quelle, sans la lire ni
            // la comparer. Exposer la date aurait demande a l'application de
            // choisir un format — donc a deux depots de s'accorder sur un
            // format, et de diverger un jour sans que rien ne le dise.
            //
            // SANS whenLoaded, ET C'EST LE POINT DE VIGILANCE. Ce depot a livre
            // CINQ fois le defaut `whenLoaded` qui fait DISPARAITRE une cle du
            // JSON quand la relation n'est pas chargee, et l'application lit
            // alors un nul indistinguable d'une absence. Ici il n'y a aucune
            // relation : `updated_at` est une COLONNE, toujours selectionnee.
            // Verifie, parce que c'est la seule facon de la perdre : aucun
            // chemin de ces trois ressources ne restreint les colonnes. Les
            // trois ->select() du depot portent ailleurs — PartCategory pour
            // son arbre, un agregat de tableau de bord, et la commande console
            // BackfillFitments. La cle est donc presente sur la liste, sur la
            // fiche et sur la recherche de compatibilite, et un test l'exige
            // sur chacun de ces chemins (VersionDePartageTest).
            //
            // Null reste possible — `timestamps()` rend la colonne nullable, et
            // une ligne inseree en SQL brut peut la laisser vide. Le contrat
            // l'admet : l'application retombe alors sur l'adresse nue.
            'share_version' => VersionPartage::pour($this->updated_at),
        ];

        return PartnerListingPresentation::present($data, $this->resource, $request, 'vehicle');
    }
}
