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

    /**
     * Les plafonds GLOBAUX des deux portes publiques qui n'en avaient pas.
     *
     * Memes clés constantes que `PARTAGE_CLE_GLOBALE`, et pour exactement la
     * meme raison : aucune valeur de la requete n'y entre, donc aucun en-tete
     * n'en sort. Voir les commentaires de `login` et de `public-write`.
     *
     * Les valeurs sont larges a dessein. Elles ne sont pas la pour gener
     * l'usage normal — une entreprise d'une dizaine d'employes et quelques
     * partenaires n'approche jamais 300 connexions par minute — mais pour
     * qu'un plafond EXISTE la ou il n'y en avait aucun.
     */
    public const LOGIN_PAR_MINUTE_GLOBAL = 300;

    public const LOGIN_CLE_GLOBALE = 'login:tout-le-monde';

    /**
     * Essais par minute sur UN compte donne, toutes adresses confondues.
     *
     * C'est la cle qui compte le plus : elle ne contient que l'adresse e-mail
     * visee, donc rien que l'appelant puisse faire varier pour s'y soustraire.
     * Trente laisse place a quelqu'un qui se trompe plusieurs fois de suite,
     * et ferme la devinette automatisee.
     */
    public const LOGIN_PAR_COMPTE_PAR_MINUTE = 30;

    public const ECRITURE_PUBLIQUE_PAR_MINUTE_GLOBAL = 120;

    public const ECRITURE_PUBLIQUE_CLE_GLOBALE = 'ecriture-publique:tout-le-monde';

    /**
     * Photos par minute pour UN vendeur.
     *
     * Une annonce accepte dix photos et le formulaire en envoie une par
     * requete. Vingt, c'est donc le lot complet plus un reessai entier du
     * lot : le plafond ne doit jamais tomber sur un vendeur qui fait ce que
     * l'ecran lui propose, et le vrai plafond du nombre de photos est tenu
     * par le controleur (`max:10` par requete, puis
     * `photos()->count() + count(...) > 10` qui rend 422).
     */
    public const DEPOT_DE_PHOTOS_PAR_MINUTE = 20;

    /**
     * Photos par minute pour tout le monde.
     *
     * Ici le plafond ne protege pas contre un abus mais contre la physique de
     * l'hebergement : `php artisan serve` est mono-processus et serialise la
     * phase script, et docker/php/depot-de-photos.ini mesure 8,76 s pour dix
     * photos de 2048 px — soit environ 0,9 s de script bloque par photo.
     * Soixante par minute, c'est deja 54 des 60 secondes passees a reencoder.
     * Au-dela, l'instance ne sert plus personne : un 429 qui dit d'attendre
     * une minute vaut mieux qu'un delai d'attente que personne ne comprend.
     */
    public const DEPOT_DE_PHOTOS_PAR_MINUTE_GLOBAL = 60;

    public const DEPOT_DE_PHOTOS_CLE_GLOBALE = 'depot-de-photos:tout-le-monde';

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
        // ── LES DEUX PLAFONDS CI-DESSOUS NE SUFFISAIENT PAS ──────────────
        //
        // Ils sont tous deux cles sur l'adresse IP, et `bootstrap/app.php:63`
        // pose `trustProxies(at: '*')` : Laravel lit alors l'entree la plus a
        // GAUCHE de `X-Forwarded-For`, c'est-a-dire celle que l'appelant
        // fournit lui-meme. Un en-tete different a chaque requete donnait donc
        // une cle differente, et le plafond ne mordait jamais.
        //
        // Verification faite le 24/10/2026, et conservee :
        // `RateLimitTest::test_un_compte_vise_depuis_mille_adresses_reste_plafonne`
        // envoie 30 essais sur un meme compte avec une adresse neuve a chaque
        // fois. Les deux limites par adresse n'en refusent AUCUN — elles sont
        // toutes neuves a chaque tour. En neutralisant la limite par compte
        // ajoutee ci-dessous, le 31e essai est examine lui aussi : c'est le
        // defaut, reproduit. Avec elle, il rend 429.
        //
        // Le projet connaissait deja ce defaut et l'avait corrige — pour les
        // SEULES pages de partage (voir PARTAGE_CLE_GLOBALE plus haut, et son
        // commentaire). `login` n'avait jamais recu la compagne, alors que
        // c'est la porte qui donne un jeton de PERSONNEL.
        //
        // Les deux limites ajoutees ci-dessous portent des cles qu'aucun
        // en-tete n'atteint :
        //
        //   - par ADRESSE EMAIL seule : borne les essais contre UN compte,
        //     quelle que soit l'adresse revendiquee. C'est celle qui ferme
        //     l'attaque par force brute ciblee.
        //   - GLOBALE, cle constante : borne la machine entiere.
        //
        // COMPROMIS ASSUME sur la premiere. Le commentaire d'origine ci-dessus
        // explique que l'IP figure dans la cle pour qu'un tiers ne puisse pas
        // bloquer un employe en echouant a sa place. C'est vrai, et une limite
        // par email seule reintroduit ce risque. Mais une minute de gene vaut
        // mieux qu'un nombre d'essais illimite sur le compte d'un
        // administrateur, et 30 par minute laisse largement place a quelqu'un
        // qui se trompe plusieurs fois de suite.
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
            Limit::perMinute(self::LOGIN_PAR_COMPTE_PAR_MINUTE)
                ->by('login:compte:'.Str::lower((string) $request->input('email')))
                ->response($this->refus(
                    'Trop de tentatives sur ce compte. Patientez une minute avant de reessayer.'
                )),
            Limit::perMinute(self::LOGIN_PAR_MINUTE_GLOBAL)
                ->by(self::LOGIN_CLE_GLOBALE)
                ->response($this->refus(
                    'Trop de tentatives de connexion en cours. Patientez une minute.'
                )),
        ]);

        // ── Ecritures ouvertes a tous ────────────────────────────────────────
        //
        // Soumettre un besoin ne demande aucun compte — c'est voulu, un
        // acheteur doit pouvoir se manifester sans s'inscrire. Mais chaque
        // soumission notifie tout le personnel : sans plafond, n'importe qui
        // peut noyer les notifications de l'equipe.
        // Meme correctif que pour `login`, et pour la meme raison : la seule
        // cle de ce limiteur etait l'adresse, et elle cede a un en-tete
        // `X-Forwarded-For`. Le plafond de 10 affiche ci-dessous ne bornait
        // donc rien du tout pour qui prend la peine de poser l'en-tete.
        //
        // L'enjeu grandit bientot : la branche
        // `codex/catalogue-finitions-rapport-2026-10-21` accroche
        // `POST /partner/register` a CE limiteur (routes/partner.php:14) — une
        // creation de compte sur un plafond qui ne tenait pas. Le defaut est
        // corrige avant que cette porte n'ouvre, pas apres.
        RateLimiter::for('public-write', fn (Request $request) => [
            Limit::perMinute(10)
                ->by($request->ip())
                ->response($this->refus(
                    'Trop de demandes envoyees. Patientez une minute avant de reessayer.'
                )),
            Limit::perMinute(self::ECRITURE_PUBLIQUE_PAR_MINUTE_GLOBAL)
                ->by(self::ECRITURE_PUBLIQUE_CLE_GLOBALE)
                ->response($this->refus(
                    'Trop de demandes en cours. Patientez une minute avant de reessayer.'
                )),
        ]);

        // ── Depot des photos d'une annonce de particulier ───────────────────
        //
        // POURQUOI UN LIMITEUR A PART
        //
        // `ThrottleRequests` construit sa cle avec `md5($nom.$cle_du_by)`,
        // SANS la route : toutes les routes qui portent le meme
        // `throttle:public-write` puisent dans un seul seau. Les trois
        // ecritures d'un depot d'annonce y etaient accrochees ensemble, et le
        // formulaire envoie une requete par photo : un brouillon + dix photos
        // + un envoi = douze requetes sur un plafond de dix. Le refus tombait
        // des la NEUVIEME photo, c'est-a-dire sur le parcours que l'ecran
        // propose lui-meme (« Photos (x/10) ») — pas sur un abus.
        //
        // POURQUOI PAR COMPTE, ET NON PAR ADRESSE
        //
        // `public-write` est cle sur l'adresse parce que ses appelants n'ont
        // pas de compte : deposer un besoin, demander un compte partenaire.
        // Cette route-ci est derriere `firebase`, qui pose le resolveur
        // d'utilisateur (VerifyFirebaseToken::50) : l'appelant est connu, donc
        // le motif de la cle par adresse ne s'y applique pas. Et la cle par
        // adresse etait ici nuisible, pas seulement inutile : avec
        // `trustProxies(at: '*')`, deux vendeurs derriere le NAT d'un operateur
        // burkinabe partagent une adresse — c'est le depot lui-meme qui le
        // decrit pour le limiteur `partage` plus bas. Elle ne bornait pas
        // l'abuseur, qui pose l'en-tete qu'il veut ; elle ne penalisait que le
        // vendeur honnete.
        //
        // Precedent du depot : `POST partner/vehicles/{vehicle}/media`, la
        // route analogue a photos multiples, ne porte aucun plafond de route et
        // vit sous `throttle:api`, cle sur le compte.
        //
        // CE QU'ON N'A PAS FAIT, ET POURQUOI
        //
        // Regrouper les dix photos en une requete aurait aussi ferme le defaut,
        // et c'etait la premiere idee. Mais dix reencodages GD dans UNE
        // `DB::transaction` tenant `lockForUpdate` echangent un 429 reparable
        // contre deux maux pires : le site entier gele le temps du lot — la
        // phase script est serialisee, « pendant ces secondes-la, personne
        // d'autre n'est servi », docker/php/depot-de-photos.ini:169 — et le
        // `catch (\Throwable)` du controleur supprime tous les chemins ecrits,
        // donc un depassement en cours de lot perd les DIX photos au lieu d'en
        // garder neuf. Une requete par photo reste le bon decoupage ; c'est le
        // seau qu'il fallait corriger.
        RateLimiter::for('depot-de-photos', fn (Request $request) => [
            Limit::perMinute(self::DEPOT_DE_PHOTOS_PAR_MINUTE)
                ->by('depot-de-photos:'.($request->user()?->id ?: $request->ip()))
                ->response($this->refus(
                    'Trop de photos envoyees en meme temps. Patientez une minute avant de reessayer.'
                )),
            Limit::perMinute(self::DEPOT_DE_PHOTOS_PAR_MINUTE_GLOBAL)
                ->by(self::DEPOT_DE_PHOTOS_CLE_GLOBALE)
                ->response($this->refus(
                    'Trop de photos en cours de depot. Patientez une minute avant de reessayer.'
                )),
        ]);

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
