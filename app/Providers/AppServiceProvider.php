<?php

namespace App\Providers;

use App\Models\User;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
    }

    public function boot(): void
    {
        // Registry permissions ("pos.sell") work with Gate, @can and $this->authorize().
        Gate::before(function (User $user, string $ability, array $arguments = []) {
            if (! PermissionRegistry::exists($ability)) {
                return null;
            }
            $store = $arguments[0] ?? null;

            return $user->hasPermission($ability, is_numeric($store) || $store instanceof \App\Models\Store ? $store : null) ?: null;
        });

        // Length beats complexity rules; no forced periodic rotation (NIST SP 800-63B).
        Password::defaults(fn () => Password::min(10)->max(128));

        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);
        RateLimiter::for('pin', fn (Request $request) => Limit::perMinute(6)->by(($request->user()?->id ?? 'guest').'|'.$request->ip()));
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(240)->by($request->bearerToken() ? sha1($request->bearerToken()) : $request->ip()));
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
