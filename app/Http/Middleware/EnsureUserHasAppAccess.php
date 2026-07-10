<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasAppAccess
{
    /**
     * Usage in routes: ->middleware('app.access:inventory')
     * or, to require admin-level specifically: ->middleware('app.access:inventory,admin')
     */
    public function handle(Request $request, Closure $next, string $app, string $minLevel = 'user'): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $level = $user->accessLevelFor($app);

        $rank = ['none' => 0, 'user' => 1, 'admin' => 2];

        if (($rank[$level] ?? 0) < ($rank[$minLevel] ?? 1)) {
            abort(403, 'You do not have permission to access this app.');
        }

        return $next($request);
    }
}