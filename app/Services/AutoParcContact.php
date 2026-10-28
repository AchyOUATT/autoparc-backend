<?php

namespace App\Services;

use App\Models\AppSetting;

class AutoParcContact
{
    private const PHONE_KEY = 'contact_phone';

    public function phone(): string
    {
        return $this->coordinates()['phone'];
    }

    /** @return array{phone: string, whatsapp: string} */
    public function coordinates(): array
    {
        // Une seule lecture pour que les deux liens d'une page utilisent le
        // meme enregistrement, meme si le BO change le numero pendant le rendu.
        $phone = AppSetting::query()->whereKey(self::PHONE_KEY)->value('value');

        if ($phone !== null) {
            return ['phone' => $phone, 'whatsapp' => ltrim($phone, '+')];
        }

        // Avant le premier enregistrement, conserver les reglages Render deja
        // en service. Ensuite le numero BO devient commun au web et au mobile.
        $phone = (string) config('boutique.telephone');

        return [
            'phone' => $phone,
            'whatsapp' => (string) (config('boutique.whatsapp') ?: $phone),
        ];
    }

    public function updatePhone(string $phone): void
    {
        AppSetting::query()->updateOrCreate(['key' => self::PHONE_KEY], ['value' => $phone]);
    }
}
