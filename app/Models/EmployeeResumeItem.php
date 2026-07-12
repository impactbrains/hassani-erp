<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeResumeItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'contact_id',
        'type',
        'title',
        'subtitle',
        'start_date',
        'end_date',
        'is_current',
        'description',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function scopeExperience($query)
    {
        return $query->where('type', 'experience');
    }

    public function scopeEducation($query)
    {
        return $query->where('type', 'education');
    }
}