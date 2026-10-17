<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A tenant: one business using Hassani ERP. */
class Company extends Model
{
    protected $guarded = ['id'];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
