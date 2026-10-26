<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Un refus qui porte un CODE STABLE, lisible par le client.
 *
 * ── POURQUOI UN CODE, ET NON SEULEMENT UN STATUT ───────────────────────────
 *
 * Deux refus tres differents partagent le statut 403 :
 *
 *   — « votre role ne permet pas cette action » : la session est VALABLE, le
 *     client doit la garder et se contenter d'afficher le refus ;
 *   — « votre compte est desactive » : la session est MORTE, le client doit
 *     vider son coffre et revenir a l'ecran de connexion.
 *
 * Le statut seul ne les distingue pas, et le client ne peut pas les deviner.
 * Un `message` non plus : il change avec la traduction et la formulation.
 * `partner_repository.dart` l'a paye — `sessionRejected => statusCode == 401 ||
 * statusCode == 403` effacait le jeton partenaire sur N'IMPORTE QUEL 403, y
 * compris « cette annonce n'est pas la votre ». Meme symptome que la panne
 * qu'on corrige, autre cause.
 *
 * D'ou un vocabulaire ferme et stable, enumere ici :
 *
 *   `account_disabled`  — compte desactive, jeton encore present. Le client
 *                         efface sa session locale.
 *   `permission_denied` — refus d'autorisation. Le client CONSERVE sa session.
 *
 * ── POURQUOI 403 ET NON 401 POUR UN COMPTE DESACTIVE ──────────────────────
 *
 * Parce que le jeton, lui, est encore valable : le declarer invalide
 * melangerait « ce jeton n'existe pas » et « ce compte n'a plus le droit ». Le
 * 401 reste reserve au premier cas, et il arrive de lui-meme quand
 * l'observateur a revoque les jetons. Les deux chemins de desactivation
 * rendent donc deux statuts differents, et c'est voulu :
 *
 *   desactivation par Eloquent     -> jetons revoques -> 401
 *   desactivation en SQL direct    -> jeton survivant -> 403 account_disabled
 *
 * ── COMMENT LE CORPS EST RENDU ─────────────────────────────────────────────
 *
 * Par `render()`, que le gestionnaire de Laravel appelle sur l'exception
 * elle-meme. Le `respond()` de `bootstrap/app.php` continue d'ajouter
 * `request_id` par-dessus, puisqu'il travaille sur la reponse finale : le
 * client garde donc le fil vers le journal.
 */
class RefusMetier extends HttpException
{
    public const COMPTE_DESACTIVE = 'account_disabled';

    public const PERMISSION_REFUSEE = 'permission_denied';

    public function __construct(
        int $statut,
        string $message,
        public readonly string $codeMetier,
    ) {
        parent::__construct($statut, $message);
    }

    /** Le compte existe, le jeton aussi, mais le compte est desactive. */
    public static function compteDesactive(string $message): self
    {
        return new self(403, $message, self::COMPTE_DESACTIVE);
    }

    /** La session est valable ; c'est l'action qui est refusee. */
    public static function permissionRefusee(string $message): self
    {
        return new self(403, $message, self::PERMISSION_REFUSEE);
    }

    public function render(Request $request): ?JsonResponse
    {
        // Les pages de partage (/v, /p, /a) et le navigateur attendent du HTML :
        // on ne leur impose pas du JSON. `shouldRenderJsonWhen` de
        // `bootstrap/app.php` tient la meme regle pour le reste.
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->codeMetier,
        ], $this->getStatusCode());
    }
}
