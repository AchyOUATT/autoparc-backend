<?php

namespace App\Models;

use App\Enums\CheckVerdict;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un controle effectue, a une date, sur un vehicule du garage.
 *
 * Un passage ne se modifie pas. C'est un constat date, et la seule chose qu'on
 * puisse faire d'un constat errone est d'en faire un autre. D'ou l'absence de
 * route de mise a jour : la fonction n'offre que « refaire le controle ».
 *
 * Les compteurs sont stockes plutot que recalcules a la lecture. L'historique
 * d'un vehicule se liste vingt passages a la fois, et recompter les reponses de
 * chacun pour afficher « 2 points a regler » couterait vingt requetes pour une
 * information qui ne bougera plus jamais.
 */
class VehicleCheck extends Model
{
    protected $fillable = [
        'owned_vehicle_id', 'user_id', 'reason', 'trip_distance_km',
        'mileage_km', 'performed_at', 'verdict',
        'blocking_count', 'watch_count', 'checked_count', 'note',
        'client_reference',
    ];

    protected function casts(): array
    {
        return [
            'performed_at'     => 'datetime',
            'verdict'          => CheckVerdict::class,
            'trip_distance_km' => 'integer',
            'mileage_km'       => 'integer',
            'blocking_count'   => 'integer',
            'watch_count'      => 'integer',
            'checked_count'    => 'integer',
        ];
    }

    public function ownedVehicle(): BelongsTo
    {
        return $this->belongsTo(OwnedVehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(VehicleCheckAnswer::class);
    }

    /**
     * Les points qui restent a traiter, du plus grave au moins grave.
     *
     * C'est ce que le garage affiche entre deux controles, et la seule partie
     * d'un passage qui interesse encore quelqu'un une semaine plus tard.
     */
    public function aReprendre(): HasMany
    {
        return $this->answers()
            ->whereIn('status', ['bad', 'watch'])
            ->orderByRaw("CASE WHEN status = 'bad' THEN 0 ELSE 1 END")
            ->orderByRaw("CASE WHEN severity = 'blocking' THEN 0 ELSE 1 END");
    }

    public function scopeOwnedBy(Builder $q, int $userId): Builder
    {
        return $q->where('user_id', $userId);
    }
}
