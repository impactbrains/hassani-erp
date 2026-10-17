<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * API keys for the future e-commerce website and app. A key is shown once,
 * is limited to chosen abilities and one store, and can be revoked at once.
 */
class ApiClientController extends Controller
{
    public const ABILITIES = [
        'catalog:read' => 'Read products, prices and categories',
        'stock:read' => 'Read available stock',
        'customers:write' => 'Create and update customers',
        'orders:write' => 'Place and cancel orders',
        'orders:read' => 'Read order status',
    ];

    public function index(Request $request): Response
    {
        $this->allow($request, 'settings.api.manage');

        return Inertia::render('settings/ApiClients', [
            'clients' => ApiClient::query()->with('store:id,name')->latest('id')->get()->map(fn (ApiClient $c) => $c->toArray() + ['last_used_at' => $c->tokens()->max('last_used_at')]),
            'abilities' => self::ABILITIES,
            'stores' => \App\Models\Store::query()->where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->allow($request, 'settings.api.manage');
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'store_id' => ['required', 'integer'], 'abilities' => ['required', 'array', 'min:1'], 'abilities.*' => ['in:'.implode(',', array_keys(self::ABILITIES))]]);
        $client = ApiClient::create(['name' => $data['name'], 'store_id' => $data['store_id'], 'abilities' => $data['abilities'], 'created_by' => $request->user()->id]);
        $token = $client->createToken($data['name'], $data['abilities']);
        Audit::record('api.client_created', "API key “{$client->name}” created", $client, new: ['abilities' => $data['abilities']]);

        return back()->with('secret', ['label' => "API key for {$client->name}", 'value' => $token->plainTextToken, 'note' => 'Copy it now: it is shown only once. Use it as "Authorization: Bearer <key>".']);
    }

    public function destroy(Request $request, ApiClient $client): RedirectResponse
    {
        $this->allow($request, 'settings.api.manage');
        $client->tokens()->delete();
        $client->update(['revoked_at' => now()]);
        Audit::record('api.client_revoked', "API key “{$client->name}” revoked", $client);

        return back()->with('success', 'API key revoked.');
    }
}
