<?php

namespace Tests\Feature;

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\TestCase;

/**
 * Verifie le cablage des droits sur les routes back-office.
 *
 * Le point faible d'une matrice posee dans les routes, c'est la route
 * suivante : celle qu'on ajoute six mois plus tard sans y penser. Ce test
 * relit la table de routage et refuse toute ecriture du back-office qui
 * n'annonce pas de capacite.
 *
 * Il ne touche pas la base : seul le routeur est sollicite.
 */
class StaffRouteCapabilitiesTest extends TestCase
{
    /**
     * Ecritures volontairement ouvertes a tout le personnel.
     *
     * Chacune ne concerne que l'utilisateur qui la declenche : son propre
     * appareil, ses propres notifications.
     */
    private const SANS_CAPACITE = [
        'api/fcm-token',
        'api/notifications/read-all',
        'api/notifications/{notification}/read',
    ];

    public function test_toute_ecriture_du_back_office_declare_une_capacite(): void
    {
        $manquantes = [];

        foreach ($this->staffRoutes() as $route) {
            if ($this->isReadOnly($route) || in_array($route->uri(), self::SANS_CAPACITE, true)) {
                continue;
            }

            if (! $this->declaresCapability($route)) {
                $manquantes[] = implode('|', $route->methods()) . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $manquantes, sprintf(
            "Ces routes du back-office ecrivent sans capacite declaree :\n  %s\n"
            . "Ajouter ->middleware('capability:…'), ou les inscrire dans SANS_CAPACITE si l'ouverture est voulue.",
            implode("\n  ", $manquantes),
        ));
    }

    public function test_la_consultation_reste_ouverte_a_tout_le_personnel(): void
    {
        $verrouillees = [];

        foreach ($this->staffRoutes() as $route) {
            if ($this->isReadOnly($route) && $this->declaresCapability($route)) {
                $verrouillees[] = $route->uri();
            }
        }

        $this->assertSame([], $verrouillees, sprintf(
            "Ces routes de lecture sont restreintes alors que tout le personnel doit pouvoir consulter :\n  %s",
            implode("\n  ", $verrouillees),
        ));
    }

    /**
     * Chaque capacite citee dans les routes existe dans UserRole::can().
     *
     * Un nom inconnu fermerait la route pour tout le monde, y compris
     * l'administrateur — panne silencieuse jusqu'au premier appel.
     */
    public function test_les_capacites_citees_existent(): void
    {
        $inconnues = [];

        foreach ($this->staffRoutes() as $route) {
            foreach ($this->capabilitiesOf($route) as $capability) {
                if (! \App\Enums\UserRole::Admin->can($capability)) {
                    $inconnues[$capability] = $route->uri();
                }
            }
        }

        $this->assertSame([], $inconnues, sprintf(
            "Capacite(s) inconnue(s) de UserRole::can() : %s",
            json_encode($inconnues, JSON_UNESCAPED_UNICODE),
        ));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array<int, Route> */
    private function staffRoutes(): array
    {
        $routes = array_filter(
            RouteFacade::getRoutes()->getRoutes(),
            fn (Route $route) => in_array('staff', $route->gatherMiddleware(), true),
        );

        $this->assertNotEmpty($routes, 'Aucune route back-office trouvee : le filtre est casse.');

        return array_values($routes);
    }

    private function isReadOnly(Route $route): bool
    {
        return array_diff($route->methods(), ['GET', 'HEAD']) === [];
    }

    private function declaresCapability(Route $route): bool
    {
        return $this->capabilitiesOf($route) !== [];
    }

    /** @return array<int, string> */
    private function capabilitiesOf(Route $route): array
    {
        $capabilities = [];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'capability:')) {
                $capabilities[] = substr($middleware, strlen('capability:'));
            }
        }

        return $capabilities;
    }
}
