<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Applies the user's language (English or Urdu) to messages rendered on the server. */
final class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = HandleInertiaRequests::locale($request);
        app()->setLocale(in_array($locale, ['en', 'ur'], true) ? $locale : 'en');

        return $next($request);
    }
}
