<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un passage a la pompe : des litres, un compteur, et la nature du passage.
 *
 * is_full ne dit pas « le reservoir est plein » mais « la pompe s'est arretee
 * toute seule ». La nuance est tout le calcul : c'est le seul niveau qu'un
 * conducteur reproduit d'un passage a l'autre, donc le seul qui permette
 * d'affirmer que tout le carburant achete entre deux passages est passe dans le
 * moteur. Un demi-plein apporte ses litres a l'intervalle en cours sans jamais
 * en ouvrir ni en fermer un.
 *
 * Aucun calcul ici. La consommation se calcule dans SuiviConsommation et nulle
 * part ailleurs : la ressource API et la tache d'alerte doivent lire le meme
 * nombre, faute de quoi l'ecran et la notification se contredisent sur le meme
 * vehicule.
 */
class FuelFill extends Model
{
    use HasFactory;

    protected $fillable = [
        'owned_vehicle_id', 'is_full', 'filled_at', 'mileage_km', 'litres',
        'amount_fcfa', 'price_per_litre', 'client_reference', 'note',
    ];

    protected function casts(): array
    {
        return [
            'is_full'    => 'boolean',
            'filled_at'  => 'date',
            'mileage_km' => 'integer',

            // decimal, donc une CHAINE a la serialisation — « 41.20 » et non
            // 41.2. C'est volontaire et c'est le contrat : le modele Dart lit
            // ces champs avec un parseur tolerant. Cote PHP, le service
            // reconvertit en float avant de compter.
            'litres'          => 'decimal:2',
            'price_per_litre' => 'decimal:2',
            'amount_fcfa'     => 'integer',
        ];
    }

    public function ownedVehicle(): BelongsTo
    {
        return $this->belongsTo(OwnedVehicle::class);
    }
}
