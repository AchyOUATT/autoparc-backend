<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un besoin client, approuve par le personnel, part a tous les partenaires.
 *
 * ── CE QUE CE LOT AJOUTE, ET POURQUOI CHAQUE PIECE ────────────────────────
 *
 * UN. `app_notifications.recipient_type` ne connait que `staff` et `client`.
 * Les partenaires n'ont donc aucune boite aux lettres : tout ce qui leur est
 * destine n'a nulle part ou aller. Troisieme valeur, `partner`.
 *
 * DEUX. `customer_needs.status` va de `pending` a `contacted`, `fulfilled` ou
 * `cancelled`. Aucun de ces etats ne dit « le personnel a lu cette demande et
 * la juge bonne a diffuser ». `approved` le dit, et c'est lui qui declenche.
 *
 * TROIS. `broadcast_at` retient QUAND la diffusion a eu lieu. Sans cette trace,
 * repasser un besoin par `approved` — un aller-retour de statut, une double
 * soumission du formulaire, un clic nerveux — le rediffuserait a tous les
 * partenaires. Quinze partenaires et trois clics font quarante-cinq
 * notifications pour une seule demande, et c'est ainsi qu'on apprend aux gens
 * a ignorer leurs notifications.
 *
 * ── POURQUOI UN ETAT ET NON UNE ROUTE DEDIEE ──────────────────────────────
 *
 * `PUT /api/needs/{need}` existe deja et porte le statut. Une route
 * `POST /needs/{need}/broadcast` ajouterait un geste a part, qu'il faudrait
 * penser a faire : le personnel approuverait sans diffuser, et s'etonnerait
 * du silence. Approuver EST diffuser — c'est ce que le proprietaire a decrit.
 *
 * ── CE QUE LES ANCIENNES LIGNES DEVIENNENT ────────────────────────────────
 *
 * Rien ne bouge : leur statut reste, `broadcast_at` est nul. Un besoin ancien
 * n'a pas ete diffuse, et le dire par un nul est exact.
 */
return new class extends Migration
{
    private const STATUTS_AVANT = ['pending', 'contacted', 'fulfilled', 'cancelled'];

    private const STATUTS_APRES = ['pending', 'approved', 'contacted', 'fulfilled', 'cancelled'];

    private const DESTINATAIRES_AVANT = ['staff', 'client'];

    private const DESTINATAIRES_APRES = ['staff', 'client', 'partner'];

    public function up(): void
    {
        $this->changerEnum('app_notifications', 'recipient_type', self::DESTINATAIRES_APRES);
        $this->changerEnum('customer_needs', 'status', self::STATUTS_APRES, 'pending');

        Schema::table('customer_needs', function (Blueprint $table) {
            $table->timestamp('broadcast_at')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        // ── LE RETOUR ARRIERE REFUSE DE PERDRE DES LIGNES ─────────────────
        //
        // Retrecir un enum dont des lignes portent la valeur retiree les
        // laisse dans un etat que le moteur refuse de relire — ou, selon les
        // versions de MySQL, les vide en silence. On s'arrete avant, en
        // disant quoi faire.
        $besoins = DB::table('customer_needs')->where('status', 'approved')->count();
        $notifs = DB::table('app_notifications')->where('recipient_type', 'partner')->count();

        if ($besoins > 0 || $notifs > 0) {
            throw new RuntimeException(
                "Retour arriere refuse : $besoins besoin(s) approuve(s) et $notifs "
                ."notification(s) de partenaire existent. Les reclasser ou les "
                .'supprimer explicitement avant de retirer ces valeurs.'
            );
        }

        Schema::table('customer_needs', function (Blueprint $table) {
            $table->dropColumn('broadcast_at');
        });

        $this->changerEnum('customer_needs', 'status', self::STATUTS_AVANT, 'pending');
        $this->changerEnum('app_notifications', 'recipient_type', self::DESTINATAIRES_AVANT);
    }

    /**
     * Elargir un enum, chaque moteur a sa facon.
     *
     * Meme forme que `modifyRoles()` de la migration des comptes partenaires,
     * et pour les memes raisons : MySQL a `MODIFY`, PostgreSQL une contrainte
     * `CHECK`, et SQLite ne sait rien changer sans reconstruire la table.
     *
     * @param  list<string>  $valeurs
     */
    private function changerEnum(string $table, string $colonne, array $valeurs, ?string $defaut = null): void
    {
        $liste = implode("','", $valeurs);

        switch (DB::getDriverName()) {
            case 'mysql':
            case 'mariadb':
                $suffixe = $defaut === null ? '' : " DEFAULT '$defaut'";
                DB::statement("ALTER TABLE $table MODIFY $colonne ENUM('$liste') NOT NULL$suffixe");
                break;

            case 'pgsql':
                DB::statement("ALTER TABLE $table DROP CONSTRAINT IF EXISTS {$table}_{$colonne}_check");
                DB::statement("ALTER TABLE $table ADD CONSTRAINT {$table}_{$colonne}_check CHECK ($colonne IN ('$liste'))");
                break;

            case 'sqlite':
                // SQLite reconstruit la table. Les cles etrangeres actives
                // dans une transaction empecheraient leur suspension et
                // pourraient declencher des cascades pendant le remplacement.
                if (DB::connection()->transactionLevel() > 0) {
                    throw new RuntimeException(
                        "La modification de $table.$colonne sous SQLite doit s'executer "
                        .'hors transaction afin de preserver les relations.'
                    );
                }

                Schema::withoutForeignKeyConstraints(function () use ($table, $colonne, $valeurs, $defaut) {
                    Schema::table($table, function (Blueprint $blueprint) use ($colonne, $valeurs, $defaut) {
                        $champ = $blueprint->enum($colonne, $valeurs);

                        if ($defaut !== null) {
                            $champ->default($defaut);
                        }

                        $champ->change();
                    });
                });
                break;

            default:
                throw new RuntimeException('Moteur de base non pris en charge pour la diffusion aux partenaires.');
        }
    }
};
