<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobFormField extends Model
{
    use HasFactory;

    protected $fillable = [
        'job_ats_id',
        'label',
        'field_type',
        'options',
        'is_required',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(JobAts::class, 'job_ats_id');
    }

    /**
     * Field types where `options` is meaningful — used by validation
     * (require options for these, ignore/null it out for others) and
     * by the frontend to decide whether to show the choice-list editor.
     */
    public const CHOICE_TYPES = ['dropdown', 'checkbox', 'radio'];
}