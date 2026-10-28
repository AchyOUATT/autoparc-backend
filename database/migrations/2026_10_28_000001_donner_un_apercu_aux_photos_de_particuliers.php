<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les trois colonnes qui rendent un apercu de partage possible.
 *
 * POURQUOI ELLES N'ETAIENT PAS LA, ET POURQUOI IL FALLAIT LES AJOUTER
 *
 * `private_listing_photos` n'avait que `disk` et `path`. La page /annonces/{id}
 * servait donc l'ORIGINAL en og:image : un JPEG jusqu'a 2048x2048, de l'ordre
 * de 700 ko — docker/php/depot-de-photos.ini mesure 706 508 o pour ce que pose
 * le lot mobile. WhatsApp refuse la vignette au-dela de quelques centaines de
 * ko et se rabat sur titre + description : le lien arrivait sans photo, donc
 * sans la seule chose qui le distingue d'un lien nu.
 *
 * C'est le defaut que ce depot a DEJA paye pour le catalogue, et pour lequel
 * `VignetteApercu` et `PhotoPartage` existent. La difference, ici, est qu'il
 * aurait ete PERMANENT : sans colonne, aucune variante n'etait meme stockable,
 * et `media:apercus` — le rattrapage — ne parcourt que la table `media`.
 *
 * Memes noms et memes types que `2026_10_17_000001_ajouter_l_apercu_aux_media`,
 * pour que `PhotoPartage` prenne les deux sans se dedoubler. On n'emprunte PAS
 * `preview_attempts` : ce compteur sert au rattrapage par lots d'un parc
 * existant, et ces photos-ci ont toutes leur variante fabriquee au depot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_listing_photos', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('path');
            $table->unsignedSmallInteger('preview_width')->nullable()->after('preview_path');
            $table->unsignedSmallInteger('preview_height')->nullable()->after('preview_width');
        });
    }

    public function down(): void
    {
        Schema::table('private_listing_photos', function (Blueprint $table) {
            $table->dropColumn(['preview_path', 'preview_width', 'preview_height']);
        });
    }
};
