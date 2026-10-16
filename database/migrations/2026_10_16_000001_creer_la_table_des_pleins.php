<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les passages a la pompe, d'ou sortent la consommation reelle et son cout.
 *
 * Une seule table, et deux natures de passage dedans : le plein et le
 * demi-plein. Le demi-plein existe parce qu'ici on achete pour un montant
 * — « mets-moi pour 10 000 » — et non jusqu'au debordement. Une table qui
 * n'accepterait que les pleins resterait vide pour la majorite du parc, et la
 * fonction ne rendrait jamais un chiffre.
 *
 * Seul le plein borne une mesure : l'arret automatique du pistolet est un
 * niveau que le conducteur sait reproduire, « la moitie du reservoir » ne l'est
 * pas. Un demi-plein apporte donc ses litres au calcul sans jamais fermer un
 * intervalle. D'ou is_full, qui n'est pas une case cosmetique mais ce qui
 * decide si le passage ferme une mesure ou l'alimente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fuel_fills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_vehicle_id')->constrained('owned_vehicles')->cascadeOnDelete();

            // Pas de valeur par defaut, deliberement : c'est la saisie qui
            // tranche. Un defaut en base rendrait muet un client qui oublie
            // d'envoyer le champ — les mesures se fermeraient toutes seules sur
            // des demi-pleins, et le chiffre affiche serait faux sans qu'aucune
            // erreur ne le signale. Le defaut d'INTERFACE, lui, est le
            // demi-plein, parce que l'erreur n'est pas symetrique : un plein
            // declare demi-plein coute un point de mesure, l'inverse coute la
            // justesse du resultat.
            $table->boolean('is_full');

            // Nullable : on saisit souvent le soir le passage du matin, et
            // parfois celui de la semaine derniere. Exiger la date ferait
            // renoncer a la saisie — or c'est le compteur qui porte le calcul,
            // pas la date.
            $table->date('filled_at')->nullable();

            // Le compteur releve a la pompe. C'est lui, et lui seul, qui ordonne
            // la chaine des passages : un passage saisi en retard doit
            // s'inserer a sa place reelle, pas a la fin.
            $table->unsignedInteger('mileage_km');

            $table->decimal('litres', 6, 2);

            // Le montant est optionnel parce que le ticket se perd, mais c'est
            // lui qui rend le cout aux 100 km — le chiffre que le proprietaire
            // regarde en premier.
            $table->unsignedInteger('amount_fcfa')->nullable();
            $table->decimal('price_per_litre', 7, 2)->nullable();

            // Cle d'idempotence fournie par le telephone, meme motif que sur
            // vehicle_checks : la saisie se fait a la station, le reseau y est
            // mauvais, et l'envoi sera rejoue. Sans elle, un pouce impatient
            // double les litres du mois.
            $table->string('client_reference', 64)->nullable();
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // L'index porte sur le couple interroge a chaque calcul : tous les
            // passages d'un vehicule, dans l'ordre du compteur.
            $table->index(['owned_vehicle_id', 'mileage_km']);

            // Nullable et unique : deux NULL restent distincts pour MySQL comme
            // pour PostgreSQL, donc un passage sans cle reste possible.
            $table->unique(['owned_vehicle_id', 'client_reference']);
        });

        // Pas de SoftDeletes ici, contrairement a owned_vehicles. Une saisie
        // fautive — 974 000 km au lieu de 97 400 — doit pouvoir disparaitre pour
        // que la moyenne redevienne juste. Et une ligne supprimee en douce
        // fausserait le calcul le jour ou un scope oublierait de l'ecarter :
        // l'intervalle se fermerait sur un passage que plus personne ne voit.
    }

    public function down(): void
    {
        Schema::dropIfExists('fuel_fills');
    }
};
