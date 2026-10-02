<?php

namespace App\Models;

use App\Enums\FitmentSource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Compatibilite declaree directement entre une piece et un modele de vehicule. */
class PartFitment extends Model
{
    use HasFactory;

    protected $fillable = [
        'part_id', 'vehicle_model_id', 'trim_id', 'engine_type_id',
        'drivetrain_id', 'engine_code', 'year_from', 'year_to', 'position', 'notes',
        // Renseignee par les controleurs, jamais par la requete : aucune regle
        // de validation ne la laisse passer, et `validated()` ecarte le reste.
        'source',
    ];

    protected function casts(): array
    {
        return [
            'year_from' => 'integer',
            'year_to'   => 'integer',
            'source'    => FitmentSource::class,
        ];
    }

    public function part()
    {
        return $this->belongsTo(Part::class);
    }

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    public function trim()
    {
        return $this->belongsTo(Trim::class);
    }

    public function engineType()
    {
        return $this->belongsTo(EngineType::class);
    }

    public function drivetrain()
    {
        return $this->belongsTo(Drivetrain::class);
    }

    /**
     * Les lignes que le recalcul du catalogue s'autorise a detruire.
     *
     * Nommer cette restriction plutot que de la repeter en clair dans la
     * commande sert deux choses : l'intention se lit au point d'appel, et la
     * regle s'eprouve toute seule. C'est la seconde barriere — la premiere
     * etant que la commande ignore les pieces portant une declaration.
     */
    public function scopeFabriquees($query)
    {
        return $query->where('source', FitmentSource::Generated);
    }

    public function coversYear(int $year): bool
    {
        return ($this->year_from === null || $year >= $this->year_from)
            && ($this->year_to === null || $year <= $this->year_to);
    }
}
