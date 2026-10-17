<?php

namespace App\Http\Controllers\Auth;

use App\Domain\Setup\CompanyProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * First run only: while no account exists, the person installing the system
 * names the business and creates the first owner. Afterwards this page is
 * closed for good; further accounts are created in Settings → Users.
 */
class SetupController extends Controller
{
    public function show(): Response|RedirectResponse
    {
        if (User::query()->exists()) {
            return redirect()->route('login');
        }
        $company = Company::query()->orderBy('id')->first();

        return Inertia::render('auth/Setup', [
            'company' => $company?->only(['name', 'city', 'phone']),
            'store' => $company ? CurrentCompany::runAs($company, fn () => Store::query()->orderBy('id')->first()?->only(['name', 'code'])) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_if(User::query()->exists(), 403, 'Setup is already complete.');
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:120'],
            'city' => ['nullable', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:30'],
            'store_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'password' => ['required', 'confirmed', Password::defaults()],
            'locale' => ['required', 'in:en,ur'],
        ]);

        $user = DB::transaction(function () use ($data) {
            $company = Company::query()->orderBy('id')->lockForUpdate()->first()
                ?? app(CompanyProvisioner::class)->provision(['name' => $data['company_name'], 'city' => $data['city'] ?? 'Karachi', 'store_code' => 'S1', 'store_name' => $data['store_name']]);

            return CurrentCompany::runAs($company, function () use ($company, $data) {
                $company->update(['name' => $data['company_name'], 'city' => $data['city'] ?? $company->city, 'phone' => $data['phone'] ?? $company->phone, 'default_locale' => $data['locale']]);
                Store::query()->orderBy('id')->first()?->update(['name' => $data['store_name']]);
                $user = new User(['name' => $data['name'], 'email' => mb_strtolower(trim($data['email'])), 'password' => $data['password'], 'locale' => $data['locale']]);
                $user->company_id = $company->id;
                $user->status = User::STATUS_ACTIVE;
                $user->save();
                UserRole::create(['user_id' => $user->id, 'role_id' => Role::query()->where('is_owner', true)->value('id'), 'store_id' => null, 'reason' => 'First owner (setup)']);
                Audit::record('user.created', "First owner {$user->name} created during setup", $user);

                return $user;
            });
        });

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')->with('success', 'Welcome! Set an approval PIN next under your profile so you can approve discounts and refunds at the POS.');
    }
}
