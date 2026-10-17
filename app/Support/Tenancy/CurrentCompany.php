<?php

namespace App\Support\Tenancy;

use App\Models\Company;

/**
 * Holds the tenant (company) for the current request, job or command.
 *
 * Every tenant-owned model filters on this value through CompanyScope. When
 * no company is set, tenant queries fail loudly instead of silently reading
 * across companies; cross-tenant system work must opt in with bypass().
 */
final class CurrentCompany
{
    private static ?int $companyId = null;

    private static ?Company $company = null;

    private static int $bypassDepth = 0;

    public static function set(Company|int $company): void
    {
        if ($company instanceof Company) {
            self::$company = $company;
            self::$companyId = $company->id;

            return;
        }

        self::$companyId = $company;
        self::$company = null;
    }

    public static function id(): ?int
    {
        return self::$companyId;
    }

    public static function require(): int
    {
        if (self::$companyId === null) {
            throw new MissingTenantContext('No company context is set.');
        }

        return self::$companyId;
    }

    public static function get(): ?Company
    {
        if (self::$companyId === null) {
            return null;
        }

        return self::$company ??= Company::query()->find(self::$companyId);
    }

    public static function clear(): void
    {
        self::$companyId = null;
        self::$company = null;
    }

    /**
     * Runs a callback inside another company's context and restores the
     * previous context afterwards (used by queued jobs and console commands).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function runAs(Company|int $company, callable $callback): mixed
    {
        $previousId = self::$companyId;
        $previous = self::$company;
        self::set($company);
        try {
            return $callback();
        } finally {
            self::$companyId = $previousId;
            self::$company = $previous;
        }
    }

    /**
     * Explicitly allows unscoped tenant queries for platform-level work such
     * as provisioning a new company. Use sparingly.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function bypass(callable $callback): mixed
    {
        self::$bypassDepth++;
        try {
            return $callback();
        } finally {
            self::$bypassDepth--;
        }
    }

    public static function isBypassed(): bool
    {
        return self::$bypassDepth > 0;
    }
}
