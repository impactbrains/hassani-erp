<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Login history. Not tenant-scoped because failed logins may have no company. */
class LoginEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];
}
