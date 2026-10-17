<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Models\Store;
use App\Support\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the signed-in user's company the tenant for this request and picks
 * the working store (remembered in the session, always one the user may use).
 * Guests on a single-company install get that company for branding.
 */
final class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $previous = CurrentCompany::id();
        $user = $request->user();
        if ($user) {
            CurrentCompany::set((int) $user->company_id);
            $stores = $user->accessibleStoreIds();
            $current = (int) ($request->hasSession() ? $request->session()->get('store_id') : 0);
            if (! in_array($current, $stores, true)) {
                $current = $stores[0] ?? 0;
                if ($request->hasSession()) {
                    $request->session()->put('store_id', $current);
                }
            }
            $request->attributes->set('store_id', $current ?: null);
        } elseif (Company::query()->count() === 1) {
            CurrentCompany::set(Company::query()->value('id'));
        }

        try {
            return $next($request);
        } finally {
            $previous === null ? CurrentCompany::clear() : CurrentCompany::set($previous);
        }
    }

    /** The working store of the current request. */
    public static function store(Request $request): ?Store
    {
        $id = $request->attributes->get('store_id');

        return $id ? Store::query()->find($id) : null;
    }
}
