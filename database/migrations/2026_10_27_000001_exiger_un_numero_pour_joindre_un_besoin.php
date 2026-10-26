<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De quoi RAPPELER la personne qui depose un besoin.
 *
 * ── CE QUI MANQUAIT, ET CE N'ETAIT PAS « PAS OBLIGATOIRE » ─────────────────
 *
 * `customer_needs` portait deja `contact_phone`, `contact_name` et
 * `contact_email`, tous nullables. Le modele Flutter les LISAIT, la liste du
 * personnel les AFFICHAIT. Mais `StoreCustomerNeedRequest` ne validait AUCUN
 * des trois, et le controleur fait `CustomerNeed::create($request->validated())`
 * — donc un numero envoye par le client etait jete en silence, et la colonne
 * restait nulle.
 *
 * Et `submit_need_page.dart` ne l'envoyait meme pas : la charge se limitait a
 * `type`, `description`, `budget_max` et le `firebase_uid` quand il y en avait
 * un. La route `POST /api/needs` est publique, donc le deposant peut etre
 * anonyme.
 *
 * Resultat mesure : un visiteur depose un besoin, tout le personnel reçoit une
 * notification « Nouveau besoin client », et PERSONNE ne peut le rappeler. Le
 * seul fil etait `firebase_uid`, nullable lui aussi.
 *
 * ── POURQUOI UNE COLONNE DE PLUS, ET NON L'ELARGISSEMENT DE L'ANCIENNE ────
 *
 * `contact_phone` est un `string(30)` : un seul numero. On en veut plusieurs —
 * au Burkina Faso un acheteur donne volontiers deux lignes, de deux operateurs,
 * parce que l'une passe quand l'autre ne passe pas.
 *
 * `contact_phones` porte donc la liste, et `contact_phone` RESTE renseigne avec
 * le premier numero. Ce n'est pas une redondance paresseuse : l'application
 * PUBLIEE sur le Play Store lit `contact_phone` et ignore tout le reste. Cesser
 * de l'ecrire ferait disparaitre le numero de l'ecran du personnel qui est
 * aujourd'hui installe, et c'est precisement l'ecran qui sert a rappeler
 * quelqu'un.
 *
 * ── LES ANCIENNES LIGNES ───────────────────────────────────────────────────
 *
 * Elles restent telles quelles, `contact_phones` a null. Rien n'est devine : un
 * besoin depose sans numero n'en avait pas, et en inventer un depuis une
 * colonne vide n'avancerait personne. Le personnel les voit comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->json('contact_phones')->nullable()->after('contact_phone');
        });
    }

    public function down(): void
    {
        Schema::table('customer_needs', function (Blueprint $table) {
            $table->dropColumn('contact_phones');
        });
    }
};
