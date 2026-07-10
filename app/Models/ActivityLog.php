<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'app',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ActivityLog $log) {
            $log->created_at ??= now();
            $log->user_id ??= auth()->id();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Record an activity. Call this from any app's controller after a
     * meaningful action, e.g.:
     *
     * ActivityLog::log('pos', 'confirmed', "Confirmed sale {$document->number}", $document);
     */
    public static function log(string $app, string $action, string $description, ?Model $subject = null, array $metadata = []): self
    {
        return self::create([
            'app' => $app,
            'action' => $action,
            'description' => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata,
        ]);
    }

    public function scopeForApp($query, string $app)
    {
        return $query->where('app', $app);
    }
}