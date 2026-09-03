<?php

namespace App\Services;

use App\Models\OemNumber;
use App\Models\OwnedVehicle;
use App\Models\Part;
use App\Models\Vehicle;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Moteur de correspondance vehicule <-> piece detachee.
 *
 * Deux chemins de compatibilite sont supportes et fusionnes :
 *   1. Chemin OEM     : vehicule -> (modele, finition, motorisation, annee)
 *                       -> oem_number_fitments -> oem_numbers -> pieces
 *   2. Chemin direct  : vehicule -> part_fitments -> pieces
 */
class CompatibilityService
{
    /** Pieces compatibles avec un vehicule precis du parc. */
    public function partsForVehicle(Vehicle $vehicle, array $filters = []): Builder
    {
        $query = Part::query()
            ->with(['category', 'manufacturer', 'oemNumbers'])
            ->where(function (Builder $q) use ($vehicle) {
                $q->whereHas('fitments', fn ($f) => $this->applyVehicleCriteria($f, $vehicle))
                  ->orWhereHas('oemNumbers.fitments', fn ($f) => $this->applyVehicleCriteria($f, $vehicle));
            });

        if (! empty($filters['category_id'])) {
            $query->where('part_category_id', $filters['category_id']);
        }

        if (! empty($filters['in_stock'])) {
            $query->inStock();
        }

        if (! empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }

        return $query->active();
    }

    /**
     * Pieces compatibles a partir de criteres bruts (recherche catalogue publique,
     * sans vehicule enregistre dans le parc).
     *
     * @param string|null $engineCode  Code moteur optionnel (ex: "1NZ", "K20", "OM651").
     *                                 Quand fourni, filtre les fitments qui l'ont declare ;
     *                                 les fitments sans engine_code restent toujours inclus
     *                                 (un fitment general vaut pour tous les moteurs du modele).
     */
    public function partsForCriteria(
        int $vehicleModelId,
        ?int $year = null,
        ?int $trimId = null,
        ?int $engineTypeId = null,
        ?int $drivetrainId = null,
        ?string $engineCode = null
    ): Builder {
        $apply = function (Builder $f) use ($vehicleModelId, $year, $trimId, $engineTypeId, $drivetrainId, $engineCode) {
            $f->where('vehicle_model_id', $vehicleModelId);

            if ($year !== null) {
                $f->where(fn ($q) => $q->whereNull('year_from')->orWhere('year_from', '<=', $year))
                  ->where(fn ($q) => $q->whereNull('year_to')->orWhere('year_to', '>=', $year));
            }

            // Une compatibilite sans finition/motorisation precisee vaut pour toutes.
            if ($trimId !== null) {
                $f->where(fn ($q) => $q->whereNull('trim_id')->orWhere('trim_id', $trimId));
            }

            if ($engineTypeId !== null) {
                $f->where(fn ($q) => $q->whereNull('engine_type_id')->orWhere('engine_type_id', $engineTypeId));
            }

            if ($drivetrainId !== null) {
                $f->where(fn ($q) => $q->whereNull('drivetrain_id')->orWhere('drivetrain_id', $drivetrainId));
            }

            // Code moteur : un fitment sans engine_code vaut pour tous les moteurs du modele.
            if ($engineCode !== null) {
                $f->where(fn ($q) => $q->whereNull('engine_code')->orWhere('engine_code', $engineCode));
            }
        };

        return Part::query()
            ->with(['category', 'manufacturer', 'oemNumbers'])
            ->where(function (Builder $q) use ($apply) {
                $q->whereHas('fitments', $apply)
                  ->orWhereHas('oemNumbers.fitments', $apply);
            })
            ->active();
    }

    /**
     * Pieces compatibles avec un vehicule personnel du client ("mon garage").
     * Retombe sur la motorisation par defaut de la finition si le client
     * n'a pas precise engine_type_id/drivetrain_id.
     * Utilise engine_code quand disponible (decodage VIN NHTSA) pour plus de precision.
     */
    public function partsForOwnedVehicle(OwnedVehicle $vehicle, array $filters = []): Builder
    {
        $query = $this->partsForCriteria(
            $vehicle->vehicle_model_id,
            $vehicle->manufacturing_year,
            $vehicle->trim_id,
            $vehicle->effective_engine_type_id,
            $vehicle->effective_drivetrain_id,
            $vehicle->effective_engine_code,   // null si absent — pas de filtrage supplementaire
        );

        if (! empty($filters['category_id'])) {
            $query->where('part_category_id', $filters['category_id']);
        }

        if (! empty($filters['in_stock'])) {
            $query->inStock();
        }

        if (! empty($filters['condition'])) {
            $query->where('condition', $filters['condition']);
        }

        return $query;
    }

    /** Vehicules du parc compatibles avec un numero OEM saisi. */
    public function vehiclesForOem(string $oemNumber): Collection
    {
        $normalized = OemNumber::normalize($oemNumber);

        $oem = OemNumber::query()
            ->where('normalized_number', $normalized)
            ->with('fitments')
            ->first();

        if (! $oem) {
            return new Collection();
        }

        // On suit la chaine de remplacement pour ne rien manquer.
        $oem = $oem->currentReference()->loadMissing('fitments');

        $query = Vehicle::query()->with(['brand', 'vehicleModel', 'trim', 'engineType']);

        $query->where(function (Builder $q) use ($oem) {
            foreach ($oem->fitments as $fitment) {
                $q->orWhere(function (Builder $sub) use ($fitment) {
                    $sub->where('vehicle_model_id', $fitment->vehicle_model_id);

                    if ($fitment->trim_id) {
                        $sub->where('trim_id', $fitment->trim_id);
                    }

                    if ($fitment->engine_type_id) {
                        $sub->where('engine_type_id', $fitment->engine_type_id);
                    }

                    if ($fitment->drivetrain_id) {
                        $sub->where('drivetrain_id', $fitment->drivetrain_id);
                    }

                    if ($fitment->engine_code) {
                        $sub->where('engine_code', $fitment->engine_code);
                    }

                    if ($fitment->year_from) {
                        $sub->where('manufacturing_year', '>=', $fitment->year_from);
                    }

                    if ($fitment->year_to) {
                        $sub->where('manufacturing_year', '<=', $fitment->year_to);
                    }
                });
            }

            // Aucune applicabilite declaree : on ne retourne rien.
            if ($oem->fitments->isEmpty()) {
                $q->whereRaw('1 = 0');
            }
        });

        return $query->get();
    }

    /** Modeles couverts par un numero OEM (catalogue, hors parc). */
    public function modelsForOem(string $oemNumber): Collection
    {
        $normalized = OemNumber::normalize($oemNumber);

        $oem = OemNumber::where('normalized_number', $normalized)->first();

        if (! $oem) {
            return new Collection();
        }

        $modelIds = $oem->currentReference()->fitments()->pluck('vehicle_model_id')->unique();

        return VehicleModel::with('brand')->whereIn('id', $modelIds)->get();
    }

    /** Equivalences : autres references OEM couvertes par les memes pieces. */
    public function crossReferences(string $oemNumber): Collection
    {
        $normalized = OemNumber::normalize($oemNumber);

        $oem = OemNumber::where('normalized_number', $normalized)->first();

        if (! $oem) {
            return new Collection();
        }

        $partIds = $oem->parts()->pluck('parts.id');

        return OemNumber::with('brand')
            ->whereHas('parts', fn ($p) => $p->whereIn('parts.id', $partIds))
            ->where('id', '!=', $oem->id)
            ->get();
    }

    /** Applique les criteres du vehicule a une requete de fitment (OEM ou direct). */
    protected function applyVehicleCriteria(Builder $fitment, Vehicle $vehicle): Builder
    {
        $fitment->where('vehicle_model_id', $vehicle->vehicle_model_id);

        $year = $vehicle->manufacturing_year;
        $fitment->where(fn ($q) => $q->whereNull('year_from')->orWhere('year_from', '<=', $year))
                ->where(fn ($q) => $q->whereNull('year_to')->orWhere('year_to', '>=', $year));

        if ($vehicle->trim_id) {
            $fitment->where(fn ($q) => $q->whereNull('trim_id')->orWhere('trim_id', $vehicle->trim_id));
        }

        if ($vehicle->engine_type_id) {
            $fitment->where(fn ($q) => $q->whereNull('engine_type_id')->orWhere('engine_type_id', $vehicle->engine_type_id));
        }

        if ($vehicle->drivetrain_id) {
            $fitment->where(fn ($q) => $q->whereNull('drivetrain_id')->orWhere('drivetrain_id', $vehicle->drivetrain_id));
        }

        if ($vehicle->engine_code) {
            $fitment->where(fn ($q) => $q->whereNull('engine_code')->orWhere('engine_code', $vehicle->engine_code));
        }

        return $fitment;
    }
}
