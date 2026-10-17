<?php

namespace App\Http\Controllers;

use App\Models\Store;
use App\Models\User;
use Illuminate\Http\Request;

abstract class Controller
{
    /** The store the user is working in (chosen in the header). */
    protected function currentStore(Request $request): Store
    {
        $id = $request->attributes->get('store_id');
        abort_unless($id, 403, 'You are not assigned to any store yet. Ask the owner to give you a role.');

        return Store::query()->findOrFail($id);
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }

    /** 403 unless the user holds the permission for that store. */
    protected function allow(Request $request, string $permission, Store|int|null $store = null): void
    {
        abort_unless($request->user()?->hasPermission($permission, $store), 403, 'You do not have permission to do this.');
    }

    /** Pagination size from the request within sensible bounds. */
    protected function perPage(Request $request, int $default = 25): int
    {
        return min(100, max(10, (int) $request->integer('per_page', $default)));
    }
}
