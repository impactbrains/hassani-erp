<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiClient;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Base for API v1. Callers are either an API client (website/app key, tied
 * to one store, with abilities) or a staff user (rider app). Errors use one
 * shape: {"message": "...", "code": "...", "errors"?: {...}}.
 */
abstract class ApiController extends Controller
{
    protected function client(Request $request): ApiClient
    {
        $client = $request->user();
        abort_unless($client instanceof ApiClient && $client->isActive(), 403, 'This endpoint needs a website/app API key.');

        return $client;
    }

    protected function staff(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isActive(), 403, 'This endpoint needs a staff login.');

        return $user;
    }

    protected function ability(Request $request, string $ability): void
    {
        abort_unless($request->user()?->tokenCan($ability), 403, "This key is not allowed to {$ability}.");
    }

    protected function clientStore(Request $request): Store
    {
        return Store::query()->where('is_active', true)->findOrFail($this->client($request)->store_id);
    }
}
