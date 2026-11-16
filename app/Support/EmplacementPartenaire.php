<?php

namespace App\Support;

use App\Models\PartnerAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * L'emplacement d'un partenaire, et la ville des annonces.
 *
 * Le defaut vit sur le compte. Chaque annonce fige le sien a la
 * creation : un changement de defaut ne reecrit pas ce qui est
 * deja en ligne. A la modification, un champ omis garde sa valeur
 * enregistree ; un null explicite l'efface.
 */
final class EmplacementPartenaire
{
    /**
     * Recopie le defaut du compte sur une CREATION dont la ville
     * ou le lieu ont ete omis. Une modification n'est pas touchee.
     *
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    public static function appliquer(array $donnees, ?Model $existant, PartnerAccount $compte): array
    {
        if ($existant !== null) {
            return $donnees;
        }

        // `city` du compte -> `city` de l'annonce.
        // `address` du compte -> `site` de l'annonce (le lieu, deja
        // present sur les vehicules).
        if (! array_key_exists('city', $donnees)) {
            $donnees['city'] = $compte->city;
        }
        if (! array_key_exists('site', $donnees)) {
            $donnees['site'] = $compte->address;
        }

        return $donnees;
    }

    /** Les annonces dont la ville, ou celle de leur agence, correspond. */
    public static function filtrerParVille(Builder $requete, string $ville): Builder
    {
        return $requete->where(function (Builder $groupe) use ($ville, $requete) {
            $groupe->where($requete->getModel()->qualifyColumn('city'), $ville)
                ->orWhereHas('location', fn (Builder $agence) => $agence->where('city', $ville));
        });
    }
}
