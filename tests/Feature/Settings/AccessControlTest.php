<?php

use App\Domain\Identity\RoleService;
use App\Domain\Identity\UserAccountService;
use App\Exceptions\BusinessRuleException;
use App\Models\Role;
use App\Support\Settings\Settings;
use App\Support\Settings\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->setUpCompany();
    $this->owner = $this->userWithRole('owner');
    $this->accounts = app(UserAccountService::class);
});

it('scopes permissions to the stores a role was granted for', function () {
    $second = app(\App\Domain\Setup\CompanyProvisioner::class)->createStore('Branch 2', 'B2');
    $cashier = $this->userWithRole('cashier', $this->store);

    expect($cashier->hasPermission('pos.sell', $this->store->id))->toBeTrue()
        ->and($cashier->hasPermission('pos.sell', $second->id))->toBeFalse()
        ->and($cashier->hasPermission('reports.finance'))->toBeFalse()
        ->and($cashier->accessibleStoreIds())->toBe([$this->store->id]);
});

it('stops a manager from granting roles with permissions they do not hold', function () {
    $admin = $this->userWithRole('store_manager');
    $admin->roleAssignments()->first()->role->permissions()->create(['permission' => 'settings.users.manage']);
    $admin->forgetPermissionCache();
    $target = $this->userWithRole('cashier', $this->store);

    expect(fn () => DB::transaction(fn () => $this->accounts->assignRoles($admin, $target, [['role_id' => Role::query()->where('key', 'accountant')->value('id')]])))
        ->toThrow(BusinessRuleException::class, 'permissions you do not have');
    expect(fn () => $this->accounts->assignRoles($admin, $target, [['role_id' => Role::query()->where('key', 'owner')->value('id')]]))
        ->toThrow(BusinessRuleException::class, 'Only an owner');
    expect(fn () => $this->accounts->assignRoles($admin, $admin, [['role_id' => Role::query()->where('key', 'cashier')->value('id')]]))
        ->toThrow(BusinessRuleException::class, 'your own roles');
});

it('never removes or deactivates the last owner', function () {
    $admin = $this->userWithRole('store_manager');
    $admin->roleAssignments()->first()->role->permissions()->create(['permission' => 'settings.users.manage']);
    $admin->forgetPermissionCache();

    expect(fn () => $this->accounts->deactivate($this->owner, $this->owner, 'x'))->toThrow(BusinessRuleException::class, 'your own account');
    expect(fn () => $this->accounts->deactivate($admin, $this->owner, 'x'))->toThrow(BusinessRuleException::class, 'last active owner');

    $second = $this->userWithRole('owner');
    expect($this->accounts->deactivate($second, $this->owner, 'left the business')->status)->toBe('deactivated');
    expect(fn () => $this->accounts->assignRoles($this->owner->fresh(), $second, []))->toThrow(BusinessRuleException::class, 'not allowed');
});

it('refuses to let a role editor add permissions they lack', function () {
    $editor = $this->userWithRole('store_manager');
    $editor->roleAssignments()->first()->role->permissions()->create(['permission' => 'settings.roles.manage']);
    $editor->forgetPermissionCache();

    expect(fn () => app(RoleService::class)->save($editor, ['name' => 'Super cashier'], ['pos.sell', 'accounting.journals.post']))
        ->toThrow(BusinessRuleException::class, 'do not have yourself');
    $role = app(RoleService::class)->save($editor, ['name' => 'Counter helper'], ['pos.access', 'pos.sell']);
    expect($role->permissionKeys())->toBe(['pos.access', 'pos.sell']);
    expect(fn () => app(RoleService::class)->save($this->owner, ['name' => 'x'], [], Role::query()->where('is_owner', true)->first()))->toThrow(BusinessRuleException::class, 'owner role');
});

it('validates settings, refuses unsupported scopes and applies store then user precedence', function () {
    expect(fn () => Settings::set('pricing.max_discount_percent', '150'))->toThrow(ValidationException::class);
    expect(fn () => Settings::set('inventory.valuation_method', 'lifo'))->toThrow(ValidationException::class);
    expect(fn () => Settings::set('fiscal.year_start_month', 1, SettingsRegistry::SCOPE_STORE, $this->store->id))->toThrow(BusinessRuleException::class, 'cannot be set per store');
    expect(fn () => Settings::get('unknown.key'))->toThrow(InvalidArgumentException::class);

    Settings::set('pos.receipt_footer', 'Company footer');
    Settings::set('pos.receipt_footer', 'Store footer', SettingsRegistry::SCOPE_STORE, $this->store->id);
    expect(Settings::get('pos.receipt_footer'))->toBe('Company footer')
        ->and(Settings::get('pos.receipt_footer', $this->store->id))->toBe('Store footer');

    Settings::set('localization.default_locale', 'ur', SettingsRegistry::SCOPE_USER, userId: $this->owner->id);
    expect(Settings::get('localization.default_locale', null, $this->owner->id))->toBe('ur')
        ->and(Settings::get('localization.default_locale'))->toBe('en');
    expect(\App\Models\AuditLog::query()->where('event', 'setting.changed')->count())->toBe(2);
});
