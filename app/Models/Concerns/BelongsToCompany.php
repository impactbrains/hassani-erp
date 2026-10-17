<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\Tenancy\CompanyScope;
use App\Support\Tenancy\CurrentCompany;
use App\Support\Tenancy\MissingTenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Makes a model tenant-owned: every query is filtered to the current
 * company and every insert is stamped with it. A record can never be
 * created for, or moved into, a different company than the current one.
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function ($model) {
            $current = CurrentCompany::id();
            if ($model->company_id === null) {
                if ($current === null) {
                    throw new MissingTenantContext('Cannot create '.static::class.' without a company context.');
                }
                $model->company_id = $current;
            } elseif ($current !== null && (int) $model->company_id !== $current) {
                throw new MissingTenantContext('Refusing to create '.static::class.' for another company.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('company_id')) {
                throw new MissingTenantContext('A record cannot be moved to another company.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
