<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\UserPermission;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UserPermissionController extends Controller
{
    /**
     * List of every app that can have permissions assigned.
     * Kept in sync with resources/js/config/apps.ts keys.
     */
    private const APPS = [
        'pos',
        'inventory',
        'products',
        'invoicing',
        'sales',
        'accounting',
        'website',
        'contacts',
        'barcode',
        'todo',
        'chat',
    ];

    public function index(): Response
    {
        $users = User::with('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'has_dashboard_access' => $user->has_dashboard_access,
            ]);

        return Inertia::render('users/Index', [
            'users' => $users,
        ]);
    }

    public function edit(User $user): Response
    {
        $permissions = $user->permissions()
            ->where('section', '*')
            ->get()
            ->keyBy('app');

        // Ensure every app has an entry (defaulting to 'none') so the
        // frontend always has a consistent, complete list to render.
        $appPermissions = collect(self::APPS)->map(fn (string $app) => [
            'app' => $app,
            'access_level' => $permissions->get($app)?->access_level ?? 'none',
        ]);

        return Inertia::render('users/Edit', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->is_admin,
                'has_dashboard_access' => $user->has_dashboard_access,
            ],
            'permissions' => $appPermissions,
        ]);
    }
    
    public function update(Request $request, User $user): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validate([
            'is_admin' => ['required', 'boolean'],
            'has_dashboard_access' => ['required', 'boolean'],
            'permissions' => ['required', 'array'],
            'permissions.*.app' => ['required', 'string', 'in:' . implode(',', self::APPS)],
            'permissions.*.access_level' => ['required', 'string', 'in:none,user,admin'],
        ]);

        $user->update([
            'is_admin' => $validated['is_admin'],
            'has_dashboard_access' => $validated['has_dashboard_access'],
        ]);

        foreach ($validated['permissions'] as $permission) {
            UserPermission::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'app' => $permission['app'],
                    'section' => '*',
                ],
                [
                    'access_level' => $permission['access_level'],
                ]
            );
        }

        ActivityLog::log(
            'users',
            'updated',
            "Updated permissions for {$user->name}",
            $user,
            ['updated_by' => $request->user()->id]
        );

        return redirect()
            ->route('users.index')
            ->with('success', "Permissions updated for {$user->name}.");
    }
}