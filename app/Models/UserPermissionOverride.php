<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A permission given to, or taken away from, one user on top of their roles. store_id null means every store. */
class UserPermissionOverride extends Model
{
    use BelongsToCompany;

    public const GRANT = 'grant';

    public const REVOKE = 'revoke';

    protected $guarded = ['id', 'company_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function granter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
