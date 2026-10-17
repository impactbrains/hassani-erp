<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['is_owner' => 'boolean', 'is_system' => 'boolean'];

    public function permissions(): HasMany
    {
        return $this->hasMany(RolePermission::class);
    }

    /** @return list<string> */
    public function permissionKeys(): array
    {
        return $this->permissions->pluck('permission')->sort()->values()->all();
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(UserRole::class);
    }
}
