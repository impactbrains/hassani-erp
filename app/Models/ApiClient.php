<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * A machine client of API v1 (the future e-commerce website or app).
 * Its tokens carry abilities such as "catalog:read" or "orders:write".
 */
class ApiClient extends Model
{
    use BelongsToCompany, HasApiTokens;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['abilities' => 'array', 'revoked_at' => 'datetime'];

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
