<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Une cote de consommation officielle, pour une motorisation donnee.
 *
 * Voir la migration `create_motorisations_table` pour le raisonnement : la
 * consommation appartient au moteur et a la boite, pas a l'annonce ni a la
 * finition.
 */
class Motorisation extends Model
{
    protected $fillable = [
        'source', 'cycle', 'model_year', 'make_raw', 'model_raw', 'vehicle_class',
        'engine_l', 'cylinders', 'transmission_code', 'fuel_code',
        'consumption_city', 'consumption_highway', 'consumption_combined', 'co2_g_km',
        'brand_id', 'vehicle_model_id',
    ];

    protected function casts(): array
    {
        return [
            'model_year'           => 'integer',
            'cylinders'            => 'integer',
            'co2_g_km'             => 'integer',
            'engine_l'             => 'decimal:1',
            'consumption_city'     => 'decimal:1',
            'consumption_highway'  => 'decimal:1',
            'consumption_combined' => 'decimal:1',
        ];
    }

    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function vehicleModel()
    {
        return $this->belongsTo(VehicleModel::class);
    }

    /**
     * Libelle affichable de la motorisation : « 2,5 l 4 cyl., boite auto. 6 ».
     *
     * C'est ce que l'utilisateur doit choisir, et non une finition : « LE » ne
     * dit rien de la consommation.
     */
    public function getLibelleAttribute(): string
    {
        $parties = [];

        if ($this->engine_l !== null) {
            $parties[] = str_replace('.', ',', (string) (float) $this->engine_l).' l';
        }
        if ($this->cylinders !== null) {
            $parties[] = $this->cylinders.' cyl.';
        }
        if ($this->transmission_code !== null) {
            $parties[] = self::libelleBoite($this->transmission_code);
        }

        return implode(' ', array_filter($parties));
    }

    /**
     * Traduit le code de boite de Ressources naturelles Canada.
     *
     * A : automatique, AM : robotisee, AS : automatique a convertisseur avec
     * rapports selectionnables, AV : variation continue, M : manuelle. Le
     * chiffre qui suit est le nombre de rapports.
     */
    public static function libelleBoite(string $code): string
    {
        if (! preg_match('/^(AV|AS|AM|A|M)(\d+)?$/', $code, $m)) {
            return $code;
        }

        $famille = match ($m[1]) {
            'AV' => 'boite a variation continue',
            'AS' => 'boite auto.',
            'AM' => 'boite robotisee',
            'A'  => 'boite auto.',
            'M'  => 'boite manuelle',
        };

        return isset($m[2]) ? $famille.' '.$m[2] : $famille;
    }

    /** Le carburant, tel que Ressources naturelles Canada le code. */
    public static function libelleCarburant(?string $code): ?string
    {
        return match ($code) {
            'X'     => 'Essence ordinaire',
            'Z'     => 'Essence super',
            'D'     => 'Diesel',
            'E'     => 'Ethanol (E85)',
            'N'     => 'Gaz naturel',
            'B'     => 'Electrique',
            default => null,
        };
    }
}
