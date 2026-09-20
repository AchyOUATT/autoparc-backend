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
            // « 2,0 l » et non « 2 l » : une cylindree s'ecrit avec sa
            // decimale, c'est ainsi qu'elle figure sur les papiers du
            // vehicule.
            $parties[] = number_format((float) $this->engine_l, 1, ',', ' ').' l';
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
     * D'ou vient cette cote, en clair.
     *
     * Affiche a cote du chiffre : une consommation sans provenance ne se
     * discute pas. Un proprietaire qui trouve 8,2 l/100 pour sa voiture doit
     * pouvoir savoir qui l'a mesuree, et sur quel parcours.
     */
    public function getLibelleSourceAttribute(): string
    {
        return match ($this->source) {
            'nrcan' => 'Ressources naturelles Canada',
            'eea'   => 'Agence europeenne pour l\'environnement',
            default => $this->source,
        };
    }

    /**
     * Le protocole d'essai, en clair.
     *
     * Deux cotes issues de cycles differents ne se comparent pas : une meme
     * voiture se lit 8,2 en cinq cycles et 6,4 en WLTP. Le dire evite qu'on
     * croie a une difference entre deux vehicules.
     */
    public function getLibelleCycleAttribute(): string
    {
        return match ($this->cycle) {
            '5-cycle' => 'essai canadien, cinq cycles',
            '2-cycle' => 'essai canadien, deux cycles',
            'wltp'    => 'norme WLTP',
            'nedc'    => 'ancienne norme NEDC',
            default   => $this->cycle,
        };
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

        // Ces libelles sont lus par l'utilisateur, pas par le code : ils
        // portent leurs accents, contrairement au reste du fichier.
        $famille = match ($m[1]) {
            'AV' => 'boîte à variation continue',
            'AS' => 'boîte auto.',
            'AM' => 'boîte robotisée',
            'A'  => 'boîte auto.',
            'M'  => 'boîte manuelle',
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
            'L'     => 'GPL',
            'B'     => 'Electrique',
            // Sa cote officielle demarre batterie pleine : elle ne dit rien
            // de ce que la voiture boit une fois la batterie vide.
            'H'     => 'Hybride rechargeable',
            default => null,
        };
    }
}
