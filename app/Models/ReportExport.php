<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportExport extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected $hidden = ['file_path'];

    protected $casts = [
        'filters' => 'array',
        'is_sensitive' => 'boolean',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
        'downloaded_at' => 'datetime',
        'row_count' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
