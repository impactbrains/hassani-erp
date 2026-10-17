<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Setup\CompanyProvisioner;
use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'code' => ['required', 'alpha_num', 'max:10'], 'city' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:300']]);
        abort_if(Store::query()->where('code', strtoupper($data['code']))->exists(), 422, 'That store code is already used.');
        $store = $provisioner->createStore($data['name'], strtoupper($data['code']), $data['city'] ?? null, $data['address'] ?? null);
        Audit::record('store.created', "Store {$store->name} created", $store);

        return back()->with('success', "{$store->name} created with its stock locations and a POS counter. Give people a role for it in Users.");
    }

    public function update(Request $request, Store $store): RedirectResponse
    {
        $this->allow($request, 'settings.stores.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'name_ur' => ['nullable', 'string', 'max:120'], 'kind' => ['required', 'in:retail,wholesale,retail_wholesale,warehouse'], 'phone' => ['nullable', 'string', 'max:30'], 'city' => ['nullable', 'string', 'max:80'], 'address' => ['nullable', 'string', 'max:300'], 'is_active' => ['boolean']]);
        $store->update($data);
        Audit::record('store.updated', "Store {$store->name} updated", $store);

        return back()->with('success', 'Store saved.');
    }
}
