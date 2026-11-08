<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Representation negotiation only: never grants an authorization. */
class ResolveApiCapabilities
{
    public function handle(Request $request, Closure $next): Response
    {
        $capabilities = array_map('trim', explode(',', $request->header('X-AutoParc-Capabilities', '')));
        $request->attributes->set('nullable_part_type', in_array('nullable-part-type', $capabilities, true));
        $response = $next($request);
        $response->setVary('X-AutoParc-Capabilities', false);

        return $response;
    }
}
