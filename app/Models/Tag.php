<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_tag');
    }
}
