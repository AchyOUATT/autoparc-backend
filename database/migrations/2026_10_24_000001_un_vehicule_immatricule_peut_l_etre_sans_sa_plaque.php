<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le carnet d'immatriculation accepte de ne pas connaitre la plaque.
 *
 * ── POURQUOI CETTE CONTRAINTE EXISTAIT, ET CE QU'ELLE COUTAIT ──────────────
 *
 * `vehicle_registration_details` exigeait `plate_number` ET
 * `registration_country_id`. C'est de la que venait toute la mecanique
 * d'avant : `VehicleController::store()` DEDUISAIT le statut de la presence
 * d'une plaque, parce que le schema ne savait pas enregistrer autre chose.
 *
 * Le resultat, du point de vue d'un vendeur : il declare un vehicule
 * immatricule sans avoir sa carte grise sous la main, remplit le kilometrage
 * et l'echeance de visite technique — et la fiche part du cote import, son
 * carnet ignore. S'il force le statut, il recoit « Erreur du serveur » :
 * SQLSTATE[23000], contrainte NOT NULL sur `plate_number`.
 *
 * Les deux colonnes deviennent donc nullables. Ce ne sont pas des donnees
 * qu'on a toujours au moment ou l'on sait deja que le vehicule est
 * immatricule : la plaque se releve, la carte grise se retrouve, le pays se
 * precise. Ce sont des champs a completer, pas des conditions d'existence.
 *
 * ── CE QUI N'EST PAS TOUCHE ────────────────────────────────────────────────
 *
 * L'action dediee `POST /api/vehicles/{v}/register` continue d'EXIGER la
 * plaque et le pays dans sa propre validation, et c'est juste : immatriculer
 * un vehicule importe, c'est precisement poser ces deux valeurs. Cette
 * migration ouvre la creation, elle n'affaiblit pas cette action-la.
 *
 * Aucune ligne existante n'est modifiee : une contrainte qui se desserre ne
 * peut pas invalider ce qui la respectait deja. `down()` la resserre, et
 * echouera si des lignes sans plaque ont ete creees entre-temps — c'est
 * voulu : mieux vaut un rollback qui refuse qu'un rollback qui tronque.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_registration_details', function (Blueprint $table) {
            $table->string('plate_number')->nullable()->change();
            $table->foreignId('registration_country_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_registration_details', function (Blueprint $table) {
            $table->string('plate_number')->nullable(false)->change();
            $table->foreignId('registration_country_id')->nullable(false)->change();
        });
    }
};
