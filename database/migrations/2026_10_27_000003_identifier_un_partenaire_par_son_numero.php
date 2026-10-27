<?php

use App\Support\NumeroDeTelephone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Le numero de telephone devient l'identifiant d'un partenaire.
 *
 * ── POURQUOI ──────────────────────────────────────────────────────────────
 *
 * Le courriel ne servait qu'a UNE chose : se connecter. Mesure faite, aucun
 * courriel n'est envoye nulle part — toutes les notifications passent par
 * `AppNotification`, dans l'application. Exiger une adresse d'un revendeur qui
 * n'en a pas revenait donc a lui demander d'en creer une pour pouvoir revenir
 * sur son compte.
 *
 * Il faut neanmoins SAVOIR de quel compte il s'agit. Le numero est la seule
 * chose que tout partenaire a, et qu'il donne deja : il devient l'identifiant.
 *
 * ── POURQUOI UNE COLONNE DE PLUS, ET NON UN INDEX SUR `phone` ─────────────
 *
 * « +226 70 00 00 01 » et « +22670000001 » sont le MEME numero et deux
 * chaines differentes : un index unique sur `phone` laisserait passer le
 * doublon qu'il est cense interdire. `phone_key` porte la forme normalisee —
 * chiffres seuls, voir `NumeroDeTelephone::cle()` — et c'est elle qui est
 * unique.
 *
 * `phone` reste tel que le partenaire l'a tape : c'est ce qui s'affiche sur
 * l'annonce, et l'y reecrire sans raison serait une perte seche de lisibilite.
 *
 * ── LES DOUBLONS EXISTANTS : POURQUOI ON NE S'ARRETE PAS ──────────────────
 *
 * La premiere version de cette migration levait une exception quand deux
 * comptes partageaient un numero. C'etait prudent sur les donnees et DESASTREUX
 * en production : le Dockerfile enchaine
 *
 *     php artisan migrate --force && … && php artisan serve
 *
 * Une migration qui jette arrete donc la chaine AVANT `serve`, et le site ne
 * demarre pas. L'offre Render employee ici n'ouvre aucun shell : personne ne
 * pourrait departager les comptes, et la seule sortie serait un nouveau
 * deploiement. Proteger la donnee en eteignant la boutique n'est pas un
 * arbitrage, c'est un echange d'un probleme contre un pire.
 *
 * Le choix retenu : le PREMIER compte de chaque groupe garde le numero, les
 * autres restent sans cle. Consequences, dites franchement :
 *
 *   — le numero reste pris : un nouvel inscrit ne peut pas le reclamer, donc
 *     personne ne se retrouve a l'identifiant d'un autre ;
 *   — les comptes laisses sans cle ne peuvent pas se connecter PAR NUMERO.
 *     Ils le peuvent par courriel, et ils en ont tous un : l'adresse etait
 *     obligatoire jusqu'a ce lot. Aucun partenaire existant ne perd l'acces ;
 *   — l'index unique accepte plusieurs valeurs nulles, sous SQLite comme sous
 *     MySQL, donc il s'applique quand meme ;
 *   — et le journal de deploiement NOMME les comptes concernes, pour que le
 *     personnel tranche quand il le voudra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->string('phone_key', 40)->nullable()->after('phone');
        });

        $this->attribuerLesCles();

        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->unique('phone_key');
        });

        // Le courriel devient facultatif. La colonne reste UNIQUE : deux
        // comptes ne peuvent toujours pas partager une adresse, et SQLite
        // comme MySQL autorisent plusieurs NULL sous un index unique.
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        $sansCourriel = DB::table('users')->whereNull('email')->count();

        if ($sansCourriel > 0) {
            throw new RuntimeException(
                "Retour arriere refuse : $sansCourriel compte(s) n'ont pas de courriel. "
                .'Leur en attribuer un explicitement avant de rendre la colonne obligatoire.'
            );
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
        });

        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->dropUnique(['phone_key']);
            $table->dropColumn('phone_key');
        });
    }

    /**
     * Le premier de chaque groupe garde le numero ; les suivants restent nuls.
     *
     * L'ordre est celui des identifiants, donc le plus ancien compte garde sa
     * ligne — c'est le seul depart non arbitraire disponible ici, et il est
     * stable d'une execution a l'autre.
     *
     * PUBLIQUE, et c'est voulu : une reprise de donnees qui se trompe de
     * compte donne l'identifiant de connexion d'un partenaire a un autre.
     * `MigrationDesNumerosPartenairesTest` l'appelle directement, parce qu'une
     * migration deja jouee ne se rejoue pas dans un banc.
     */
    public function attribuerLesCles(): void
    {
        $prises = [];
        $laisses = [];

        DB::table('partner_accounts')
            ->select('id', 'phone', 'company_name', 'contact_name')
            ->orderBy('id')
            ->chunk(200, function ($comptes) use (&$prises, &$laisses) {
                foreach ($comptes as $compte) {
                    $cle = NumeroDeTelephone::cle($compte->phone);

                    // Un numero sans aucun chiffre : rien a indexer, et rien a
                    // signaler non plus — ce compte n'a jamais eu de numero
                    // exploitable, il n'en perd donc aucun. La colonne reste
                    // nulle, ce qu'elle serait de toute facon.
                    if ($cle === null) {
                        continue;
                    }

                    if (isset($prises[$cle])) {
                        $laisses[] = "  #$compte->id  $compte->phone  "
                            .($compte->company_name ?: $compte->contact_name)
                            ."  (numero deja porte par #{$prises[$cle]})";

                        continue;
                    }

                    $prises[$cle] = $compte->id;
                    DB::table('partner_accounts')->where('id', $compte->id)
                        ->update(['phone_key' => $cle]);
                }
            });

        if ($laisses !== []) {
            $this->avertir($laisses);
        }
    }

    /** @param  list<string>  $laisses */
    private function avertir(array $laisses): void
    {
        $message = "ATTENTION : des comptes partenaires partageaient un numero.\n"
            ."Le numero devient l'identifiant de connexion : le compte le plus ancien le garde.\n"
            ."Les comptes ci-dessous se connectent par COURRIEL uniquement, jusqu'a ce qu'on\n"
            ."leur attribue un numero propre depuis l'administration des partenaires.\n\n"
            .implode("\n", $laisses)."\n";

        // Un avertissement, et non une exception : jeter ici arreterait la
        // chaine du Dockerfile avant `artisan serve`, c'est-a-dire eteindrait
        // le site. Il part par le journal de l'application, parce que c'est
        // ce que le deploiement collecte — et parce qu'un banc peut le lire,
        // ce qu'un `error_log` ne permet pas.
        Log::warning($message);
    }
};
