<?php

namespace App\Http\Middleware;

use App\Models\LoginEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out suspended or deactivated users on their next request (access is
 * removed immediately, not at session expiry), sends users with a temporary
 * password to change it, and records activity time for the sessions list.
 */
final class EnsureAccountUsable
{
    private const ALLOWED_WHILE_CHANGING = ['password.force', 'password.force.update', 'logout', 'locale.update'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }
        if (! $user->isActive()) {
            LoginEvent::query()->withoutGlobalScopes()->create(['company_id' => $user->company_id, 'user_id' => $user->id, 'email' => $user->email, 'event' => 'session_ended_inactive', 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your account is not active. Ask the owner or a manager.']);
        }
        if ($user->must_change_password && ! in_array($request->route()?->getName(), self::ALLOWED_WHILE_CHANGING, true)) {
            return $request->expectsJson() && ! $request->header('X-Inertia')
                ? response()->json(['message' => 'Change your temporary password first.', 'code' => 'password_change_required'], 403)
                : redirect()->route('password.force');
        }
        if (! $user->last_activity_at || $user->last_activity_at->lt(now()->subMinutes(5))) {
            $user->forceFill(['last_activity_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
