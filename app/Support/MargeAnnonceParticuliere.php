<?php

namespace App\Support;

use App\Models\AppSetting;
use App\Models\AppSettingChange;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Les frais AutoParc sur le prix propose par un particulier.
 *
 * Le pourcentage est ajoute au prix saisi. La colonne `price` stocke le total
 * public, et `private_pricing` fige le prix propose, le taux et la date.
 * Changer le reglage ne reprend pas les annonces deja enregistrees.
 */
final class MargeAnnonceParticuliere
{
    public const SETTING_KEY = 'private_listing_margin_percent';

    public const TAUX_MAX = 50.0;

    public static function taux(): float
    {
        $valeur = AppSetting::query()->whereKey(self::SETTING_KEY)->value('value');
        if ($valeur === null || $valeur === '') {
            return 0.0;
        }

        return min(self::TAUX_MAX, max(0.0, (float) $valeur));
    }

    /**
     * Le prix majore, arrondi au franc le plus proche.
     *
     * Le franc CFA n'a pas de subdivision en usage. On reprend la meme
     * regle que {@see MargePartenaire::majorer()}.
     */
    public static function majorer(float $prix, float $taux): int
    {
        return (int) round($prix * (1 + $taux / 100));
    }

    /**
     * @param  array<string, mixed>  $donnees
     * @param  array<string, mixed>|null  $detailExistant
     * @return array<string, mixed>
     */
    public static function appliquer(array $donnees, ?array $detailExistant = null, ?int $prixPublicStocke = null): array
    {
        if (! array_key_exists('price', $donnees) || $donnees['price'] === null) {
            return $donnees;
        }

        $propose = (int) $donnees['price'];
        $proposeFige = isset($detailExistant['prix_propose']) ? (int) $detailExistant['prix_propose'] : null;

        // Sans changement de prix, on conserve le calcul fige. Un ancien
        // client renvoie le prix PUBLIC ; un client a jour renvoie le net.
        $inchange = ($prixPublicStocke !== null && $propose === $prixPublicStocke)
            || ($proposeFige !== null && $propose === $proposeFige);

        if ($inchange) {
            if (is_array($detailExistant) && isset($detailExistant['prix_propose'])) {
                $donnees['private_pricing'] = $detailExistant;
                $donnees['price'] = $prixPublicStocke ?? self::majorer(
                    (int) $detailExistant['prix_propose'],
                    (float) ($detailExistant['taux'] ?? 0),
                );
            }

            return $donnees;
        }

        $taux = self::taux();
        $donnees['price'] = self::majorer($propose, $taux);
        $donnees['private_pricing'] = [
            'prix_propose' => $propose,
            'taux' => $taux,
            'applique_le' => now()->toIso8601String(),
        ];

        return $donnees;
    }

    /**
     * @return array{taux: float, derniere_modification: ?array{taux_precedent: ?float, taux: float, modifie_par: ?array{id: int, name: string}, modifie_le: string}}
     */
    public static function etatAdmin(): array
    {
        $changement = AppSettingChange::query()
            ->where('key', self::SETTING_KEY)
            ->with('user:id,name')
            ->latest('id')
            ->first();

        return [
            'taux' => self::taux(),
            'derniere_modification' => $changement === null ? null : [
                'taux_precedent' => $changement->old_value === null || $changement->old_value === ''
                    ? null
                    : (float) $changement->old_value,
                'taux' => (float) $changement->new_value,
                'modifie_par' => $changement->user === null ? null : [
                    'id' => (int) $changement->user->id,
                    'name' => $changement->user->name,
                ],
                'modifie_le' => $changement->created_at->toIso8601String(),
            ],
        ];
    }

    public static function enregistrer(float $taux, User $acteur): void
    {
        $nouveau = self::normaliser($taux);
        $actuel = AppSetting::query()->whereKey(self::SETTING_KEY)->value('value');
        if ($actuel !== null && self::normaliser((float) $actuel) === $nouveau) {
            return;
        }

        DB::transaction(function () use ($nouveau, $actuel, $acteur) {
            AppSetting::query()->updateOrCreate(
                ['key' => self::SETTING_KEY],
                ['value' => $nouveau],
            );
            AppSettingChange::query()->create([
                'key' => self::SETTING_KEY,
                'old_value' => $actuel,
                'new_value' => $nouveau,
                'user_id' => $acteur->id,
                'created_at' => now(),
            ]);
        });
    }

    public static function normaliser(float $taux): string
    {
        $borne = min(self::TAUX_MAX, max(0.0, $taux));

        return $borne == (int) $borne ? (string) (int) $borne : rtrim(rtrim(sprintf('%.1f', $borne), '0'), '.');
    }
}
