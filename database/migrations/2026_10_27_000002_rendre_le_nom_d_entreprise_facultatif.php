<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un partenaire n'est pas forcement une entreprise.
 *
 * ── CE QU'EXIGEAIT L'INSCRIPTION, ET CE QUE CA COUTAIT ────────────────────
 *
 * `company_name` etait `required` a l'inscription et NOT NULL en base. Or le
 * vendeur type au Burkina Faso est un particulier ou un petit revendeur qui
 * n'a pas de raison sociale : il inventait un nom, ou il abandonnait
 * l'inscription. Les deux sont des pertes, et la seconde est muette.
 *
 * ── POURQUOI LA COLONNE RESTE, ET POURQUOI L'API NE REND JAMAIS NULL ──────
 *
 * Nullable en base, oui. Nul dans la reponse publique, NON — et ce n'est pas
 * une precaution theorique. L'application PUBLIEE sur le Play Store lit le
 * nom du vendeur par un transtypage dur :
 *
 *   ProductSeller.fromJson : `json['company_name'] as String`
 *   PartnerSeller.fromJson : `json['company_name'] as String`
 *
 * Un null y leve un `TypeError` au moment d'afficher la fiche. Pire, la fiche
 * vehicule compose l'initiale du vendeur par `partner.companyName[0]` : une
 * chaine VIDE y leve un `RangeError`. Les deux font tomber l'ecran de detail
 * de l'annonce — l'ecran meme ou se trouve le numero a appeler.
 *
 * `PartnerListingPresentation::seller()` replie donc sur `contact_name`, qui
 * reste obligatoire. C'est le serveur qui tient cette garantie, et non une
 * future version de l'application : les telephones deja installes ne se
 * mettent pas a jour parce qu'on l'aurait souhaite.
 *
 * ── LES COMPTES EXISTANTS ─────────────────────────────────────────────────
 *
 * Ils gardent leur nom. Rendre une colonne nullable ne vide rien, et aucun
 * nom n'est devine ni reecrit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->string('company_name', 180)->nullable()->change();
        });
    }

    /**
     * Le retour arriere REFUSE de perdre des donnees.
     *
     * Remettre la colonne NOT NULL alors que des comptes l'ont laissee vide
     * echouerait au niveau du moteur, ou — pire selon les moteurs — remplirait
     * ces lignes d'une chaine vide, ce qui est exactement le `RangeError`
     * decrit plus haut. On s'arrete avant, en disant quoi faire.
     */
    public function down(): void
    {
        $sansNom = DB::table('partner_accounts')->whereNull('company_name')->count();

        if ($sansNom > 0) {
            throw new RuntimeException(
                "Retour arriere refuse : $sansNom compte(s) partenaire(s) n'ont pas de nom "
                ."d'entreprise. Leur en attribuer un explicitement avant de rendre la colonne "
                .'obligatoire.'
            );
        }

        Schema::table('partner_accounts', function (Blueprint $table) {
            $table->string('company_name', 180)->nullable(false)->change();
        });
    }
};
