<?php

use Database\Seeders\EnginsIndustrielsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Verse les neuf engins qui portent les filtres industriels.
 *
 * Quatre marques de MACHINES — Caterpillar, John Deere, McCormick, JCB — et
 * neuf modeles, chacun avec la compatibilite que sa source autorise et le
 * RANG de cette source ecrit dans `part_fitments.notes`.
 *
 * Meme raison mecanique que `2026_10_24_000002` : `migrate --force` au
 * demarrage est le seul point d'entree qui s'execute cote serveur. Render ne
 * joue pas les seeders et le plan gratuit n'offre aucun acces shell.
 *
 * ELLE NE LEVE JAMAIS D'EXCEPTION : le CMD du Dockerfile enchaine
 * `migrate --force && ... && php artisan serve`. Neuf engins absents sont un
 * desagrement, une API eteinte est une panne.
 *
 * ── CE QU'ELLE SUPPOSE, ET CE QU'ELLE NE SUPPOSE PAS ───────────────────────
 *
 * Elle suppose que les onze filtres sont deja en base — ils le sont, par
 * `2026_10_24_000002`, qui porte un numero inferieur et passe donc avant. Si
 * une piece manquait malgre tout, le seeder cree la machine sans la
 * compatibilite et le dit : un engin vaut par lui-meme dans le selecteur.
 *
 * Elle ne suppose RIEN des categories de pieces, puisqu'elle n'en cree
 * aucune. L'erreur commise sur `2026_10_24_000002` — lancer
 * `PartCategoriesSeeder` et polluer les 49 tests qui creent leurs propres
 * categories — n'est pas reproductible ici.
 *
 * Idempotente : marques et modeles retrouves par slug, compatibilites
 * remplacees et non empilees. Mesure par `FiltresIndustrielsTest` et
 * `EnginsIndustrielsTest`.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            Artisan::call('db:seed', [
                '--class' => EnginsIndustrielsSeeder::class,
                '--force' => true,
            ]);

            echo Artisan::output();
        } catch (\Throwable $e) {
            echo '  ATTENTION : les engins industriels n\'ont pas ete verses ('.$e->getMessage().').'.PHP_EOL;
            echo '  Le deploiement continue.'.PHP_EOL;
        }
    }

    /**
     * Les neuf machines partent, mais pas celles qu'on a touchees depuis.
     *
     * On ne retire que les modeles restes EXACTEMENT dans l'etat ou cette
     * migration les a crees : actifs, et sans vehicule ni annonce rattache.
     * Un modele sur lequel quelqu'un a enregistre un vehicule reste, sinon le
     * retour en arriere emporterait la fiche d'un client.
     *
     * Les marques, elles, ne sont jamais supprimees : rien ne garantit que
     * personne n'en a cree d'autres modeles entre-temps, et une marque vide
     * ne coute rien.
     */
    public function down(): void
    {
        $slugs = [
            'cat-de150-gc',
            'jd-5090m', 'jd-5100m', 'jd-5115m',
            'mccormick-mtx-110', 'mccormick-mtx-125', 'mccormick-mtx-140',
            'jcb-3cx', 'jcb-3dx',
        ];

        \App\Models\VehicleModel::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->whereDoesntHave('vehicles')
            ->each(function ($modele) {
                \Illuminate\Support\Facades\DB::table('part_fitments')
                    ->where('vehicle_model_id', $modele->id)
                    ->delete();

                $modele->delete();
            });
    }
};
