<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReportRecent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'viewed_at' => 'datetime',
    ];
}
