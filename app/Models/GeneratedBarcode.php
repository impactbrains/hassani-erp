<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class GeneratedBarcode extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'code',
        'label',
        'status',
    ];
}