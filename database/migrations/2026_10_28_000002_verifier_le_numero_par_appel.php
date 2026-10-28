<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le numero que le vendeur SAISIT, distinct de celui qu'on a VERIFIE.
 *
 * CE QUI CHANGE, ET POURQUOI DEUX COLONNES PLUTOT QU'UNE
 *
 * La verification par SMS est abandonnee : le personnel appellera le vendeur
 * pendant la moderation. Le numero n'arrive donc plus de la revendication
 * `phone_number` d'un jeton Firebase, mais du formulaire.
 *
 * C'etait la piece la plus solide du module : `submit()` ne lisait le numero
 * que dans le jeton, et `verified_phone` comme `phone_verified_at` sont dans la
 * liste `prohibited` de `validateFields`, donc le formulaire ne pouvait
 * STRUCTURELLEMENT pas poser la preuve. Un simple glissement du numero vers le
 * formulaire aurait detruit cette propriete : un vendeur aurait pu se declarer
 * verifie lui-meme.
 *
 * D'ou deux colonnes, et c'est tout l'objet de cette migration :
 *
 *   `phone`                        ce que le vendeur a tape. Non verifie.
 *                                  Le formulaire l'ecrit, comme `seller_name`.
 *
 *   `verified_phone`               ce qu'un moderateur a confirme en appelant.
 *   `phone_verified_at`            Restent dans `prohibited` : seul
 *                                  `approve()` les ecrit, et il exige que le
 *                                  moderateur affirme avoir passe l'appel.
 *
 * La preuve passe donc d'un mecanisme a un geste humain — c'est une perte
 * assumee —, mais elle ne passe JAMAIS par le formulaire. Et l'ecart entre les
 * deux colonnes est ce qui permet de reclamer un nouvel appel quand le vendeur
 * change de numero apres coup, au lieu de lui laisser porter une verification
 * qui concernait une autre ligne.
 *
 * Nullable, et 20 caracteres comme `verified_phone` : le format impose est
 * E.164 (`+22670000000`, treize signes pour le Burkina), la marge couvre les
 * indicatifs plus longs. Nullable parce que les annonces creees avant cette
 * migration n'en ont pas, et parce qu'un brouillon tout juste cree n'en a pas
 * encore — c'est `submit()` qui l'exige, pas la base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('seller_name');
        });
    }

    public function down(): void
    {
        Schema::table('private_vehicle_listings', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
