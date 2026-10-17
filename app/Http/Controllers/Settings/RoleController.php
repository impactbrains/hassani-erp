<?php

namespace App\Http\Controllers\Settings;

use App\Domain\Identity\RoleService;
use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Roles are bundles of permissions. Templates are provided; owners can create their own. */
class RoleController extends Controller
{
    public function index(Request $request): Response
    {
        $this->allow($request, 'settings.users.view');

        return Inertia::render('settings/Roles', [
            'roles' => Role::query()->withCount('assignments')->with('permissions:id,role_id,permission')->orderByDesc('is_owner')->orderBy('name')->get()
                ->map(fn (Role $r) => $r->only(['id', 'key', 'name', 'description', 'is_owner', 'is_system', 'assignments_count']) + ['permissions' => $r->is_owner ? PermissionRegistry::keys() : $r->permissionKeys()]),
            'groups' => PermissionRegistry::groups(),
            'mine' => $request->user()->permissionKeys(),
            'canManage' => $request->user()->hasPermission('settings.roles.manage'),
        ]);
    }

    public function store(Request $request, RoleService $roles): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'permissions' => ['array'], 'permissions.*' => ['string']]);
        $roles->save($request->user(), $data, $data['permissions'] ?? []);

        return back()->with('success', 'Role created.');
    }

    public function update(Request $request, Role $role, RoleService $roles): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300'], 'permissions' => ['array'], 'permissions.*' => ['string']]);
        $roles->save($request->user(), $data, $data['permissions'] ?? [], $role);

        return back()->with('success', 'Role saved. People with this role get the change on their next page.');
    }

    public function destroy(Request $request, Role $role, RoleService $roles): RedirectResponse
    {
        $roles->delete($request->user(), $role);

        return back()->with('success', 'Role deleted.');
    }
}
