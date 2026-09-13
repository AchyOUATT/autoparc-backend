<?php

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Le serveur n'a pas pu verifier la session : ce n'est pas la faute du client.
 *
 * La distinction n'est pas cosmetique. Pendant des heures, une machine sans
 * bundle de certificats — donc incapable de telecharger les cles publiques de
 * Google — a fait repondre « Token Firebase invalide ou expire ». On a cherche
 * du cote du jeton, du client, de l'horloge : partout sauf la ou etait la
 * panne. Un 401 dit « reconnectez-vous » ; un 503 dit « le serveur est en
 * difficulte », et envoie chercher au bon endroit.
 *
 * Le motif technique part au journal, jamais au client.
 */
class FirebaseUnavailableException extends ServiceUnavailableHttpException
{
    public function __construct(?\Throwable $previous = null)
    {
        parent::__construct(
            retryAfter: null,
            message: "Verification de session indisponible. Reessayez dans un instant.",
            previous: $previous,
        );
    }
}
