<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Log;

class AutoParcContact
{
    private const PHONE_KEY = 'contact_phone';

    public function phone(): string
    {
        return $this->coordinates()['phone'];
    }

    /**
     * Le dernier recours, si meme la configuration est illisible.
     *
     * C'est le numero que le binaire publie sur le Play Store porte en dur
     * (lib/core/contact/autoparc_contact.dart), donc le seul qu'on puisse
     * servir sans mentir : il sonne deja chez AutoParc pour tout le parc
     * installe.
     */
    private const DERNIER_RECOURS = '+22675570484';

    /** @return array{phone: string, whatsapp: string} */
    public function coordinates(): array
    {
        // Une seule lecture pour que les deux liens d'une page utilisent le
        // meme enregistrement, meme si le BO change le numero pendant le rendu.
        $enregistre = AppSetting::query()->whereKey(self::PHONE_KEY)->value('value');

        if (($phone = self::normaliser($enregistre)) !== null) {
            return ['phone' => $phone, 'whatsapp' => substr($phone, 1)];
        }

        // Avant le premier enregistrement, conserver les reglages Render deja
        // en service. Ensuite le numero BO devient commun au web et au mobile.
        $configure = config('boutique.telephone');

        if (($phone = self::normaliser($configure)) !== null) {
            // `whatsapp` garde sa cle propre ICI seulement : avant le premier
            // enregistrement, une installation peut porter une ligne WhatsApp
            // distincte du telephone. On en retire tout ce qui n'est pas un
            // chiffre — wa.me n'interprete rien, et un numero mal forme ouvre
            // WhatsApp sur « numero invalide » au lieu de la conversation.
            $whatsapp = preg_replace('/\D+/', '', (string) config('boutique.whatsapp'));

            return [
                'phone' => $phone,
                'whatsapp' => $whatsapp !== '' ? $whatsapp : substr($phone, 1),
            ];
        }

        // ── POURQUOI CETTE TROISIEME BRANCHE EXISTE ───────────────────────
        //
        // Sans elle, un `BOUTIQUE_TELEPHONE` mal saisi sur Render — « 226... »
        // sans le « + », exactement la forme que porte la variable VOISINE
        // `BOUTIQUE_WHATSAPP` par defaut — faisait emettre a l'API un numero
        // que l'application refuse de lire. `AutoParcContact.fromJson` leve,
        // et l'ecran du personnel qui existe PRECISEMENT pour corriger le
        // numero affichait « Une erreur est survenue » avec un « Reessayer »
        // qui ne pouvait jamais aboutir. Une impasse sur l'ecran de
        // reparation, et aucun shell sur le plan gratuit pour en sortir.
        //
        // On sert donc le dernier recours, et on le DIT : sur une instance
        // sans shell, un diagnostic a distance ne tient qu'a ce que le
        // programme consent a raconter.
        Log::warning('Contact AutoParc : numero illisible, dernier recours servi', [
            'enregistre' => $enregistre,
            'configure' => $configure,
            'servi' => self::DERNIER_RECOURS,
        ]);

        return [
            'phone' => self::DERNIER_RECOURS,
            'whatsapp' => substr(self::DERNIER_RECOURS, 1),
        ];
    }

    /**
     * Le numero en E.164, ou `null` s'il n'est pas composable.
     *
     * La chaine vide est rejetee comme l'absence, et ce n'est pas theorique :
     * ce depot a deja paye ce defaut ailleurs — `PhotoPartage::cheminExploitable`
     * rejette `''` et `'0'` parce qu'une valeur vide avait fabrique un lien
     * casse. Un `tel:` sans numero, c'est un bouton d'appel qui ne fait rien.
     *
     * Les separateurs sont retires avant l'examen, pour la meme raison que
     * dans `UpdateContactRequest` : un responsable ecrit « +226 75 57 04 84 ».
     */
    private static function normaliser(?string $valeur): ?string
    {
        $candidat = preg_replace('/[\s().-]+/u', '', (string) $valeur);

        return preg_match('/^\+[1-9][0-9]{7,14}$/D', $candidat) === 1 ? $candidat : null;
    }

    public function updatePhone(string $phone): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::PHONE_KEY], ['value' => $phone]);
    }
}
