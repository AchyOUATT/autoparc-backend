<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permet d'enregistrer un vehicule dont le modele n'est pas au referentiel.
 *
 * Le referentiel compte 25 marques et un peu plus de 200 modeles, batis sur
 * les flux d'occasion europeens. Le parc burkinabe vient aussi des Etats-Unis,
 * du Golfe et du Japon : il manquera toujours des modeles. Un CX-9 — jamais
 * vendu en Europe ni au Japon — a ainsi rendu l'enregistrement impossible,
 * apres une Mazda3 de 2014 quelques semaines plus tot.
 *
 * Jusqu'ici l'impasse etait totale : le formulaire exigeait un modele de la
 * liste, la validation aussi, et la colonne etait NOT NULL. Qui ne trouvait
 * pas le sien ne pouvait rien faire — ni enregistrer, ni signaler.
 *
 * `vehicle_model_id` devient donc facultatif, et `model_libre` recueille ce
 * que la personne a tape.
 *
 * Ce qu'un vehicule sans modele perd : la recherche de pieces compatibles, qui
 * s'appuie sur le modele. Elle repondra « non determine » plutot que de se
 * taire ou d'inventer.
 *
 * Ce qu'il garde : tout le reste du garage — kilometrage, echeances, controles,
 * consommation, rappels d'entretien. C'est-a-dire l'essentiel de ce pour quoi
 * on enregistre un vehicule.
 *
 * Et ce que l'application y gagne : chaque saisie libre est une demande
 * d'enrichissement du referentiel, avec le nom exact attendu par quelqu'un qui
 * a le vehicule sous les yeux.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            // La cle etrangere reste : un modele renseigne doit exister. Seule
            // son obligation disparait.
            $table->foreignId('vehicle_model_id')->nullable()->change();

            $table->string('model_libre', 80)
                ->nullable()
                ->after('vehicle_model_id');
        });
    }

    public function down(): void
    {
        Schema::table('owned_vehicles', function (Blueprint $table) {
            $table->dropColumn('model_libre');
        });

        // La colonne ne peut redevenir NOT NULL que si plus aucune ligne n'est
        // vide. On ne force pas : un retour arriere ne doit pas supprimer les
        // vehicules enregistres entre-temps.
        if (! \Illuminate\Support\Facades\DB::table('owned_vehicles')->whereNull('vehicle_model_id')->exists()) {
            Schema::table('owned_vehicles', function (Blueprint $table) {
                $table->foreignId('vehicle_model_id')->nullable(false)->change();
            });
        }
    }
};
