<?php

namespace App\Support\Settings;

use App\Exceptions\BusinessRuleException;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Support\Audit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Reads and writes settings for the current company with the documented
 * precedence (user > store > company > default). Writes are validated
 * against the registry, refused for scopes a setting does not allow, and
 * audited with the old and new value.
 */
final class Settings
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    public static function get(string $key, ?int $storeId = null, ?int $userId = null): mixed
    {
        $definition = SettingsRegistry::definition($key);
        $rows = self::rowsFor($key);

        if ($userId !== null && in_array(SettingsRegistry::SCOPE_USER, $definition['scopes'], true)) {
            foreach ($rows as $row) {
                if ($row['user_id'] === $userId) {
                    return $row['value'];
                }
            }
        }
        if ($storeId !== null && in_array(SettingsRegistry::SCOPE_STORE, $definition['scopes'], true)) {
            foreach ($rows as $row) {
                if ($row['store_id'] === $storeId && $row['user_id'] === null) {
                    return $row['value'];
                }
            }
        }
        foreach ($rows as $row) {
            if ($row['store_id'] === null && $row['user_id'] === null) {
                return $row['value'];
            }
        }

        return $definition['default'];
    }

    /** Where the effective value came from, for the settings screen. */
    public static function source(string $key, ?int $storeId = null, ?int $userId = null): string
    {
        $rows = self::rowsFor($key);
        foreach ($rows as $row) {
            if ($userId !== null && $row['user_id'] === $userId) {
                return 'user';
            }
        }
        foreach ($rows as $row) {
            if ($storeId !== null && $row['store_id'] === $storeId && $row['user_id'] === null) {
                return 'store';
            }
        }
        foreach ($rows as $row) {
            if ($row['store_id'] === null && $row['user_id'] === null) {
                return 'company';
            }
        }

        return 'default';
    }

    public static function set(string $key, mixed $value, string $scope = SettingsRegistry::SCOPE_COMPANY, ?int $storeId = null, ?int $userId = null): void
    {
        $definition = SettingsRegistry::definition($key);
        if (! in_array($scope, $definition['scopes'], true)) {
            throw new BusinessRuleException("“{$definition['label']}” cannot be set per {$scope}.", 'setting_scope_not_allowed', field: $key);
        }
        if ($scope === SettingsRegistry::SCOPE_STORE && $storeId === null) {
            throw new \InvalidArgumentException('A store setting needs a store id.');
        }
        if ($scope === SettingsRegistry::SCOPE_USER && $userId === null) {
            throw new \InvalidArgumentException('A user setting needs a user id.');
        }

        $value = self::normalise($definition['type'], $value);
        $validator = Validator::make(['value' => $value], ['value' => $definition['rules']], [], ['value' => $definition['label']]);
        if ($validator->fails()) {
            throw ValidationException::withMessages([$key => $validator->errors()->get('value')]);
        }
        self::guardLocked($key, $definition);

        $storeId = $scope === SettingsRegistry::SCOPE_STORE ? $storeId : null;
        $userId = $scope === SettingsRegistry::SCOPE_USER ? $userId : null;
        $old = self::get($key, $storeId, $userId);

        $row = Setting::query()
            ->where('key', $key)
            ->where(fn ($q) => $storeId === null ? $q->whereNull('store_id') : $q->where('store_id', $storeId))
            ->where(fn ($q) => $userId === null ? $q->whereNull('user_id') : $q->where('user_id', $userId))
            ->first() ?? new Setting(['key' => $key, 'store_id' => $storeId, 'user_id' => $userId]);
        $row->value = $value;
        $row->updated_by = Auth::id();
        $row->save();
        self::flush();

        if ($scope !== SettingsRegistry::SCOPE_USER) {
            Audit::record('setting.changed', "Changed setting {$key}", $row, ['value' => $old], ['value' => $value], ['scope' => $scope], $storeId);
        }
    }

    /** Removes a store or user override so the next level applies again. */
    public static function clear(string $key, string $scope, ?int $storeId = null, ?int $userId = null): void
    {
        SettingsRegistry::definition($key);
        $query = Setting::query()->where('key', $key);
        if ($scope === SettingsRegistry::SCOPE_STORE) {
            $query->where('store_id', $storeId)->whereNull('user_id');
        } elseif ($scope === SettingsRegistry::SCOPE_USER) {
            $query->where('user_id', $userId);
        } else {
            $query->whereNull('store_id')->whereNull('user_id');
        }
        $row = $query->first();
        if ($row) {
            Audit::record('setting.cleared', "Cleared {$scope} override of {$key}", $row, ['value' => $row->value], [], ['scope' => $scope], $storeId);
            $row->delete();
        }
        self::flush();
    }

    public static function flush(): void
    {
        self::$cache = [];
    }

    private static function normalise(string $type, mixed $value): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $value,
            'int' => is_numeric($value) ? (int) $value : $value,
            'decimal' => is_numeric($value) ? (string) $value : $value,
            'enum' => is_scalar($value) ? (string) $value : $value,
            default => $value,
        };
    }

    /** @param array<string, mixed> $definition */
    private static function guardLocked(string $key, array $definition): void
    {
        if (($definition['locked_after'] ?? null) === 'first_stock_movement' && StockMovement::query()->exists()) {
            $current = self::get($key);
            throw new BusinessRuleException(
                "“{$definition['label']}” is locked at “{$current}” because stock has already moved. Changing it would rewrite past results; ask your accountant to plan a controlled revaluation.",
                'setting_locked',
                field: $key,
            );
        }
    }

    /** @return list<array{store_id: ?int, user_id: ?int, value: mixed}> */
    private static function rowsFor(string $key): array
    {
        $companyId = CurrentCompany::require();
        if (! isset(self::$cache[$companyId])) {
            self::$cache[$companyId] = Setting::query()
                ->get(['key', 'store_id', 'user_id', 'value'])
                ->groupBy('key')
                ->map(fn ($rows) => $rows->map(fn ($row) => [
                    'store_id' => $row->store_id,
                    'user_id' => $row->user_id,
                    'value' => $row->value,
                ])->all())
                ->all();
        }

        return self::$cache[$companyId][$key] ?? [];
    }
}
