<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Every API request selects its own language, including anonymous requests. */
class ResolveApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Regional tags and quality weights are handled by Symfony. An absent
        // or unsupported language keeps the historical French response.
        app()->setLocale($request->getPreferredLanguage(['fr', 'en']) ?? 'fr');

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        $response->setVary('Accept-Language', false);

        return $response;
    }
}
