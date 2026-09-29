<?php

namespace App\Models;

use App\Support\Saison;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un point de controle possible, et les conditions qui le font apparaitre.
 *
 * Le noyau (is_core) est pose a tout vehicule. Tout le reste attend une raison
 * chiffree : 60 000 km pour la courroie, un diesel qui a roule pour le filtre a
 * gasoil, la saison seche pour le filtre a air. Un point qui apparait sans
 * raison est un point qu'on coche sans regarder — et une liste de quarante
 * points generiques se remplit une fois puis s'abandonne.
 *
 * Les conditions se CUMULENT : toutes celles qui sont renseignees doivent etre
 * remplies. La courroie de distribution attend dix ans ET deux cents
 * kilometres, parce qu'une courroie d'age inconnu se supporte en ville et ne se
 * supporte pas sur une nationale.
 *
 * Une donnee manquante ne declenche jamais un point conditionnel : un vehicule
 * dont le kilometrage n'est pas saisi ne verra pas les points kilometriques.
 * L'inverse — afficher par precaution — remplirait la liste de points que le
 * proprietaire ne peut pas juger, et lui apprendrait a cocher sans lire.
 */
class VehicleCheckItem extends Model
{
    protected $fillable = [
        'code', 'category', 'title', 'help', 'severity', 'is_core',
        'min_trip_distance_km', 'min_mileage_km', 'min_age_years',
        'engine_codes', 'excluded_engine_codes', 'body_types', 'months', 'seasons', 'trigger_label',
        'prefill_source', 'part_category_slug', 'position', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_core'              => 'boolean',
            'is_active'            => 'boolean',
            'min_trip_distance_km' => 'integer',
            'min_mileage_km'       => 'integer',
            'min_age_years'        => 'integer',
            'engine_codes'          => 'array',
            'excluded_engine_codes' => 'array',
            'body_types'           => 'array',
            'months'                => 'array',
            'seasons'               => 'array',
            'position'             => 'integer',
        ];
    }

    public function scopeActifs(Builder $q): Builder
    {
        return $q->where('is_active', true)->orderBy('position');
    }

    /** Ce point appartient-il au controle de cette saison ? */
    public function appartientA(Saison $saison): bool
    {
        return in_array($saison->value, $this->seasons ?? [], true);
    }

    /**
     * Un point saisonnier n'a rien a faire dans un controle avant voyage.
     *
     * « Écoulements et joints de portes » se verifie une fois avant les pluies,
     * pas avant chaque trajet : l'y ajouter allongerait la liste du voyage d'un
     * point que personne ne referait.
     */
    public function estSaisonnier(): bool
    {
        return ! empty($this->seasons);
    }

    /**
     * Les raisons pour lesquelles ce point concerne ce vehicule, ou null s'il
     * ne le concerne pas.
     *
     * Le meme parcours decide et explique, volontairement : une liste de
     * raisons redigee a part finirait par ne plus correspondre aux conditions
     * qui l'ont produite. Et ces raisons sont la moitie de l'interet de la
     * fonction — « 180 000 km au compteur » convainc d'ouvrir le capot, « il
     * faut verifier la courroie » ne convainc personne.
     *
     * @return array<int, string>|null
     */
    public function raisonsPour(OwnedVehicle $vehicule, ?int $distanceKm, CarbonImmutable $jour): ?array
    {
        $raisons = [];

        if ($this->trigger_label !== null) {
            $raisons[] = $this->trigger_label;
        }

        if ($this->min_trip_distance_km !== null) {
            if ($distanceKm === null || $distanceKm < $this->min_trip_distance_km) {
                return null;
            }
            $raisons[] = 'Trajet de ' . $this->nombre($distanceKm) . ' km';
        }

        if ($this->min_mileage_km !== null) {
            if ($vehicule->mileage_km === null || $vehicule->mileage_km < $this->min_mileage_km) {
                return null;
            }
            $raisons[] = $this->nombre($vehicule->mileage_km) . ' km au compteur';
        }

        if ($this->min_age_years !== null) {
            $age = $vehicule->manufacturing_year === null
                ? null
                : $jour->year - (int) $vehicule->manufacturing_year;

            if ($age === null || $age < $this->min_age_years) {
                return null;
            }
            $raisons[] = 'Véhicule de ' . $age . ' ans';
        }

        if (! empty($this->engine_codes)) {
            $code = $vehicule->codeMotorisation();
            if ($code === null || ! in_array($code, $this->engine_codes, true)) {
                return null;
            }
            $raisons[] = $vehicule->libelleMotorisation() ?? ucfirst($code);
        }

        // L'exclusion ne joue que sur une motorisation CONNUE, et ne produit
        // aucune raison : elle ne dit pas pourquoi le point est la, elle dit
        // pourquoi il n'y est pas. Une courroie d'accessoires demandee sur une
        // electrique decredibilise toute la liste ; la retirer a un vehicule dont
        // le moteur n'est pas saisi priverait la plupart des vehicules du point.
        if (! empty($this->excluded_engine_codes)) {
            $code = $vehicule->codeMotorisation();
            if ($code !== null && in_array($code, $this->excluded_engine_codes, true)) {
                return null;
            }
        }

        if (! empty($this->body_types)) {
            $carrosserie = $vehicule->carrosserie();
            if ($carrosserie === null || ! in_array($carrosserie, $this->body_types, true)) {
                return null;
            }
            $raisons[] = ucfirst($carrosserie);
        }

        if (! empty($this->months)) {
            if (! in_array((int) $jour->month, array_map('intval', $this->months), true)) {
                return null;
            }
        }

        // Un point du noyau est pose meme sans raison particuliere. Un point
        // conditionnel qui n'a produit aucune raison n'a aucune condition
        // chiffree : il attend alors que l'application ait quelque chose a dire
        // sur lui, c'est-a-dire une source de pre-remplissage exploitable. Le
        // service tranche ce dernier cas, qui depend du vehicule.
        return $raisons;
    }

    /** Un point conditionnel sans aucun seuil : c'est le pre-remplissage qui le declenche. */
    public function attendUnPreRemplissage(): bool
    {
        return ! $this->is_core
            && $this->prefill_source !== null
            && $this->min_trip_distance_km === null
            && $this->min_mileage_km === null
            && $this->min_age_years === null
            && empty($this->engine_codes)
            && empty($this->excluded_engine_codes)
            && empty($this->body_types)
            && empty($this->months);
    }

    private function nombre(int $valeur): string
    {
        return number_format($valeur, 0, ',', ' ');
    }
}
