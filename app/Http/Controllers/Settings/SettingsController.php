<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Settings\Settings;
use App\Support\Settings\SettingsRegistry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Business settings from the registry. Every setting shows what it does,
 * its current value and where that value comes from (default, business or a
 * store override). Only settings the user may change are editable.
 */
class SettingsController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user->hasAnyPermission(['settings.company.manage', 'settings.stores.manage', 'settings.security.manage', 'settings.data.manage', 'settings.catalog.manage']), 403);
        $storeId = $request->integer('store_id') ?: null;
        $settings = [];
        foreach (SettingsRegistry::definitions() as $key => $def) {
            $settings[] = [
                'key' => $key, 'group' => $def['group'], 'label' => $def['label'], 'description' => $def['description'], 'type' => $def['type'], 'options' => $def['options'] ?? null,
                'scopes' => $def['scopes'], 'high_impact' => (bool) ($def['high_impact'] ?? false), 'locked_after' => $def['locked_after'] ?? null, 'default' => $def['default'],
                'value' => Settings::get($key, $storeId), 'source' => Settings::source($key, $storeId), 'can_edit' => $user->hasPermission($def['permission']),
            ];
        }
        $company = CurrentCompany::get();

        return Inertia::render('settings/Business', [
            'groups' => SettingsRegistry::groups(),
            'settings' => $settings,
            'company' => $company?->only(['name', 'legal_name', 'ntn', 'strn', 'phone', 'email', 'address', 'city', 'timezone', 'currency']),
            'stores' => Store::query()->whereIn('id', $user->accessibleStoreIds())->get(['id', 'name']),
            'storeId' => $storeId,
            'canCompany' => $user->hasPermission('settings.company.manage'),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate(['key' => ['required', 'string'], 'value' => ['present'], 'store_id' => ['nullable', 'integer'], 'clear' => ['boolean']]);
        $def = SettingsRegistry::definition($data['key']);
        $this->allow($request, $def['permission']);
        $scope = $data['store_id'] ? SettingsRegistry::SCOPE_STORE : SettingsRegistry::SCOPE_COMPANY;
        if ($data['store_id']) {
            abort_unless(in_array((int) $data['store_id'], $request->user()->accessibleStoreIds(), true), 403);
        }
        // An emptied value means "use the default / the business value" again.
        $data['clear'] = ($data['clear'] ?? false) || $data['value'] === null || $data['value'] === '';
        ($data['clear'] ?? false) ? Settings::clear($data['key'], $scope, $data['store_id']) : Settings::set($data['key'], $data['value'], $scope, $data['store_id']);

        return back()->with('success', "“{$def['label']}” saved.");
    }

    public function company(Request $request): RedirectResponse
    {
        $this->allow($request, 'settings.company.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'legal_name' => ['nullable', 'string', 'max:200'], 'ntn' => ['nullable', 'string', 'max:20'], 'strn' => ['nullable', 'string', 'max:20'], 'phone' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email'], 'address' => ['nullable', 'string', 'max:300'], 'city' => ['nullable', 'string', 'max:80']]);
        $company = CurrentCompany::get();
        $old = $company->only(array_keys($data));
        $company->update($data);
        \App\Support\Audit::record('company.updated', 'Business details changed', $company, $old, $data);

        return back()->with('success', 'Business details saved.');
    }
}
