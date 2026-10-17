<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeGuarantor extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['cnic'];

    protected $casts = [
        'cnic' => 'encrypted',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
