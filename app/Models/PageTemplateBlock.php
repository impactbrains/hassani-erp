<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageTemplateBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'page_template_id',
        'block_definition_id',
        'position',
        'content',
        'style',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'style' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PageTemplate::class, 'page_template_id');
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(BlockDefinition::class, 'block_definition_id');
    }
}
