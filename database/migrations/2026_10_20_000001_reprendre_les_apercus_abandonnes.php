<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Remet a zero le compteur des media dont l'apercu a ete abandonne.
 *
 * Sans cette migration, le correctif de GD ne changerait RIEN au catalogue
 * actuel, et c'est le genre de piege qui fait croire qu'un correctif a echoue.
 *
 * ── POURQUOI ELLE EST NECESSAIRE ───────────────────────────────────────────
 *
 * `GenererApercusMedia` ecarte de son lot toute ligne ayant atteint
 * `ESSAIS_MAX`, et c'est VOULU : sans cela, une ligne irreparable reprendrait
 * le budget de `--limit` a chaque demarrage et les lignes saines ne seraient
 * jamais atteintes. Le journal de production du 20/10/2026 montre les dix
 * media du catalogue a « essai 3/3 », donc tous hors du lot.
 *
 * La commande prevoit `--refaire` pour exactement ce cas — « les reprend quand
 * la cause est corrigee », dit sa propre sortie. Mais le plan gratuit de Render
 * n'ouvre aucun shell : personne ne peut la lancer avec cette option. Une
 * migration est le seul chemin, comme elle l'a deja ete pour les
 * compatibilites de filtres.
 *
 * ── POURQUOI ELLE TOMBE AU BON MOMENT ──────────────────────────────────────
 *
 * Le CMD enchaine `migrate --force`, puis les seeders, puis lance le serveur et
 * `media:apercus` en arriere-plan. Cette migration s'execute donc AVANT la
 * commande, dans le meme deploiement que la reconstruction de GD : les
 * compteurs repartent de zero, et la commande retrouve les dix lignes avec un
 * GD qui sait enfin lire et ecrire le JPEG. Un seul deploiement, pas deux.
 *
 * ── CE QU'ELLE NE FAIT PAS ─────────────────────────────────────────────────
 *
 * Elle ne touche QUE les lignes sans variante. Une ligne qui en a une a deja
 * son compteur a zero — la commande le remet elle-meme a la reussite — et la
 * reecrire serait du bruit.
 *
 * Elle ne vide pas `preview_failed_at` : cette colonne garde la trace du
 * dernier echec, et l'effacer ferait perdre l'information qu'un echec a eu
 * lieu avant ce correctif. Le compteur suffit a rouvrir le lot.
 *
 * ── CE QUI ARRIVE SI LA CAUSE N'ETAIT PAS GD ───────────────────────────────
 *
 * Les dix lignes refont trois tentatives, echouent de nouveau, et sont
 * abandonnees — mais cette fois les quatre branches muettes de
 * `VignetteApercu` ont ete rendues bavardes dans le meme lot : le journal
 * nommera la cause au lieu de se taire. Rejouer ces trois tentatives coute
 * quelques secondes au demarrage ; ne pas les rejouer couterait un diagnostic
 * a l'aveugle de plus.
 *
 * Idempotente : un `UPDATE` vers une constante, sur un critere qui cesse d'etre
 * vrai des que la variante existe. Et elle ne leve jamais — le CMD enchaine
 * `migrate --force` puis `php artisan serve`, donc une migration qui echoue
 * eteint l'API.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            if (! Schema::hasTable('media') || ! Schema::hasColumn('media', 'preview_attempts')) {
                echo '  Table ou colonne absente : rien a faire.'.PHP_EOL;

                return;
            }

            $aReprendre = DB::table('media')
                ->whereNull('preview_path')
                ->where('preview_attempts', '>', 0)
                ->count();

            if ($aReprendre === 0) {
                echo '  Aucun apercu abandonne : rien a reprendre.'.PHP_EOL;

                return;
            }

            DB::table('media')
                ->whereNull('preview_path')
                ->where('preview_attempts', '>', 0)
                ->update(['preview_attempts' => 0]);

            echo "  {$aReprendre} apercu(s) abandonne(s) remis dans le lot ; ".
                 '`media:apercus` les reprendra au demarrage de ce deploiement.'.PHP_EOL;
        } catch (\Throwable $e) {
            echo '  ATTENTION : les apercus abandonnes n\'ont pas ete repris ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Rien a defaire.
     *
     * Remettre les compteurs a trois reproduirait un etat d'echec sans savoir
     * lesquelles avaient reellement echoue, et priverait le rattrapage de
     * lignes qui ont peut-etre reussi depuis.
     */
    public function down(): void
    {
        //
    }
};
