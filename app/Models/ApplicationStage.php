<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ApplicationStage extends Model
{
    use BelongsToCompany;

    use HasFactory;

    protected $fillable = [
        'name',
        'sort_order',
        'is_default',
        'is_terminal',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_terminal' => 'boolean',
        ];
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(Contact::class, 'application_stage_id');
    }
}