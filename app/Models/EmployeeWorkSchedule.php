<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeWorkSchedule extends Model
{
    use HasFactory;

    public const DAYS = [
        0 => 'Monday',
        1 => 'Tuesday',
        2 => 'Wednesday',
        3 => 'Thursday',
        4 => 'Friday',
        5 => 'Saturday',
        6 => 'Sunday',
    ];

    protected $fillable = [
        'contact_id',
        'day',
        'is_working_day',
        'start_time',
        'end_time',
        'work_location',
    ];

    protected function casts(): array
    {
        return [
            'is_working_day' => 'boolean',
        ];
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function getDayNameAttribute(): string
    {
        return self::DAYS[$this->day] ?? 'Unknown';
    }

    /**
     * Build the full Monday-Sunday week for a contact, filling in any
     * missing days as an unsaved "day off" placeholder so the frontend
     * always gets exactly 7 rows to render, whether or not the employee
     * has been fully configured yet.
     */
    public static function weekFor(int $contactId): \Illuminate\Support\Collection
    {
        $existing = self::where('contact_id', $contactId)->get()->keyBy('day');

        return collect(range(0, 6))->map(function (int $day) use ($existing, $contactId) {
            return $existing->get($day) ?? new self([
                'contact_id' => $contactId,
                'day' => $day,
                'is_working_day' => false,
            ]);
        });
    }
}