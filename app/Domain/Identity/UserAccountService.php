<?php

namespace App\Domain\Identity;

use App\Exceptions\BusinessRuleException;
use App\Models\Contact;
use App\Models\LoginEvent;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use App\Support\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Login accounts: creation (there is no public sign-up), roles per store,
 * suspension, deactivation with session and API-token revocation, PINs,
 * and links to employee records.
 *
 * Guards against privilege escalation: nobody can grant a role holding
 * permissions they do not hold themselves, edit their own roles, or remove
 * the last active owner.
 */
final class UserAccountService
{
    /**
     * @param  array{name: string, email: string, locale?: string|null, employee_id?: int|null, temporary_password?: string|null}  $data
     * @param  list<array{role_id: int, store_id?: int|null, expires_at?: string|null}>  $roles
     * @return array{user: User, temporary_password: string}
     */
    public function create(User $actor, array $data, array $roles): array
    {
        $this->authorize($actor, 'settings.users.manage');
        $email = mb_strtolower(trim($data['email']));
        if (User::query()->where('email', $email)->exists()) {
            throw new BusinessRuleException('An account with this e-mail already exists.', 'duplicate_email', field: 'email');
        }

        return DB::transaction(function () use ($actor, $data, $roles, $email) {
            $temporary = ! empty($data['temporary_password']) ? (string) $data['temporary_password'] : Str::password(14, symbols: false);
            $user = new User(['name' => $data['name'], 'email' => $email, 'password' => $temporary, 'locale' => $data['locale'] ?? null, 'must_change_password' => true]);
            $user->company_id = CurrentCompany::require();
            $user->status = User::STATUS_ACTIVE;
            $user->save();
            $this->assignRoles($actor, $user, $roles);
            if (! empty($data['employee_id'])) {
                $this->linkEmployee($actor, $user, (int) $data['employee_id']);
            }
            Audit::record('user.created', "Created login for {$user->name} ({$user->email})", $user, new: ['roles' => $roles]);

            return ['user' => $user, 'temporary_password' => $temporary];
        });
    }

    /** @param list<array{role_id: int, store_id?: int|null, expires_at?: string|null, reason?: string|null}> $roles */
    public function assignRoles(User $actor, User $user, array $roles): void
    {
        $this->authorize($actor, 'settings.users.manage');
        $this->sameCompany($user);
        if ($actor->id === $user->id) {
            throw new BusinessRuleException('You cannot change your own roles. Ask another owner or admin.', 'self_role_change');
        }
        $roleModels = Role::query()->with('permissions')->whereIn('id', array_column($roles, 'role_id'))->get()->keyBy('id');
        foreach ($roles as $assignment) {
            $role = $roleModels[$assignment['role_id']] ?? throw new BusinessRuleException('Unknown role.', 'invalid_role');
            $this->assertCanGrant($actor, $role);
        }

        DB::transaction(function () use ($actor, $user, $roles, $roleModels) {
            $before = $user->roleAssignments()->with('role:id,key')->get()->map(fn ($a) => ['role' => $a->role?->key, 'store_id' => $a->store_id])->all();
            $removingOwner = $user->roleAssignments()->whereHas('role', fn ($q) => $q->where('is_owner', true))->exists()
                && ! collect($roles)->contains(fn ($r) => $roleModels[$r['role_id']]->is_owner);
            if ($removingOwner) {
                $this->assertNotLastOwner($user);
            }
            UserRole::query()->where('user_id', $user->id)->delete();
            foreach ($roles as $assignment) {
                UserRole::create([
                    'user_id' => $user->id,
                    'role_id' => $assignment['role_id'],
                    'store_id' => $assignment['store_id'] ?? null,
                    'expires_at' => $assignment['expires_at'] ?? null,
                    'reason' => $assignment['reason'] ?? null,
                    'granted_by' => $actor->id,
                ]);
            }
            $user->forgetPermissionCache();
            $after = collect($roles)->map(fn ($r) => ['role' => $roleModels[$r['role_id']]->key, 'store_id' => $r['store_id'] ?? null, 'expires_at' => $r['expires_at'] ?? null])->all();
            Audit::record('user.roles_changed', "Changed roles of {$user->name}", $user, ['roles' => $before], ['roles' => $after]);
        });
    }

    public function suspend(User $actor, User $user, string $reason): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_SUSPENDED, $reason);
    }

    public function deactivate(User $actor, User $user, string $reason): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_DEACTIVATED, $reason);
    }

    /** Used by HR offboarding: the login follows the employee's status even if HR cannot manage users. */
    public function deactivateForOffboarding(User $actor, User $user, string $reason): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_DEACTIVATED, $reason, checkPermission: false);
    }

    public function suspendForHr(User $actor, User $user, string $reason): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_SUSPENDED, $reason, checkPermission: false);
    }

    /** Used by HR when an employee is reinstated or rehired: the login comes back with them. */
    public function reactivateForHr(User $actor, User $user, string $reason): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_ACTIVE, $reason, checkPermission: false);
    }

    /** Whether this actor may give the role to someone (the same rule assignRoles enforces). */
    public function canGrant(User $actor, Role $role): bool
    {
        try {
            $this->assertCanGrant($actor, $role);

            return true;
        } catch (BusinessRuleException) {
            return false;
        }
    }

    public function reactivate(User $actor, User $user): User
    {
        return $this->changeStatus($actor, $user, User::STATUS_ACTIVE, 'Reactivated');
    }

    /** Ends every browser session and API token of the user. */
    public function revokeAccess(User $user, string $reason): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
        LoginEvent::create(['company_id' => $user->company_id, 'user_id' => $user->id, 'email' => $user->email, 'event' => 'sessions_revoked', 'user_agent' => $reason]);
    }

    public function revokeSession(User $actor, User $user, string $sessionId): void
    {
        if ($actor->id !== $user->id) {
            $this->authorize($actor, 'settings.security.manage');
        }
        $this->sameCompany($user);
        DB::table('sessions')->where('user_id', $user->id)->where('id', $sessionId)->delete();
        Audit::record('user.session_revoked', "Signed {$user->name} out of a device", $user);
    }

    /** @return string a new temporary password the admin passes on in person */
    public function resetPassword(User $actor, User $user): string
    {
        $this->authorize($actor, 'settings.users.manage');
        $this->sameCompany($user);
        $temporary = Str::password(14, symbols: false);
        $user->forceFill(['password' => $temporary, 'must_change_password' => true])->save();
        $this->revokeAccess($user, 'Password reset by admin');
        Audit::record('user.password_reset', "Reset password of {$user->name}", $user);

        return $temporary;
    }

    public function setPin(User $user, string $currentPassword, string $pin): void
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw new BusinessRuleException('Your password is not correct.', 'password_invalid', field: 'current_password');
        }
        if (! preg_match('/^\d{4,8}$/', $pin) || preg_match('/^(\d)\1+$/', $pin) || in_array($pin, ['1234', '12345', '123456', '0123', '4321'], true)) {
            throw new BusinessRuleException('Use 4 to 8 digits that are not all the same or in a simple sequence.', 'weak_pin', field: 'pin');
        }
        $user->forceFill(['pin_hash' => Hash::make($pin)])->save();
        Audit::record('user.pin_set', "{$user->name} set an approval PIN", $user);
    }

    public function linkEmployee(User $actor, User $user, ?int $employeeId): void
    {
        $this->authorize($actor, 'settings.users.manage');
        $this->sameCompany($user);
        DB::transaction(function () use ($user, $employeeId) {
            Contact::query()->where('user_id', $user->id)->update(['user_id' => null]);
            if ($employeeId) {
                $employee = Contact::query()->employees()->findOrFail($employeeId);
                if ($employee->user_id && $employee->user_id !== $user->id) {
                    throw new BusinessRuleException("{$employee->name} is already linked to another login.", 'employee_already_linked');
                }
                $employee->update(['user_id' => $user->id]);
            }
            Audit::record('user.employee_linked', "Linked {$user->name} to employee #{$employeeId}", $user);
        });
    }

    private function changeStatus(User $actor, User $user, string $status, string $reason, bool $checkPermission = true): User
    {
        if ($checkPermission) {
            $this->authorize($actor, 'settings.users.manage');
        }
        $this->sameCompany($user);
        if ($status !== User::STATUS_ACTIVE) {
            if ($actor->id === $user->id) {
                throw new BusinessRuleException('You cannot suspend or deactivate your own account.', 'self_status_change');
            }
            if ($user->isOwner()) {
                $this->assertNotLastOwner($user);
            }
        }

        return DB::transaction(function () use ($actor, $user, $status, $reason) {
            $old = $user->status;
            $user->forceFill([
                'status' => $status,
                'deactivated_at' => $status === User::STATUS_ACTIVE ? null : now(),
                'deactivated_by' => $status === User::STATUS_ACTIVE ? null : $actor->id,
            ])->save();
            if ($status !== User::STATUS_ACTIVE) {
                $this->revokeAccess($user, $reason);
            }
            Audit::record('user.status_changed', "{$user->name}: {$old} → {$status} ({$reason})", $user, ['status' => $old], ['status' => $status]);

            return $user;
        });
    }

    private function assertCanGrant(User $actor, Role $role): void
    {
        if ($role->is_owner && ! $actor->isOwner()) {
            throw new BusinessRuleException('Only an owner can make someone an owner.', 'privilege_escalation');
        }
        if ($actor->isOwner()) {
            return;
        }
        $missing = array_diff($role->permissions->pluck('permission')->all(), $actor->permissionKeys());
        if ($missing !== []) {
            throw new BusinessRuleException("You cannot grant “{$role->name}” because it includes permissions you do not have.", 'privilege_escalation', ['missing' => array_values($missing)]);
        }
    }

    public function assertNotLastOwner(User $user): void
    {
        $owners = User::query()->inCurrentCompany()->where('status', User::STATUS_ACTIVE)
            ->whereHas('roleAssignments', fn ($q) => $q->whereHas('role', fn ($r) => $r->where('is_owner', true))->where(fn ($e) => $e->whereNull('expires_at')->orWhere('expires_at', '>', now())))
            ->pluck('id');
        if ($owners->count() <= 1 && $owners->contains($user->id)) {
            throw new BusinessRuleException('This is the last active owner. Make someone else an owner first.', 'last_owner');
        }
    }

    private function authorize(User $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) {
            throw new BusinessRuleException('You are not allowed to manage users.', 'not_permitted');
        }
    }

    private function sameCompany(User $user): void
    {
        if ($user->company_id !== CurrentCompany::require()) {
            abort(404);
        }
    }
}
