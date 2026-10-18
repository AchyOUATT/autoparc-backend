<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La variante d'image servie a og:image, enregistree a cote de l'original.
 *
 * MediaController ecrit le fichier tel qu'il arrive : aucun redimensionnement,
 * et jusqu'a 8192 ko par photo. WhatsApp refuse la vignette au-dela de quelques
 * centaines de ko et se rabat sur titre + description — l'apercu perdait la
 * photo, donc sa raison d'etre.
 *
 * Les 8 Mo n'etaient d'ailleurs qu'annonces : jusqu'a
 * docker/php/depot-de-photos.ini, PHP plafonnait le depot a son defaut compile
 * de 2M et refusait tout au-dela (mesure par HTTP reel : 2 255 773 o -> 422).
 * Les lignes que cette migration rattrape portent donc des originaux de 2 Mo au
 * plus — ce qui est deja bien trop pour une vignette.
 *
 * Trois colonnes et non une seule, et c'est le point de cette migration.
 *
 * On pourrait deduire le chemin de la variante par convention, sans colonne du
 * tout. Il faudrait alors un `exists()` sur le disque a CHAQUE rendu de page
 * pour savoir si elle est la — soit un aller-retour reseau vers Cloudflare R2
 * sur le chemin critique, devant une instance Render qui met deja plusieurs
 * secondes a se reveiller. La colonne repond sans ce voyage.
 *
 * Les dimensions sont enregistrees pour la meme raison, et pour une seconde :
 * la page declare og:image:width et og:image:height, et une dimension annoncee
 * fausse vaut moins que pas de dimension du tout. La borne etant 1200x630 a
 * rapport conserve, elles varient d'une photo a l'autre — elles se lisent, elles
 * ne se devinent pas.
 *
 * NULLABLES, et elles le restent. Les media deja en base n'ont pas de variante
 * et continuent de servir leur original (voir PhotoPartage::depuis, qui prefere
 * la variante quand elle existe et retombe sur l'original sinon) ; la commande
 * `media:apercus` les rattrape sans que la colonne ait a mentir en attendant.
 *
 * ── Deux colonnes de plus, pour que le rattrapage AVANCE ───────────────────
 *
 * `preview_attempts` et `preview_failed_at` ne decrivent pas la variante : elles
 * decrivent les ECHECS de sa fabrication. Sans elles, une ligne qu'aucune
 * execution ne saura jamais traiter — original disparu du stockage objet, ce que
 * render.yaml documente comme etant arrive — reste eternellement dans
 * `whereNull('preview_path')`, et la commande, qui repart du plus petit
 * identifiant et s'arrete a --limit, la reprend a chaque demarrage sans jamais
 * atteindre les lignes saines.
 *
 * Mesure avant correction, cinq media dont les deux plus petits identifiants
 * sont irreparables, `media:apercus --limit=2` lance trois fois :
 *
 *     demarrage 1 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *     demarrage 2 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *     demarrage 3 -> « 0 variante(s) generee(s) », 0 ligne avec variante sur 5
 *
 * Trois demarrages, aucune progression, et un compte rendu que rien ne
 * distingue de « rien a faire ».
 *
 * `preview_attempts` entre dans le TRI (jamais tentees d'abord), ce qui fait
 * avancer la commande des le demarrage suivant, et dans le FILTRE au-dela de
 * quelques essais, ce qui finit par sortir la ligne du lot. `preview_failed_at`
 * date le dernier echec : c'est ce qui permet de voir, en base, qu'une ligne
 * echoue depuis longtemps plutot que depuis la derniere minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->string('preview_path')->nullable()->after('path');
            $table->unsignedSmallInteger('preview_width')->nullable()->after('preview_path');
            $table->unsignedSmallInteger('preview_height')->nullable()->after('preview_width');

            // `default(0)` et non nullable : le tri porte sur cette colonne, et
            // un NULL se classerait avant ou apres 0 selon le moteur — Postgres
            // en ligne, SQLite dans la suite de tests. Un compteur a zero dit
            // la meme chose sans dependre du moteur.
            $table->unsignedTinyInteger('preview_attempts')->default(0)->after('preview_height');
            $table->timestamp('preview_failed_at')->nullable()->after('preview_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropColumn([
                'preview_path', 'preview_width', 'preview_height',
                'preview_attempts', 'preview_failed_at',
            ]);
        });
    }
};
