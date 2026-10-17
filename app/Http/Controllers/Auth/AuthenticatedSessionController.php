<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff sign-in. There is no public sign-up: accounts are created by an
 * owner or admin. Suspended and deactivated accounts cannot sign in, and
 * accounts with an authenticator app confirm a 6-digit code.
 */
class AuthenticatedSessionController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if (User::query()->count() === 0) {
            return redirect()->route('setup');
        }

        return Inertia::render('auth/Login', ['status' => $request->session()->get('status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'string', 'email', 'max:190'], 'password' => ['required', 'string', 'max:200'], 'remember' => ['boolean']]);
        $email = mb_strtolower(trim($data['email']));
        $key = 'login:'.$email.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->event(null, $email, 'lockout', $request);
            throw ValidationException::withMessages(['email' => 'Too many attempts. Try again in '.ceil(RateLimiter::availableIn($key) / 60).' minute(s).']);
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 300);
            $this->event($user, $email, 'login_failed', $request);
            throw ValidationException::withMessages(['email' => 'The e-mail or password is not correct.']);
        }
        if (! $user->isActive()) {
            $this->event($user, $email, 'login_blocked_inactive', $request);
            throw ValidationException::withMessages(['email' => 'This account is '.$user->status.'. Ask the owner or a manager.']);
        }
        RateLimiter::clear($key);

        if ($user->two_factor_confirmed_at) {
            $request->session()->put('login.two_factor', ['id' => $user->id, 'remember' => (bool) ($data['remember'] ?? false), 'at' => time()]);

            return redirect()->route('two-factor.challenge');
        }

        return $this->complete($request, $user, (bool) ($data['remember'] ?? false));
    }

    public function twoFactor(Request $request): Response|RedirectResponse
    {
        return $request->session()->has('login.two_factor') ? Inertia::render('auth/TwoFactorChallenge') : redirect()->route('login');
    }

    public function verifyTwoFactor(Request $request): RedirectResponse
    {
        $pending = $request->session()->get('login.two_factor');
        if (! $pending || time() - $pending['at'] > 300) {
            $request->session()->forget('login.two_factor');

            return redirect()->route('login')->withErrors(['email' => 'Sign in again.']);
        }
        $request->validate(['code' => ['required', 'string', 'max:20']]);
        $key = 'two-factor:'.$pending['id'];
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many attempts. Wait a few minutes.']);
        }
        $user = User::query()->findOrFail($pending['id']);
        if (! $user->isActive() || ! $user->two_factor_secret || ! Totp::verify($user->two_factor_secret, $request->string('code'))) {
            RateLimiter::hit($key, 300);
            $this->event($user, $user->email, 'two_factor_failed', $request);
            throw ValidationException::withMessages(['code' => 'That code is not correct.']);
        }
        RateLimiter::clear($key);
        $request->session()->forget('login.two_factor');

        return $this->complete($request, $user, $pending['remember']);
    }

    public function destroy(Request $request): RedirectResponse
    {
        if ($user = $request->user()) {
            $this->event($user, $user->email, 'logout', $request);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function forgot(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    private function complete(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::guard('web')->login($user, $remember);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now(), 'last_activity_at' => now()])->saveQuietly();
        $this->event($user, $user->email, 'login_succeeded', $request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    private function event(?User $user, string $email, string $event, Request $request): void
    {
        LoginEvent::query()->create(['company_id' => $user?->company_id, 'user_id' => $user?->id, 'email' => $email, 'event' => $event, 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 500)]);
    }
}
