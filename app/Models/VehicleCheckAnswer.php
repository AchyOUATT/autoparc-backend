<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un point de controle, et l'etat qu'on lui a donne.
 *
 * Le libelle, la categorie et la severite sont recopies du gabarit au moment du
 * passage, et ne sont volontairement pas lus par une relation. Sans cette copie,
 * reecrire l'intitule d'un point ou le retirer de la liste reecrirait l'histoire
 * des controles deja faits : un passage repond de ce qu'on a demande ce jour-la,
 * pas de ce qu'on demande aujourd'hui. Le code du gabarit reste stocke pour
 * pouvoir retrouver l'aide et la categorie de pieces quand le point existe
 * encore, mais rien n'en depend.
 */
class VehicleCheckAnswer extends Model
{
    protected $fillable = [
        'vehicle_check_id', 'item_code', 'title', 'category', 'severity',
        'status', 'note',
    ];

    public function check(): BelongsTo
    {
        return $this->belongsTo(VehicleCheck::class, 'vehicle_check_id');
    }

    /** Un defaut sur un point bloquant : c'est ce qui interdit le depart. */
    public function estBloquant(): bool
    {
        return $this->status === 'bad' && $this->severity === 'blocking';
    }
}
