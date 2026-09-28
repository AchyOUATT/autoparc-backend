<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le controle avant voyage : gabarits, passages, reponses.
 *
 * Trois tables parce que trois durees de vie differentes. Les gabarits sont du
 * referentiel : ils se reecrivent, se desactivent, s'enrichissent. Un passage
 * est un fait date, qui ne bouge plus. Une reponse appartient a son passage.
 *
 * Pourquoi des conditions de declenchement sur les gabarits plutot qu'une
 * liste figee : une liste de quarante points generiques se remplit une fois
 * puis s'abandonne. Un diesel de 190 000 km n'a pas les memes points a
 * verifier qu'une citadine de 2022, et l'application sait deja lequel des deux
 * elle a en face — millesime, kilometrage, carrosserie, motorisation. La liste
 * se compose donc par vehicule : dix points qui le concernent valent mieux que
 * quarante qui ne le concernent pas.
 *
 * Pourquoi les reponses recopient le libelle et la severite de leur gabarit :
 * sans cela, retirer un point de controle ou en reecrire l'intitule reecrirait
 * l'histoire des passages deja faits. Un controle repond de ce qu'on a demande
 * ce jour-la, pas de ce qu'on demande aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Gabarits : les points de controle possibles ───────────────
        Schema::create('vehicle_check_items', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();      // pneus-usure, papiers-assurance
            $table->string('category', 30);            // papiers, pneus, niveaux, freins...
            $table->string('title');
            $table->text('help')->nullable();          // ou regarder, et ce qu'on cherche

            // Un defaut bloquant interdit le depart, un defaut a surveiller
            // ne fait qu'entrer dans la liste des choses a faire. La nuance
            // decide du verdict : elle ne peut pas vivre cote application.
            $table->enum('severity', ['blocking', 'watch']);

            // Un point du noyau est pose a tout vehicule. Les autres attendent
            // qu'une de leurs conditions soit remplie — elles se cumulent, et
            // toutes doivent l'etre.
            $table->boolean('is_core')->default(false);

            $table->unsignedSmallInteger('min_trip_distance_km')->nullable();
            $table->unsignedInteger('min_mileage_km')->nullable();
            $table->unsignedTinyInteger('min_age_years')->nullable();
            $table->json('engine_codes')->nullable();  // ['diesel'] — engine_types.code

            // L'inverse d'engine_codes, et pas son complement : un point n'est
            // ecarte que si l'on SAIT que la motorisation ne le concerne pas.
            // « Courroie d'accessoires » n'a aucun sens sur une electrique, mais
            // exiger engine_codes = [petrol, diesel, hybrid] la retirerait aussi
            // a tous les vehicules dont le proprietaire n'a pas saisi son moteur
            // — le cas le plus courant.
            $table->json('excluded_engine_codes')->nullable();
            $table->json('body_types')->nullable();    // ['pick-up'] — vehicle_models.body_type
            // Mois calendaires, de 1 a 12 : la poussiere de l'harmattan et la
            // saison des pluies n'usent pas les memes pieces.
            $table->json('months')->nullable();

            // Rendre visible la raison d'un point est la moitie de son interet :
            // « 180 000 km au compteur » convainc de regarder la courroie, « il
            // faut verifier la courroie » ne convainc personne. Ces raisons se
            // deduisent des conditions chiffrees. Cette colonne ne sert qu'aux
            // conditions qui ne se lisent pas d'elles-memes — la saison — et
            // n'a pas a rediger ce que les chiffres disent mieux.
            $table->string('trigger_label')->nullable();

            // Certains points, l'application connait deja leur reponse : elle
            // porte la date d'assurance, celle de la visite, et l'ecart de
            // kilometrage depuis la derniere vidange. Le point est alors
            // pre-rempli, avec la raison affichee — mais reste modifiable : une
            // assurance peut avoir ete renouvelee sans que personne n'ait
            // pense a le saisir ici.
            $table->enum('prefill_source', ['insurance', 'technical_inspection', 'service'])->nullable();

            // Un point rate doit mener a la piece. Nullable : « fuite sous le
            // vehicule » ne designe aucune categorie precise.
            $table->string('part_category_slug', 60)->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        // ── Passages : un controle effectue, a une date ───────────────
        Schema::create('vehicle_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owned_vehicle_id')->constrained()->cascadeOnDelete();
            // Redondant avec le vehicule, et volontairement : la policy et
            // l'historique « mes controles » interrogent l'utilisateur sans
            // avoir a joindre le garage.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->enum('reason', ['trip', 'periodic'])->default('trip');
            $table->unsignedSmallInteger('trip_distance_km')->nullable();

            // Le kilometrage releve au passage. C'est la contrepartie discrete
            // de la fonction : le rappel de vidange ne part jamais sans lui, et
            // personne n'ouvre l'application pour saisir un compteur.
            $table->unsignedInteger('mileage_km')->nullable();

            $table->timestamp('performed_at');
            $table->enum('verdict', ['blocked', 'attention', 'clear']);
            $table->unsignedTinyInteger('blocking_count')->default(0);
            $table->unsignedTinyInteger('watch_count')->default(0);
            $table->unsignedTinyInteger('checked_count')->default(0);
            $table->text('note')->nullable();

            // Cle d'idempotence fournie par le telephone. Un controle se
            // remplit dans une cour, capot ouvert, souvent sans reseau :
            // l'envoi sera rejoue. Sans cette cle, un reseau hesitant
            // enregistrerait deux fois le meme passage.
            $table->uuid('client_reference')->nullable();

            $table->timestamps();

            // Nullable et unique : MySQL comme PostgreSQL tiennent deux NULL
            // pour distincts, donc un controle sans cle reste possible.
            $table->unique(['owned_vehicle_id', 'client_reference']);
            $table->index(['owned_vehicle_id', 'performed_at']);
        });

        // ── Reponses : un point, un etat ──────────────────────────────
        Schema::create('vehicle_check_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_check_id')->constrained()->cascadeOnDelete();

            $table->string('item_code', 60);
            $table->string('title');                  // recopie du gabarit
            $table->string('category', 30);           // recopiee
            $table->enum('severity', ['blocking', 'watch']); // recopiee

            $table->enum('status', ['ok', 'watch', 'bad']);
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // Un point ne se repond qu'une fois par passage.
            $table->unique(['vehicle_check_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_check_answers');
        Schema::dropIfExists('vehicle_checks');
        Schema::dropIfExists('vehicle_check_items');
    }
};
