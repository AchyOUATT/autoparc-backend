<?php

namespace App\Http\Resources;

use App\Support\PrixEffectif;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** A typed public card; source identifiers keep their native numeric contract. */
class CatalogVehicleListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $source = $this->resource['source'];
        $model = $this->resource['model'];

        // This endpoint is always public, including when called by an owner or staff member.
        $publicRequest = clone $request;
        $publicRequest->setUserResolver(static fn () => null);
        $publicRequest->setRouteResolver(static fn () => null);
        $payload = $source === 'vehicle'
            ? (new VehicleResource($model))->resolve($publicRequest)
            : (new PrivateVehicleListingResource($model))->resolve($publicRequest);
        if ($source === 'vehicle') {
            $payload['commercial']['sale_price'] = PrixEffectif::de($model, 'sale_price');
        }

        return [
            'key' => $source.':'.$model->id,
            'source' => $source,
            'source_id' => (int) $model->id,
            $source === 'vehicle' ? 'vehicle' : 'private_listing' => $payload,
        ];
    }
}
