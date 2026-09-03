<?php

namespace App\Services;

use App\Enums\RentalStatus;
use App\Enums\VehicleStatus;
use App\Models\Rental;
use App\Models\Vehicle;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class RentalService
{
    public function __construct(protected ReferenceGenerator $references)
    {
    }

    /** Cree une reservation apres controle de disponibilite. */
    public function reserve(array $data): Rental
    {
        return DB::transaction(function () use ($data) {
            $vehicle = Vehicle::lockForUpdate()->findOrFail($data['vehicle_id']);

            if (! $vehicle->availability->allowsRent()) {
                throw new RuntimeException("Le vehicule {$vehicle->reference} n'est pas propose a la location.");
            }

            if (! $vehicle->isAvailableForRentBetween($data['start_at'], $data['expected_return_at'])) {
                throw new RuntimeException("Le vehicule {$vehicle->reference} est deja reserve sur cette periode.");
            }

            $days = $this->billedDays($data['start_at'], $data['expected_return_at']);
            $rate = $data['daily_rate'] ?? $vehicle->rental_daily_rate;

            if ($rate === null) {
                throw new RuntimeException("Aucun tarif journalier defini pour le vehicule {$vehicle->reference}.");
            }

            $rental = Rental::create(array_merge($data, [
                'reference'      => $this->references->next('LOC'),
                'status'         => RentalStatus::Reserved,
                'daily_rate'     => $rate,
                'billed_days'    => $days,
                'deposit_amount' => $data['deposit_amount'] ?? $vehicle->rental_deposit ?? 0,
                'total_amount'   => $days * (float) $rate,
                'currency'       => $vehicle->currency,
            ]));

            $vehicle->update(['status' => VehicleStatus::Reserved]);

            return $rental;
        });
    }

    /** Depart du vehicule. */
    public function checkout(Rental $rental, ?int $mileageStart = null, ?int $fuelLevel = null): Rental
    {
        $rental->update([
            'status'           => RentalStatus::Ongoing,
            'mileage_start_km' => $mileageStart ?? $rental->vehicle->mileage_km,
            'fuel_level_start' => $fuelLevel,
        ]);

        $rental->vehicle->update(['status' => VehicleStatus::Rented]);

        return $rental;
    }

    /** Retour du vehicule : recalcul du total, kilometrage et depassements. */
    public function checkin(Rental $rental, array $data = []): Rental
    {
        return DB::transaction(function () use ($rental, $data) {
            $returnedAt = Carbon::parse($data['actual_return_at'] ?? now());
            $days       = $this->billedDays($rental->start_at, $returnedAt);
            $extra      = (float) ($data['extra_charges'] ?? 0);

            $mileageEnd = $data['mileage_end_km'] ?? null;

            if ($mileageEnd !== null && $rental->mileage_limit_km && $rental->extra_km_rate) {
                $done = $mileageEnd - (int) $rental->mileage_start_km;
                $over = max(0, $done - $rental->mileage_limit_km);
                $extra += $over * (float) $rental->extra_km_rate;
            }

            $rental->update([
                'status'           => RentalStatus::Returned,
                'actual_return_at' => $returnedAt,
                'billed_days'      => $days,
                'mileage_end_km'   => $mileageEnd,
                'fuel_level_end'   => $data['fuel_level_end'] ?? null,
                'extra_charges'    => $extra,
                'total_amount'     => $days * (float) $rental->daily_rate + $extra,
                'checkin_notes'    => $data['checkin_notes'] ?? null,
            ]);

            // Mise a jour du compteur pour un vehicule immatricule.
            if ($mileageEnd !== null && $rental->vehicle->registrationDetail) {
                $rental->vehicle->registrationDetail->update(['mileage_km' => $mileageEnd]);
            }

            $rental->vehicle->update(['status' => VehicleStatus::InStock]);

            return $rental;
        });
    }

    /** Marque en retard toutes les locations non rendues. */
    public function flagOverdue(): int
    {
        return Rental::where('status', RentalStatus::Ongoing)
            ->whereNull('actual_return_at')
            ->where('expected_return_at', '<', now())
            ->update(['status' => RentalStatus::Overdue]);
    }

    protected function billedDays(mixed $start, mixed $end): int
    {
        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end));

        return max(1, (int) ceil($days ?: 1));
    }
}
