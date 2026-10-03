<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Retire « HA36 » du Suzuki Alto : il n'aurait jamais du y etre pose.
 *
 * La migration precedente devait nommer dix-neuf generations, celles dont le
 * code couvre toute la ligne. L'Alto n'en faisait pas partie : la revision du
 * referentiel le scinde en HA36 (2015-2021) et HA37/HA97 (2021-), et notre
 * ligne unique court de 2015 a aujourd'hui. Il a ete inclus par erreur — une
 * vingtieme entree dans une liste qui devait en compter dix-neuf, reperee en
 * comparant le nombre de cases vides attendu en production (28) a celui
 * constate (27).
 *
 * Pourquoi le defaire plutot que le laisser. Tant que la ligne porte « HA36 »
 * tout en s'arretant a « depuis 2015 », l'application affiche « Alto (HA36,
 * depuis 2015) » au proprietaire d'une Alto de 2023 — une generation precise,
 * et fausse, puisque la HA36 s'arrete en 2021. C'est exactement ce que les
 * vingt-cinq autres scissions ont ete ecartees pour eviter. Une case vide dit
 * « on ne sait pas » ; elle ne trompe personne.
 *
 * Le slug redevient « alto » pour que `VehicleModelsSeeder`, dont l'upsert
 * porte sur (brand_id, slug) et dont la ligne est repassee a `null`, retrouve
 * la ligne existante au lieu d'en inserer une seconde.
 */
return new class extends Migration
{
    public function up(): void
    {
        $suzuki = DB::table('brands')->where('slug', 'suzuki')->value('id');

        if ($suzuki === null) {
            return;
        }

        $touchees = DB::table('vehicle_models')
            ->where('brand_id', $suzuki)
            ->where('name', 'Alto')
            ->where('production_start', 2015)
            // Seulement la valeur posee par erreur : une generation saisie
            // depuis l'application entre-temps reste en place.
            ->where('generation', 'HA36')
            ->update([
                'generation' => null,
                'slug'       => Str::slug('Alto'),
                'updated_at' => now(),
            ]);

        echo sprintf('  Alto : %d ligne(s) rendue(s) a une case vide.%s', $touchees, PHP_EOL);
    }

    /**
     * Pas de retour arriere : il remettrait le libelle trompeur.
     */
    public function down(): void
    {
        //
    }
};
