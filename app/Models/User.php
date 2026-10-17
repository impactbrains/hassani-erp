<?php

namespace App\Models;

use App\Support\Permissions\PermissionRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * A login account. Deliberately separate from the employee profile: an
 * employee may have no login, and the link is the controlled contacts.user_id.
 *
 * Users are not globally tenant-scoped (login must find them by e-mail before
 * a company is known); every user-management query uses inCurrentCompany().
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_DEACTIVATED = 'deactivated';

    protected $fillable = ['name', 'email', 'password', 'locale', 'must_change_password'];

    protected $hidden = ['password', 'remember_token', 'pin_hash', 'two_factor_secret'];

    /** @var array{owner: bool, global: array<string, true>, stores: array<int, array<string, true>>, all_stores: bool, store_ids: list<int>}|null */
    private ?array $permissionMapCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }

    /** The employee profile linked to this login, if any. */
    public function employee(): HasOne
    {
        return $this->hasOne(Contact::class)->where('type', 'employee');
    }

    public function scopeInCurrentCompany(Builder $query): Builder
    {
        return $query->where('company_id', \App\Support\Tenancy\CurrentCompany::require());
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isOwner(): bool
    {
        return $this->permissionMap()['owner'];
    }

    /**
     * Whether the user holds a permission. With a store, only roles granted
     * for that store (or for every store) count; without one, any role does.
     */
    public function hasPermission(string $permission, Store|int|null $store = null): bool
    {
        if (! $this->isActive()) {
            return false;
        }
        $map = $this->permissionMap();
        if ($map['owner']) {
            return PermissionRegistry::exists($permission);
        }
        if (isset($map['global'][$permission])) {
            return true;
        }
        $storeId = $store instanceof Store ? $store->id : $store;
        if ($storeId === null) {
            foreach ($map['stores'] as $permissions) {
                if (isset($permissions[$permission])) {
                    return true;
                }
            }

            return false;
        }

        return isset($map['stores'][$storeId][$permission]);
    }

    /** @param list<string> $permissions */
    public function hasAnyPermission(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Store ids where the user holds a permission. Owners and every-store
     * grants get all active stores of the company.
     *
     * @return list<int>
     */
    public function storeIdsFor(string $permission): array
    {
        $map = $this->permissionMap();
        if ($map['owner'] || isset($map['global'][$permission])) {
            return $this->companyStoreIds();
        }
        $ids = [];
        foreach ($map['stores'] as $storeId => $permissions) {
            if (isset($permissions[$permission])) {
                $ids[] = $storeId;
            }
        }

        return $ids;
    }

    /** @return list<int> Stores the user may work in at all. */
    public function accessibleStoreIds(): array
    {
        $map = $this->permissionMap();

        return ($map['owner'] || $map['all_stores']) ? $this->companyStoreIds() : $map['store_ids'];
    }

    /** @return list<string> Flattened permission keys (any store), shared with the UI for display only. */
    public function permissionKeys(): array
    {
        $map = $this->permissionMap();
        if ($map['owner']) {
            return PermissionRegistry::keys();
        }
        $keys = array_keys($map['global']);
        foreach ($map['stores'] as $permissions) {
            $keys = array_merge($keys, array_keys($permissions));
        }

        return array_values(array_unique($keys));
    }

    public function forgetPermissionCache(): void
    {
        $this->permissionMapCache = null;
    }

    /** @return array{owner: bool, global: array<string, true>, stores: array<int, array<string, true>>, all_stores: bool, store_ids: list<int>} */
    private function permissionMap(): array
    {
        if ($this->permissionMapCache !== null) {
            return $this->permissionMapCache;
        }

        $map = ['owner' => false, 'global' => [], 'stores' => [], 'all_stores' => false, 'store_ids' => []];
        $assignments = UserRole::query()
            ->withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where('user_id', $this->id)
            ->active()
            ->with(['role' => fn ($q) => $q->withoutGlobalScopes()->with('permissions')])
            ->get();

        foreach ($assignments as $assignment) {
            $role = $assignment->role;
            if ($role === null) {
                continue;
            }
            if ($role->is_owner) {
                $map['owner'] = true;
            }
            $keys = array_fill_keys($role->permissions->pluck('permission')->all(), true);
            if ($assignment->store_id === null) {
                $map['global'] += $keys;
                $map['all_stores'] = true;
            } else {
                $map['stores'][$assignment->store_id] = ($map['stores'][$assignment->store_id] ?? []) + $keys;
                $map['store_ids'][] = $assignment->store_id;
            }
        }
        $map['store_ids'] = array_values(array_unique($map['store_ids']));

        return $this->permissionMapCache = $map;
    }

    /** @return list<int> */
    private function companyStoreIds(): array
    {
        return Store::query()->withoutGlobalScopes()
            ->where('company_id', $this->company_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }
}
