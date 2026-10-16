<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le pays d'origine n'est plus obligatoire sur une fiche d'import.
 *
 * Mettre un vehicule en vente echouait par une 500 des que deux conditions
 * se rencontraient : aucune plaque d'immatriculation, et un kilometrage
 * saisi. Le formulaire range alors le compteur dans un bloc `import`
 * (vehicle_register_page.dart::_buildPayload), et VehicleController::
 * syncTypeSpecificDetails cree une ligne vehicle_import_details ne portant
 * que `vehicle_id` et `odometer_at_import_km`. La table exigeait en plus un
 * pays d'origine, que rien dans ce parcours ne demande.
 *
 * Trace de production, reference 5bzt7jiv :
 *
 *   SQLSTATE[23502] : null value in column "origin_country_id" of relation
 *   "vehicle_import_details" violates not-null constraint
 *   insert into "vehicle_import_details" ("vehicle_id",
 *   "odometer_at_import_km", "updated_at", "created_at")
 *   values (85, 110000, ...)
 *
 * Reproduite independamment en test sur sqlite, meme insertion, meme colonne.
 *
 * Pourquoi rendre la colonne facultative plutot que demander le pays : toutes
 * les autres colonnes de cette table sont deja nulles ou pourvues d'un
 * defaut. Celle-ci etait la seule a etre exigee, et elle l'etait pour une
 * fiche d'import complete — un cas que le parcours « mettre en vente » ne
 * couvre pas. Le kilometrage d'un vehicule sans plaque n'a rien a voir avec
 * une importation ; il atterrit ici parce que c'est la que le modele range
 * les compteurs, pas parce qu'on declare une importation.
 *
 * La cle etrangere est conservee : un pays renseigne doit toujours exister.
 *
 * LE MEME DEFAUT EXISTE SUR L'AUTRE BRANCHE, et il n'avait ete signale par
 * personne. Avec une plaque, le formulaire range le compteur dans un bloc
 * `registration`, le controleur cree une ligne vehicle_registration_details
 * ne portant que la plaque et le kilometrage, et registration_country_id est
 * exigee de la meme facon. Decouvert en ecrivant le test de la branche
 * opposee, pas sur le terrain : la panne attendait le premier vehicule
 * immatricule. Les deux colonnes sont donc traitees ensemble.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_import_details', function (Blueprint $table) {
            $table->foreignId('origin_country_id')->nullable()->change();
        });

        Schema::table('vehicle_registration_details', function (Blueprint $table) {
            $table->foreignId('registration_country_id')->nullable()->change();
        });
    }

    /**
     * Le retour arriere ne peut pas reimposer la contrainte sans mentir : les
     * lignes creees entre-temps par une mise en vente n'ont pas de pays, et
     * aucune valeur par defaut ne serait vraie. On ne tente donc pas de les
     * remplir — on laisse la colonne facultative, et on le dit.
     */
    public function down(): void
    {
        // Volontairement vide : voir le commentaire ci-dessus.
    }
};
