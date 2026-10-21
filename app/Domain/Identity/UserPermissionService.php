<?php

namespace App\Domain\Identity;

use App\Exceptions\BusinessRuleException;
use App\Models\Store;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\Audit;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/**
 * Single-permission exceptions for one person, on top of their roles: give an extra permission, or take one
 * away, for every store or for one store. Rules:
 *  - you can only give what you hold yourself (for the same store, or every store);
 *  - nobody changes their own permissions, and owners are never restricted;
 *  - taking a permission away is always allowed to someone who manages permissions;
 *  - every change is written to the audit trail, and "reset" returns the person to what their roles give.
 */
final class UserPermissionService
{
    /**
     * Every permission with where it comes from for this person.
     *
     * @return list<array{key: string, label: string, group: string, sensitive: bool, from_roles: list<string>, overrides: list<array{id: int, effect: string, store_id: int|null, store: string|null, reason: string|null, by: string|null}>, effective: bool, can_give: bool}>
     */
    public function breakdown(User $actor, User $target): array
    {
        $this->sameCompany($target);
        $roles = $target->roleAssignments()->active()->with(['role' => fn ($q) => $q->with('permissions'), 'store:id,name'])->get();
        $overrides = $target->permissionOverrides()->with(['store:id,name', 'granter:id,name'])->get()->groupBy('permission');
        $rows = [];
        foreach (PermissionRegistry::groups() as $groupKey => $group) {
            foreach ($group['permissions'] as $key => $meta) {
                $from = [];
                foreach ($roles as $assignment) {
                    $role = $assignment->role;
                    if ($role && ($role->is_owner || $role->permissions->contains('permission', $key))) {
                        $from[] = $role->name.($assignment->store ? ' ('.$assignment->store->name.')' : '');
                    }
                }
                $rows[] = [
                    'key' => $key, 'label' => $meta['label'], 'group' => $group['label'], 'sensitive' => (bool) ($meta['sensitive'] ?? false),
                    'from_roles' => $from,
                    'overrides' => ($overrides[$key] ?? collect())->map(fn ($o) => ['id' => $o->id, 'effect' => $o->effect, 'store_id' => $o->store_id, 'store' => $o->store?->name, 'reason' => $o->reason, 'by' => $o->granter?->name])->values()->all(),
                    'effective' => $target->hasPermission($key),
                    'can_give' => $this->actorHolds($actor, $key, null),
                ];
            }
        }

        return $rows;
    }

    public function set(User $actor, User $target, string $permission, string $effect, ?int $storeId, ?string $reason): UserPermissionOverride
    {
        $this->authorize($actor, $target);
        if (! PermissionRegistry::exists($permission)) {
            throw new BusinessRuleException('Unknown permission.', 'invalid_permission', field: 'permission');
        }
        if (! in_array($effect, [UserPermissionOverride::GRANT, UserPermissionOverride::REVOKE], true)) {
            throw new BusinessRuleException('Choose give or take away.', 'invalid_effect', field: 'effect');
        }
        if ($target->isOwner()) {
            throw new BusinessRuleException('An owner always has every permission. Remove the owner role first if you want to limit this person.', 'owner_unrestricted');
        }
        if ($storeId !== null) {
            Store::query()->findOrFail($storeId);
        }
        if ($effect === UserPermissionOverride::GRANT && ! $this->actorHolds($actor, $permission, $storeId)) {
            throw new BusinessRuleException('You cannot give a permission you do not have yourself'.($storeId ? ' for that store.' : ' for every store.'), 'privilege_escalation', ['permissions' => [$permission]]);
        }

        return DB::transaction(function () use ($actor, $target, $permission, $effect, $storeId, $reason) {
            $existing = UserPermissionOverride::query()->where('user_id', $target->id)->where('permission', $permission)->when($storeId === null, fn ($q) => $q->whereNull('store_id'), fn ($q) => $q->where('store_id', $storeId))->first();
            $before = $existing?->only(['effect', 'store_id', 'reason']) ?? [];
            $row = $existing ?? new UserPermissionOverride(['user_id' => $target->id, 'permission' => $permission, 'store_id' => $storeId]);
            $row->fill(['effect' => $effect, 'reason' => $reason ?: null, 'granted_by' => $actor->id])->save();
            $target->forgetPermissionCache();
            $scope = $storeId ? 'store #'.$storeId : 'every store';
            Audit::record('user.permission_changed', ($effect === UserPermissionOverride::GRANT ? 'Gave' : 'Took away')." {$permission} ".($effect === UserPermissionOverride::GRANT ? 'to' : 'from')." {$target->name} ({$scope})", $target, $before === [] ? [] : ['override' => $before], ['override' => ['permission' => $permission, 'effect' => $effect, 'store_id' => $storeId, 'reason' => $reason]], storeId: $storeId);

            return $row;
        });
    }

    /** Back to what the roles give: removes the person's exceptions for one permission, or for all of them. */
    public function reset(User $actor, User $target, ?string $permission = null): int
    {
        $this->authorize($actor, $target);

        return DB::transaction(function () use ($actor, $target, $permission) {
            $query = UserPermissionOverride::query()->where('user_id', $target->id)->when($permission, fn ($q) => $q->where('permission', $permission));
            $removed = $query->get(['permission', 'effect', 'store_id'])->map(fn ($o) => $o->only(['permission', 'effect', 'store_id']))->all();
            $query->delete();
            $target->forgetPermissionCache();
            if ($removed !== []) {
                Audit::record('user.permission_reset', $permission ? "Reset {$permission} for {$target->name} to the role default" : "Reset all permission exceptions of {$target->name} to the role defaults", $target, ['overrides' => $removed]);
            }

            return count($removed);
        });
    }

    /** Whether the actor holds the permission for that store (null = for every store). */
    private function actorHolds(User $actor, string $permission, ?int $storeId): bool
    {
        if ($actor->isOwner()) {
            return true;
        }
        if ($storeId !== null) {
            return $actor->hasPermission($permission, $storeId);
        }
        $storeIds = Store::query()->where('is_active', true)->pluck('id')->all();
        $held = $actor->storeIdsFor($permission);

        return $storeIds !== [] && array_diff($storeIds, $held) === [];
    }

    private function authorize(User $actor, User $target): void
    {
        if (! $actor->hasPermission('settings.users.permissions')) {
            throw new BusinessRuleException('You are not allowed to change individual permissions.', 'not_permitted');
        }
        $this->sameCompany($target);
        if ($actor->id === $target->id) {
            throw new BusinessRuleException('You cannot change your own permissions. Ask another owner or admin.', 'self_permission_change');
        }
    }

    private function sameCompany(User $user): void
    {
        if ($user->company_id !== CurrentCompany::require()) {
            abort(404);
        }
    }
}
