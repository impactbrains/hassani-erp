<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\Settings\Settings;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every page. Permissions are sent for showing and hiding
 * controls only; every action is checked again on the server.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $company = CurrentCompany::get();
        $storeId = $request->attributes->get('store_id');

        return [
            ...parent::share($request),
            'app' => [
                'name' => config('app.name'),
                'company' => $company ? ['id' => $company->id, 'name' => $company->name, 'currency' => $company->currency, 'timezone' => $company->timezone] : null,
                // The working store's time zone (its own, else the company's). Every time on screen uses it.
                'timezone' => $this->timezone($storeId, $company?->timezone),
            ],
            'auth' => fn () => $user ? [
                'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'is_owner' => $user->isOwner(), 'has_pin' => $user->pin_hash !== null, 'two_factor' => $user->two_factor_confirmed_at !== null],
                'permissions' => $user->permissionKeys(),
                'stores' => Store::query()->whereIn('id', $user->accessibleStoreIds())->orderBy('name')->get(['id', 'code', 'name', 'name_ur'])->toArray(),
                'store_id' => $storeId,
            ] : null,
            'locale' => fn () => self::locale($request),
            'flash' => fn () => [
                'success' => $request->session()->get('success'),
                'error' => $request->session()->get('error'),
                'secret' => $request->session()->get('secret'),
            ],
            'ziggy' => fn () => ['location' => $request->url()],
        ];
    }

    private function timezone(mixed $storeId, ?string $companyZone): string
    {
        $zone = $storeId ? Store::query()->whereKey($storeId)->value('timezone') : null;

        return \App\Domain\Reporting\ReportFilters::safeTimezone($zone ?: $companyZone);
    }

    public static function locale(Request $request): string
    {
        $user = $request->user();
        if ($user?->locale) {
            return $user->locale;
        }
        if ($request->hasSession() && $request->session()->has('locale')) {
            return (string) $request->session()->get('locale');
        }
        try {
            return CurrentCompany::id() ? (string) Settings::get('localization.default_locale') : 'en';
        } catch (\Throwable) {
            return 'en';
        }
    }
}
