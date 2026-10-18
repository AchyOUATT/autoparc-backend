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
    /**
     * Le plafond des pages de partage, par adresse ET pour tout le monde.
     *
     * Un seul chiffre pour les deux limites : voir le detail dans
     * registerRateLimiters(). Nomme pour que le test puisse le lire au lieu de
     * recopier 300, et ne plus rien prouver le jour ou il change.
     */
    public const PARTAGE_PAR_MINUTE = 300;

    /**
     * La cle du plafond global des pages de partage.
     *
     * Constante, et c'est tout son interet : aucune valeur de la requete n'y
     * entre, donc aucun en-tete n'en sort. Mesure du defaut qu'elle corrige :
     * avec la seule cle par adresse, 315 requetes a X-Forwarded-For variable
     * laissaient X-RateLimit-Remaining a 299.
     */
    public const PARTAGE_CLE_GLOBALE = 'partage:tout-le-monde';

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->exigerUneAdressePubliqueAbsolue();
        $this->registerRateLimiters();
        $this->enregistrerRechercheInsensibleALaCasse();
    }

    /**
     * En production, l'application REFUSE de demarrer sans adresse publique.
     *
     * Le defaut corrige ici n'etait pas l'oubli d'APP_URL, c'etait son silence.
     * render.yaml declare la cle `sync: false` ; laissee blanche dans le
     * tableau de bord, Render la transmet DEFINIE ET VIDE, et le defaut du
     * second argument d'env() ne s'applique qu'a l'absence. Mesure, serveur
     * demarre avec APP_URL= :
     *
     *     HTTP 200
     *     <meta property="og:url" content="/v/1">
     *     <link rel="canonical" href="/v/1">
     *     <meta property="og:image" content="/storage/vehicles/1/photo.jpg">
     *     lien WhatsApp = https://wa.me/226...?text=...%0A%2Fv%2F1
     *
     * Une page qui rend 200, parait normale, n'ecrit rien dans aucun journal,
     * et dont tous les apercus sont muets pour toujours. C'est le mode de
     * defaillance exact que les pages de partage existent pour supprimer.
     *
     * Le repli non vide de config/app.php rend la valeur utilisable ; il ne
     * rend pas l'oubli visible — « http://localhost » en ligne envoie les
     * aperçus vers une adresse injoignable. D'ou ce controle, qui refuse les
     * trois formes du meme oubli : vide, relative, et l'hote local.
     *
     * POSE ICI, et pas ailleurs, pour trois raisons mesurees :
     *
     *   — le Dockerfile commence par `php artisan config:cache` : le provider
     *     est demarre a ce moment-la, donc l'oubli arrete le deploiement avant
     *     la premiere migration et avant la premiere requete. Render conserve
     *     la version precedente et affiche le message dans son visualiseur ;
     *   — la garde ne vaut QU'EN production (`APP_ENV=production`). phpunit.xml
     *     pose APP_ENV=testing : les 464 tests ne la voient pas, et le poste de
     *     developpement, en `local`, continue de tourner sans APP_URL ;
     *   — elle ne regarde que la configuration, donc aucune commande artisan
     *     saine n'en souffre : sur un deploiement correctement renseigne, elle
     *     ne fait rien du tout.
     *
     * @throws \RuntimeException
     */
    private function exigerUneAdressePubliqueAbsolue(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        $url = trim((string) config('app.url'));
        $hote = strtolower((string) parse_url($url, PHP_URL_HOST));

        $valable = $url !== ''
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)
            && $hote !== ''
            && ! in_array($hote, ['localhost', '127.0.0.1', '::1'], true);

        if ($valable) {
            return;
        }

        throw new \RuntimeException(
            'APP_URL est obligatoire en production et doit etre absolue : '
            ."valeur lue « {$url} ». Les pages de partage /v, /p et /a composent "
            .'og:url, <link rel="canonical"> et le lien WhatsApp depuis cette '
            .'valeur. Vide ou relative, elles rendent 200 avec un og:image '
            .'relatif — aucune vignette, aucune trace. Posez l\'URL Render du '
            .'service, sans / final, AVANT le premier deploiement (render.yaml).'
        );
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

        // ── Pages de partage d'un article ───────────────────────────────────
        //
        // Le groupe 'api' porte throttle:api ; le groupe 'web' n'en portait
        // aucun, et /v, /p, /a sont les premiers points d'entree publics qui
        // interrogent la base hors de l'API. Les identifiants y sont
        // sequentiels, donc enumerables. Mesure avec CACHE_STORE=array, journal
        // de requetes releve sur une fiche AUX RELATIONS REMPLIES — marque,
        // modele, finition, motorisation, couleur, ville, et les deux blocs de
        // kilometrage pour le vehicule ; categorie, fabricant et ville pour la
        // piece :
        //
        //     /v/1 -> 10 requetes SQL
        //     /p/1 ->  5 requetes SQL
        //     /a/1 ->  4 requetes SQL
        //
        // Une boucle de /v/1 a /v/20000 represente donc 200 000 requetes SQL
        // sur l'instance gratuite. Le commentaire qui tenait cette place
        // annoncait 8 pour /v et 160 000 pour la boucle : c'etait sous-compte
        // d'une requete sur cinq. Le detail des relations est dans
        // routes/partage.php.
        //
        // Le compte DEPEND des relations remplies, et c'est pourquoi la mesure
        // le precise : sur la meme fiche sans ville ni fabricant, /p/1 tombe a
        // 3 requetes et /v/1 a 9, parce qu'Eloquent n'interroge pas une table
        // dont la cle etrangere est nulle.
        //
        // DEUX LIMITES SUPERPOSEES, et la seconde n'est pas une precaution :
        // la premiere, seule, ne bornait RIEN.
        //
        // Le commentaire qui tenait cette place annoncait qu'une enumeration
        // mettrait « plus d'une heure pour 20 000 fiches ». La mesure dit le
        // contraire. `by($request->ip())` lit l'adresse que l'APPELANT annonce,
        // puisque bootstrap/app.php pose trustProxies(at: '*') — choix assume
        // et commente la-bas pour $request->ip(). Mesure, un
        // X-Forwarded-For different a chaque requete :
        //
        //     315 requetes -> HTTP 200 partout, X-RateLimit-Remaining : 299
        //
        // Remaining ne descend jamais. L'enumeration n'etait genee d'aucune
        // seconde ; seul un client HONNETE, qui ne triche pas sur son adresse,
        // etait plafonne. Le compteur fonctionne (meme adresse : 299, 298,
        // 297) — c'est la CLE que l'appelant choisit.
        //
        // La seconde limite porte donc une cle constante : il n'existe aucun
        // en-tete qui en sorte. Elle vaut le MEME chiffre que la premiere, et
        // ce choix est mesure, pas prudent :
        //
        //   — CE PLAFOND EST AU-DESSUS DU DEBIT DE LA MACHINE, et il faut le
        //     dire ainsi : ce n'est pas une protection contre la saturation,
        //     c'est un arbitrage. Mesure sur le poste de developpement, 30
        //     requetes enchainees dans un seul curl pour ne pas compter le
        //     demarrage du client, trois passages :
        //
        //         /v/1 -> 0,277 a 0,302 s par page, soit 198 a 217 pages/minute
        //         /p/1 -> 0,270 a 0,290 s par page, soit 207 a 220 pages/minute
        //         /a/1 -> 0,271 a 0,279 s par page, soit 215 a 222 pages/minute
        //
        //     LES TROIS NE SE SEPARENT PRESQUE PAS, et c'est l'enseignement :
        //     l'ecart entre /v et /p vaut 2 a 12 ms par page la ou le compte de
        //     requetes SQL est du double. Le cout d'une page de partage n'est
        //     pas dans la base, il est dans le reste — amorcage de Laravel,
        //     rendu de la vue, aller-retour HTTP.
        //
        //     Le commentaire qui tenait cette place annoncait 0,356 s pour /v
        //     et 0,457 s pour /p, donc /v NETTEMENT PLUS RAPIDE que /p. Ni les
        //     valeurs ni l'ordre ne se reproduisent : /v est systematiquement
        //     la plus lente des trois, de peu, ce qui est le sens attendu
        //     puisqu'elle fait dix requetes contre cinq.
        //
        //     Le Dockerfile sert la production par le MEME `php artisan serve`,
        //     c'est-a-dire un unique processus qui traite une requete a la
        //     fois, sur une instance gratuite qui partage son processeur :
        //     moins encore. Les 300 ne seront donc jamais atteints par du
        //     trafic qui attend une reponse — la machine s'effondre avant.
        //     Ce qui est choisi ici, c'est de NE PAS gener, pas de proteger ;
        //     qui cherche la protection doit regarder du cote du nombre de
        //     processus, pas de ce chiffre ;
        //   — les deux usages a ne jamais gener restent couverts, parce qu'ils
        //     etaient deja bornes a 300 par la premiere limite : le robot, qui
        //     demande la page plusieurs fois d'affilee quand un vendeur colle
        //     sa liste, et le lien viral dont les lecteurs arrivent derriere le
        //     NAT d'un operateur mobile et partagent UNE adresse pour des
        //     centaines de telephones.
        //
        // Ce que cela coute, parce qu'il faut le dire : un appelant qui sature
        // le seau global fait repondre 429 aux trois routes POUR TOUT LE MONDE
        // jusqu'a la fin de la minute. C'est le defaut que la confiance aux
        // mandataires evitait justement pour les autres limites. Il est accepte
        // ici, et seulement ici, pour trois raisons : l'alternative mesuree
        // n'est pas « un peu moins de protection » mais AUCUNE ; la machine ne
        // peut pas atteindre ce plafond en servant du trafic honnete ; et une
        // minute de 429 vaut mieux qu'une instance saturee, qui refuse tout
        // aussi, plus longtemps et sans le dire.
        //
        // Les en-tetes X-RateLimit-* annoncent la plus CONTRAIGNANTE des deux,
        // et non la derniere : ThrottleRequests::getHeaders() refuse de
        // remplacer un « Remaining » deja pose par une valeur plus haute.
        // Mesure apres correction, six adresses jamais vues d'affilee :
        // Remaining 298, 297, 296, 295, 294, 293 — le compteur suit desormais
        // l'appelant quel que soit l'en-tete, la ou il restait fige a 299.
        // L'ordre du tableau ne decide donc que de la limite qui leve, et donc
        // du Retry-After rendu.
        //
        // Reponse HTML et non JSON : ces pages sont lues dans un navigateur,
        // et le rendu JSON des erreurs ne vaut que pour 'api/*'.
        RateLimiter::for('partage', fn (Request $request) => [
            Limit::perMinute(self::PARTAGE_PAR_MINUTE)->by(self::PARTAGE_CLE_GLOBALE),
            Limit::perMinute(self::PARTAGE_PAR_MINUTE)->by($request->ip()),
        ]);
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
