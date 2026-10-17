<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `perm:pos.sell` needs the permission in the working store;
 * `perm:reports.sales,any` accepts it in any store; several keys separated
 * by "|" mean any one of them.
 */
final class RequirePermission
{
    public function handle(Request $request, Closure $next, string $permissions, string $scope = 'store'): Response
    {
        $user = $request->user();
        abort_unless($user, 401);
        $storeId = $scope === 'any' ? null : $request->attributes->get('store_id');
        foreach (explode('|', $permissions) as $permission) {
            if ($user->hasPermission($permission, $storeId)) {
                return $next($request);
            }
        }
        abort(403, 'You do not have permission to open this page. Ask the owner or a manager if you need it.');
    }
}
