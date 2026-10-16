<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Le plancher de version, et les deux facons de le rater.
 *
 * Les correctifs partent par vagues et plusieurs dependent du serveur. Une
 * version ancienne qui continue d'appeler l'API affiche des ecrans faux sans
 * que personne ne s'en apercoive : c'est pour couper court a cela qu'un
 * plancher existe.
 *
 * Il y a deux facons de se tromper, et elles sont de gravite tres inegale.
 *
 * La premiere est de ne bloquer personne alors qu'il le faudrait. C'est
 * genant, et ca se corrige en relevant une variable sur le tableau de bord.
 *
 * La seconde est de bloquer tout le parc par accident — un defaut de
 * configuration, une route passee derriere l'authentification, un deploiement
 * neuf ou la variable n'a pas ete posee. Celle-la n'a pas de correctif cote
 * utilisateur : l'application refuse de s'ouvrir, et personne ne peut rien y
 * faire. Ce fichier verrouille donc surtout la seconde.
 *
 * Aucune base n'est sollicitee : trois valeurs de configuration, rien d'autre.
 */
class VersionMinimaleTest extends TestCase
{
    public function test_la_route_repond_sans_aucun_jeton(): void
    {
        $this->getJson('/api/app/version')->assertOk();
    }

    /**
     * Les quatre cles, toutes les quatre.
     *
     * L'application decide a partir de ce qu'elle recoit, et ne bloque que sur
     * une reponse CLAIRE : une cle absente la fait passer son chemin. Perdre
     * `min_version_code` desarmerait donc le plancher en silence, sans qu'une
     * seule erreur n'apparaisse nulle part.
     */
    public function test_les_quatre_cles_sont_rendues(): void
    {
        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertJsonStructure([
                'min_version_code',
                'latest_version_code',
                'store_url',
                'message',
            ]);
    }

    /**
     * Le defaut ne bloque personne.
     *
     * Une installation neuve du serveur, ou un deploiement ou
     * MOBILE_MIN_VERSION_CODE a ete oublie, rend 0 : aucun versionCode n'est
     * inferieur a 0, donc aucune application ne se verrouille. C'est un
     * reglage qu'on ARME, et ce test est ce qui l'empeche de s'armer tout
     * seul.
     */
    public function test_le_plancher_par_defaut_ne_bloque_personne(): void
    {
        config()->set('mobile.min_version_code', (int) env('MOBILE_MIN_VERSION_CODE', 0));

        $this->assertSame(
            0,
            $this->getJson('/api/app/version')->assertOk()->json('min_version_code'),
            'Sans variable d\'environnement, le plancher doit rester a 0.',
        );
    }

    /** Le type compte : l'application compare des entiers, pas des chaines. */
    public function test_le_plancher_est_rendu_en_entier(): void
    {
        config()->set('mobile.min_version_code', '70');

        $this->assertSame(70, $this->getJson('/api/app/version')->assertOk()->json('min_version_code'));
    }

    public function test_une_valeur_posee_en_configuration_est_bien_rendue(): void
    {
        config()->set('mobile.min_version_code', 70);
        config()->set('mobile.latest_version_code', 72);
        config()->set('mobile.store_url', 'https://play.google.com/store/apps/details?id=bf.autoparc.app');
        config()->set('mobile.update_message', 'Les pleins saisis depuis cette version ne remontaient pas.');

        $this->getJson('/api/app/version')
            ->assertOk()
            ->assertExactJson([
                'min_version_code'    => 70,
                'latest_version_code' => 72,
                'store_url'           => 'https://play.google.com/store/apps/details?id=bf.autoparc.app',
                'message'             => 'Les pleins saisis depuis cette version ne remontaient pas.',
            ]);
    }

    /**
     * Le message vient du serveur, et il est en francais accentue.
     *
     * C'est la seule facon d'expliquer POURQUOI la mise a jour est exigee sans
     * republier l'application — qui, par definition, ne sera plus mise a jour
     * chez ceux a qui le message s'adresse.
     */
    public function test_un_message_francais_est_servi_par_defaut(): void
    {
        $message = $this->getJson('/api/app/version')->assertOk()->json('message');

        $this->assertNotSame('', trim((string) $message));
        $this->assertStringContainsString('Mettez à jour', $message);
    }

    // ── La route doit rester joignable par une application obsolete ─────────

    /**
     * Sans jeton, et surtout pas de 401.
     *
     * La version qu'on cherche a bloquer est aussi celle qui peut ne plus
     * savoir s'authentifier — jeton expire, format d'en-tete qui a change,
     * compte Firebase qu'elle n'arrive plus a rafraichir. Si cette route
     * repondait 401, l'application ne saurait jamais qu'elle est obsolete et
     * continuerait d'afficher des ecrans faux : exactement le defaut qu'on
     * voulait corriger.
     */
    public function test_la_route_ne_repond_pas_401_sans_authentification(): void
    {
        $reponse = $this->getJson('/api/app/version');

        $this->assertNotSame(401, $reponse->getStatusCode());
        $this->assertNotSame(403, $reponse->getStatusCode());
        $this->assertSame(200, $reponse->getStatusCode());
    }

    /**
     * Le garde-fou contre la route suivante : celle qu'on deplace six mois
     * plus tard d'un `group()` a l'autre sans y penser. On relit la table de
     * routage plutot que la reponse, parce qu'un middleware d'authentification
     * ajoute par megarde ne se verrait pas tant que les tests n'actent
     * personne.
     */
    public function test_aucun_middleware_d_authentification_ne_garde_la_route(): void
    {
        $route = collect(RouteFacade::getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/app/version');

        $this->assertNotNull($route, 'La route GET /api/app/version a disparu de la table de routage.');

        $gardes = array_values(array_filter(
            $route->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && (
                str_starts_with($middleware, 'auth')
                || $middleware === 'firebase'
                || $middleware === 'staff'
                || str_starts_with($middleware, 'capability')
            ),
        ));

        $this->assertSame([], $gardes, sprintf(
            "Le plancher de version doit rester joignable par une application trop vieille pour "
            . "s'authentifier. Middlewares trouves : %s",
            implode(', ', $gardes),
        ));
    }
}
