<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/** The saved POS screen (cart tabs) of one person in one store. See PosWorkspaceService. */
class PosWorkspace extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['state' => 'array', 'version' => 'integer'];
}
