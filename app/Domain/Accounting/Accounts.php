<?php

namespace App\Domain\Accounting;

use App\Models\Account;
use App\Support\Tenancy\CurrentCompany;

/** Looks up system accounts (cash, receivables, inventory ...) by their stable key. */
final class Accounts
{
    /** @var array<int, array<string, int>> */
    private static array $cache = [];

    public static function id(string $systemKey): int
    {
        $companyId = CurrentCompany::require();
        if (! isset(self::$cache[$companyId])) {
            self::$cache[$companyId] = Account::query()->whereNotNull('system_key')->pluck('id', 'system_key')->all();
        }
        if (! isset(self::$cache[$companyId][$systemKey])) {
            throw new \RuntimeException("System account [{$systemKey}] is missing for this company.");
        }

        return self::$cache[$companyId][$systemKey];
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
