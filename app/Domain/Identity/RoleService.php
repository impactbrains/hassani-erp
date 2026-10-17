<?php

namespace App\Domain\Identity;

use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\User;
use App\Support\Audit;
use App\Support\Permissions\PermissionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Role editing with escalation guards: the owner role always holds every
 * permission and cannot be edited; nobody can put a permission into a role
 * that they do not hold themselves.
 */
final class RoleService
{
    /** @param list<string> $permissions */
    public function save(User $actor, array $data, array $permissions, ?Role $role = null): Role
    {
        if (! $actor->hasPermission('settings.roles.manage')) {
            throw new BusinessRuleException('You are not allowed to edit roles.', 'not_permitted');
        }
        if ($role?->is_owner) {
            throw new BusinessRuleException('The owner role always has every permission and cannot be edited.', 'owner_role_locked');
        }
        $unknown = array_diff($permissions, PermissionRegistry::keys());
        if ($unknown !== []) {
            throw new BusinessRuleException('Unknown permissions: '.implode(', ', $unknown), 'invalid_permission');
        }
        if (! $actor->isOwner()) {
            $before = $role ? $role->permissionKeys() : [];
            $added = array_diff($permissions, $before);
            $notHeld = array_diff($added, $actor->permissionKeys());
            if ($notHeld !== []) {
                throw new BusinessRuleException('You cannot add permissions you do not have yourself.', 'privilege_escalation', ['permissions' => array_values($notHeld)]);
            }
        }

        return DB::transaction(function () use ($actor, $data, $permissions, $role) {
            $isNew = $role === null;
            $before = $isNew ? [] : $role->permissionKeys();
            $role ??= new Role(['key' => Str::slug($data['name'], '_').'_'.Str::lower(Str::random(4)), 'is_system' => false]);
            $role->fill(['name' => $data['name'], 'description' => $data['description'] ?? null])->save();
            RolePermission::query()->where('role_id', $role->id)->delete();
            RolePermission::insert(array_map(fn ($p) => ['role_id' => $role->id, 'permission' => $p], array_values(array_unique($permissions))));
            Audit::record($isNew ? 'role.created' : 'role.updated', ($isNew ? 'Created' : 'Updated')." role {$role->name}", $role, ['permissions' => $before], ['permissions' => array_values($permissions)]);

            return $role->fresh('permissions');
        });
    }

    public function delete(User $actor, Role $role): void
    {
        if (! $actor->hasPermission('settings.roles.manage') || $role->is_owner) {
            throw new BusinessRuleException('This role cannot be deleted.', 'not_permitted');
        }
        if ($role->assignments()->exists()) {
            throw new BusinessRuleException('People still have this role. Move them to another role first.', 'role_in_use');
        }
        Audit::record('role.deleted', "Deleted role {$role->name}", $role, ['permissions' => $role->permissionKeys()]);
        $role->permissions()->delete();
        $role->delete();
    }

    /** @return array<string, array{label: string, granted: list<array{key: string, label: string, sensitive: bool}>}> grouped summary of what a role grants */
    public function summary(Role $role): array
    {
        $keys = $role->is_owner ? PermissionRegistry::keys() : $role->permissionKeys();
        $summary = [];
        foreach (PermissionRegistry::groups() as $groupKey => $group) {
            $granted = [];
            foreach ($group['permissions'] as $key => $meta) {
                if (in_array($key, $keys, true)) {
                    $granted[] = ['key' => $key, 'label' => $meta['label'], 'sensitive' => (bool) ($meta['sensitive'] ?? false)];
                }
            }
            if ($granted !== []) {
                $summary[$groupKey] = ['label' => $group['label'], 'granted' => $granted];
            }
        }

        return $summary;
    }
}
