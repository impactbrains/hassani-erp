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
    public function getIsImageAttribute(): bool
{
    if ($this->type === 'url') {
        // Can't sniff a remote URL's mime type without an HTTP call, so
        // assume image — url-type media in this system has only ever
        // been used for image links so far.
        return true;
    }
 
    if ($this->mime_type === null) {
        return true;
    }
 
    return str_starts_with($this->mime_type, 'image/');
}
    public function getFormattedSizeAttribute(): ?string
    {
        if (! $this->size) {
            return null;
        }
    
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = $this->size;
        $i = 0;
    
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
    
        return round($bytes, 1) . ' ' . $units[$i];
    }
}