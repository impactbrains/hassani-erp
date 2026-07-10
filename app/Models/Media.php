<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Media extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'path',
        'url',
        'name',
    ];

    /**
     * The actual displayable URL, regardless of whether this media item
     * is a local upload or an external link.
     */
    public function resolvedUrl(): ?string
    {
        if ($this->type === 'url') {
            return $this->url;
        }

        return $this->path ? asset('uploads/media/' . $this->path) : null;
    }
}