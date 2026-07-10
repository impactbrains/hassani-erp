<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Sequence extends Model
{
    use HasFactory;

    protected $fillable = [
        'document_type',
        'prefix',
        'next_number',
        'padding',
    ];

    /**
     * Atomically claim the next number for a document type and return the
     * formatted string (e.g. "ONO-0001"). Uses a row lock so concurrent
     * checkouts can never receive the same number.
     */
    public static function next(string $documentType): string
    {
        return DB::transaction(function () use ($documentType) {
            $sequence = self::where('document_type', $documentType)
                ->lockForUpdate()
                ->firstOrFail();

            $formatted = $sequence->prefix . str_pad(
                (string) $sequence->next_number,
                $sequence->padding,
                '0',
                STR_PAD_LEFT
            );

            $sequence->increment('next_number');

            return $formatted;
        });
    }
}