<?php

namespace App\Support\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $companyId = CurrentCompany::id();
        if ($companyId !== null) {
            $builder->where($model->qualifyColumn('company_id'), $companyId);

            return;
        }

        if (CurrentCompany::isBypassed()) {
            return;
        }

        throw new MissingTenantContext('Tenant query on '.$model::class.' without a company context.');
    }
}
