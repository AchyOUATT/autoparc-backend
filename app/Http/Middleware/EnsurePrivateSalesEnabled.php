<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsurePrivateSalesEnabled
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless(config('private_sales.enabled'), 404);

        return $next($request);
    }
}
