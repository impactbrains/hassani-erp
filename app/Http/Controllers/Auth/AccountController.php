<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\UserAccountService;
use App\Http\Controllers\Controller;
use App\Models\LoginEvent;
use App\Support\Audit;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** The signed-in user's own account: password, approval PIN, authenticator app, devices and language. */
class AccountController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('account/Profile', [
            'profile' => ['name' => $user->name, 'email' => $user->email, 'locale' => $user->locale, 'has_pin' => $user->pin_hash !== null, 'two_factor' => $user->two_factor_confirmed_at !== null, 'last_login_at' => $user->last_login_at],
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->orderByDesc('last_activity')->get(['id', 'ip_address', 'user_agent', 'last_activity'])
                ->map(fn ($s) => ['id' => $s->id, 'ip' => $s->ip_address, 'agent' => $s->user_agent, 'last_active' => date(DATE_ATOM, $s->last_activity), 'current' => $s->id === $request->session()->getId()]),
            'logins' => LoginEvent::query()->where('user_id', $user->id)->latest('id')->limit(10)->get(['event', 'ip_address', 'created_at']),
            'two_factor_setup' => $request->session()->get('two_factor_setup') ? ['secret' => $request->session()->get('two_factor_setup'), 'uri' => Totp::uri($request->session()->get('two_factor_setup'), $user->email, config('app.name'))] : null,
        ]);
    }

    public function forceChange(): Response
    {
        return Inertia::render('auth/ChangePassword');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate(['current_password' => ['required', 'current_password'], 'password' => ['required', 'confirmed', 'different:current_password', Password::defaults()]]);
        $user->forceFill(['password' => $data['password'], 'must_change_password' => false])->save();
        DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
        Audit::record('user.password_changed', "{$user->name} changed their password", $user);

        return redirect()->route('dashboard')->with('success', 'Password changed. Other devices were signed out.');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'locale' => ['required', 'in:en,ur']]);
        $request->user()->update($data);

        return back()->with('success', 'Saved.');
    }

    public function updateLocale(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', 'in:en,ur']]);
        if ($user = $request->user()) {
            $user->update(['locale' => $data['locale']]);
        }
        $request->session()->put('locale', $data['locale']);

        return back();
    }

    public function updatePin(Request $request, UserAccountService $accounts): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string'], 'pin' => ['required', 'string', 'confirmed']]);
        $accounts->setPin($request->user(), $data['current_password'], $data['pin']);

        return back()->with('success', 'Approval PIN saved. Use it to approve discounts, refunds and payouts at the POS.');
    }

    public function startTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $request->session()->put('two_factor_setup', Totp::generateSecret());

        return back();
    }

    public function confirmTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'string']]);
        $secret = $request->session()->get('two_factor_setup');
        if (! $secret || ! Totp::verify($secret, $request->string('code'))) {
            throw ValidationException::withMessages(['code' => 'That code is not correct. Check the time on your phone and try again.']);
        }
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('two_factor_setup');
        Audit::record('user.two_factor_enabled', "{$user->name} turned on authenticator sign-in", $user);

        return back()->with('success', 'Authenticator app turned on. You will be asked for a code when you sign in.');
    }

    public function disableTwoFactor(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        $user = $request->user();
        $user->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
        Audit::record('user.two_factor_disabled', "{$user->name} turned off authenticator sign-in", $user);

        return back()->with('success', 'Authenticator app turned off.');
    }

    public function revokeSession(Request $request, string $session, UserAccountService $accounts): RedirectResponse
    {
        $accounts->revokeSession($request->user(), $request->user(), $session);

        return back()->with('success', 'Device signed out.');
    }

    public function revokeOtherSessions(Request $request): RedirectResponse
    {
        $request->validate(['current_password' => ['required', 'current_password']]);
        Auth::logoutOtherDevices($request->string('current_password'));
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();

        return back()->with('success', 'All other devices were signed out.');
    }
}
