<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Setup\CompanyProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Stores / branches. A new store gets its own stock locations and a first POS. */
class StoreController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'settings.stores.manage');

        return Inertia::render('settings/Stores', ['stores' => Store::query()->withCount(['locations', 'registers'])->orderBy('id')->get()]);
    }

    public function store(Request $request, CompanyProvisioner $provisioner): RedirectResponse
    {
        $this->allow($request, 'settings.stores.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['required', 'alpha_num', 'max:10'], 'city' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:300'], 'timezone' => ['nullable', 'timezone:all']]);
        $code = strtoupper($data['code']);
        if (Store::query()->whereRaw('upper(code) = ?', [$code])->exists()) {
            // A field error (red text under the box), not a full-screen 422 page.
            throw ValidationException::withMessages(['code' => "You can't have two stores with the same prefix. Another store already uses {$code}, and the prefix is printed on receipt numbers, so each store needs its own."]);
        }
        $store = $provisioner->createStore($data['name'], $code, $data['city'] ?? null, $data['address'] ?? null);
        $store->update(['timezone' => $data['timezone'] ?? 'Asia/Karachi']);
        Audit::record('store.created', "Store {$store->name} created", $store);

        return back()->with('success', "{$store->name} created with its stock locations and a POS counter. Give people a role for it in Users.");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->allow($request, 'settings.stores.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'name_ur' => ['nullable', 'string', 'max:120'], 'kind' => ['required', 'in:retail,wholesale,retail_wholesale,warehouse'], 'phone' => ['nullable', 'string', 'max:30'], 'city' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:300'], 'timezone' => ['required', 'timezone:all'], 'is_active' => ['boolean']]);
        $zoneBefore = $store->timezone();
        $store->update($data);
        if ($zoneBefore !== $data['timezone']) {
            Audit::record('store.timezone_changed', "Store {$store->name} time zone changed from {$zoneBefore} to {$data['timezone']}", $store, ['timezone' => $zoneBefore], ['timezone' => $data['timezone']]);
        }
        Audit::record('store.updated', "Store {$store->name} updated", $store);

        return back()->with('success', 'Store saved.');
    }
}
