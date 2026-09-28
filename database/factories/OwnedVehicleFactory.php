<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\OwnedVehicle;
use App\Models\User;
use App\Models\VehicleModel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Un vehicule de garage client.
 *
 * Elle manquait : OwnedVehicle declarait HasFactory sans qu'aucune factory
 * existe, et les trois tests qui avaient besoin d'un vehicule de garage
 * construisaient a la main leur marque, leur modele et leur vehicule — vingt
 * lignes recopiees trois fois.
 *
 * Contrairement a VehicleFactory, celle-ci ne pioche PAS dans les referentiels
 * existants : elle cree sa marque et son modele. Un test de garage n'a pas
 * besoin du referentiel complet, et VehicleFactory plante sur une base vide
 * parce qu'elle fait firstOrFail() sur les marques.
 *
 * Aucune echeance par defaut, volontairement : c'est l'etat le plus courant d'un
 * vehicule qu'on vient d'ajouter, et un test qui a besoin d'une echeance doit le
 * dire — sinon un verdict de controle dependrait d'une date tiree au hasard.
 */
class OwnedVehicleFactory extends Factory
{
    protected $model = OwnedVehicle::class;

    public function definition(): array
    {
        // firstOrCreate plutot qu'une factory : VehicleModel n'en a pas, et la
        // table impose l'unicite du slug — deux vehicules crees dans le meme
        // test partagent donc la meme Corolla, ce qui est le cas reel.
        $marque = Brand::firstOrCreate(
            ['slug' => 'toyota-garage'],
            ['name' => 'Toyota'],
        );

        $modele = VehicleModel::firstOrCreate(
            ['slug' => 'toyota-garage-corolla'],
            [
                'brand_id'         => $marque->id,
                'name'             => 'Corolla',
                'generation'       => 'E210',
                'body_type'        => 'berline',
                'production_start' => 2019,
            ],
        );

        return [
            'user_id'            => User::factory()->state(['role' => 'client']),
            'brand_id'           => $marque->id,
            'vehicle_model_id'   => $modele->id,
            'manufacturing_year' => 2019,
            'mileage_km'         => 60_000,
        ];
    }

    /**
     * Une carrosserie donnee : elle vit sur le modele du catalogue, pas sur le
     * vehicule du garage. Chaque carrosserie a donc son propre modele.
     */
    public function carrosserie(string $type): static
    {
        return $this->state(function () use ($type) {
            $marque = Brand::firstOrCreate(['slug' => 'toyota-garage'], ['name' => 'Toyota']);

            $modele = VehicleModel::firstOrCreate(
                ['slug' => 'garage-'.$type],
                ['brand_id' => $marque->id, 'name' => ucfirst($type), 'body_type' => $type],
            );

            return ['brand_id' => $marque->id, 'vehicle_model_id' => $modele->id];
        });
    }

    /** Un vehicule dont l'assurance a expire il y a quelques jours. */
    public function assuranceExpiree(int $depuisJours = 12): static
    {
        return $this->state(fn () => [
            'insurance_expiry' => now()->subDays($depuisJours)->toDateString(),
        ]);
    }

    /**
     * Un vehicule dont la vidange arrive, ou est depassee si $restantKm est negatif.
     *
     * Le kilometrage se passe ICI et non dans create() : les surcharges de
     * create() s'appliquent apres les etats, donc un create(['mileage_km' => ...])
     * deplacerait le compteur sans deplacer le dernier entretien — et la vidange
     * se retrouverait depassee de cent mille kilometres sans que le test le dise.
     */
    public function vidangeDans(int $restantKm, int $compteur = 60_000): static
    {
        return $this->state(fn () => [
            'service_interval_km'     => 10_000,
            'mileage_km'              => $compteur,
            'last_service_mileage_km' => $compteur - (10_000 - $restantKm),
        ]);
    }
}
