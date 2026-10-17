<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Sequence extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];
}
