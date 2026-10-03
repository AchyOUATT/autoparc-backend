<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->registerRateLimiters();
        $this->enregistrerRechercheInsensibleALaCasse();
    }

    /**
     * `whereLike` et `orWhereLike` : une recherche qui ignore la casse partout.
     *
     * Le defaut etait invisible en developpement et bien reel en ligne. MySQL
     * compare `LIKE` sans tenir compte de la casse ; PostgreSQL, lui, la
     * distingue. Le poste de developpement tourne sous MySQL, la production
     * sous PostgreSQL : chercher « filtre » rendait dix resultats en local et
     * deux en ligne, les deux seuls dont le libelle portait le mot en
     * minuscule. « cartouche » n'en rendait aucun.
     *
     * Quinze appels a `where(..., 'like', ...)` etaient concernes, dans huit
     * fichiers — pieces, accessoires, vehicules, partenaires, clients,
     * marques. Les corriger un par un aurait laisse le prochain repartir du
     * mauvais pied : le defaut est dans l'operateur, la correction doit y etre
     * aussi.
     *
     * `ILIKE` est propre a PostgreSQL, d'ou le choix selon le pilote. SQLite,
     * employe par les tests, ignore deja la casse sur l'ASCII.
     */
    private function enregistrerRechercheInsensibleALaCasse(): void
    {
        $operateur = function ($requete): string {
            return $requete->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        };

        foreach ([EloquentBuilder::class, QueryBuilder::class] as $builder) {
            $builder::macro('whereLike', function (string $colonne, string $valeur) use ($operateur) {
                return $this->where($colonne, $operateur($this), $valeur);
            });

            $builder::macro('orWhereLike', function (string $colonne, string $valeur) use ($operateur) {
                return $this->orWhere($colonne, $operateur($this), $valeur);
            });
        }
    }

    /**
     * Limites de debit de l'API.
     *
     * Il n'y en avait aucune : Laravel 11 a retire le `throttle` pose d'office
     * sur le groupe api, et rien ne l'a remplace. Concretement, la page de
     * connexion acceptait les tentatives de mot de passe a la cadence du
     * reseau, et un inconnu pouvait soumettre des besoins en boucle — chacun
     * notifiant l'ensemble du personnel.
     *
     * Les capacites par role protegent d'un employe curieux. Elles ne
     * protegent pas d'un script qui devine le mot de passe administrateur, un
     * compte qui possede justement toutes les capacites.
     */
    private function registerRateLimiters(): void
    {
        // ── Connexion ────────────────────────────────────────────────────────
        //
        // Deux limites superposees. La premiere vise l'attaque classique :
        // beaucoup de mots de passe sur un meme compte. La seconde vise celle
        // qui contourne la premiere en changeant d'adresse a chaque essai.
        //
        // La cle inclut l'adresse IP : sans elle, un tiers pourrait bloquer la
        // connexion d'un employe simplement en echouant a sa place.
        //
        // Dix essais, et non cinq : l'application n'a plus qu'un formulaire de
        // connexion, et essaie Firebase avant le compte serveur. Chaque echec
        // cote client vient donc frapper ici aussi — quelqu'un qui se trompe
        // trois fois de mot de passe atteindrait sinon le plafond sans motif.
        // Dix par minute reste sans usage pour deviner un mot de passe, et le
        // plafond par adresse ci-dessous borne la machine entiere.
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(10)
                ->by(Str::lower((string) $request->input('email')).'|'.$request->ip())
                ->response($this->refus(
                    'Trop de tentatives de connexion. Patientez une minute avant de reessayer.'
                )),
            Limit::perMinute(20)
                ->by($request->ip())
                ->response($this->refus(
                    'Trop de tentatives depuis cet appareil. Patientez une minute.'
                )),
        ]);

        // ── Ecritures ouvertes a tous ────────────────────────────────────────
        //
        // Soumettre un besoin ne demande aucun compte — c'est voulu, un
        // acheteur doit pouvoir se manifester sans s'inscrire. Mais chaque
        // soumission notifie tout le personnel : sans plafond, n'importe qui
        // peut noyer les notifications de l'equipe.
        RateLimiter::for('public-write', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip())
            ->response($this->refus(
                'Trop de demandes envoyees. Patientez une minute avant de reessayer.'
            )));

        // Le declencheur de taches planifiees. Trois par minute : l'ordonnanceur
        // appelle une fois par jour, et la tache balaie le parc entier — rien ne
        // justifie d'en accepter davantage. Par IP, puisque l'appelant n'a pas
        // de compte.
        RateLimiter::for('scheduled-tasks', fn (Request $request) => Limit::perMinute(3)
            ->by($request->ip())
            ->response($this->refus(
                'Trop de declenchements. Une tache planifiee ne se lance pas en rafale.'
            )));

        // ── Plafond general ──────────────────────────────────────────────────
        //
        // Large a dessein : il ne s'agit pas de rationner un usage normal mais
        // d'empecher qu'une application partie en boucle ne sature le serveur.
        // Par compte connecte plutot que par IP, plusieurs employes pouvant
        // partager une meme connexion.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->id ?: $request->ip())
            ->response($this->refus(
                'Trop de requetes. Patientez un instant.'
            )));
    }

    /** Reponse 429 lisible, en conservant les en-tetes calcules par Laravel. */
    private function refus(string $message): \Closure
    {
        return fn (Request $request, array $headers) => response()->json(
            ['message' => $message],
            429,
            $headers,
        );
    }
}
