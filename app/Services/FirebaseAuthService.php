<?php

namespace App\Services;

use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Exception\Auth\FailedToVerifyToken;
use Kreait\Firebase\Factory;
use Lcobucci\JWT\Token\Parser;

/**
 * Verifie les ID tokens emis par Firebase Auth cote client (app Flutter).
 * Ne gere ni inscription ni mot de passe : Firebase s'en charge entierement.
 */
class FirebaseAuthService
{
    protected FirebaseAuth $auth;

    public function __construct()
    {
        $this->auth = (new Factory)
            ->withServiceAccount(config('services.firebase.credentials'))
            ->createAuth();
    }

    /**
     * Verifie un ID token et retourne ses informations (uid, email, name...).
     * Retourne null si le token est invalide, expire, ou mal signe.
     */
    public function verify(string $idToken): ?array
    {
        try {
            $verified = $this->auth->verifyIdToken($idToken);
        } catch (FailedToVerifyToken $e) {
            return null;
        }

        $claims = $verified->claims();

        return [
            'uid'            => $claims->get('sub'),
            'email'          => $claims->get('email'),
            'email_verified' => $claims->get('email_verified', false),
            'name'           => $claims->get('name'),
        ];
    }
}
