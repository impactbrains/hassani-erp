<?php

namespace App\Domain\Accounting;

use App\Exceptions\BusinessRuleException;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\Decimal;
use App\Support\Sequences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Posts balanced journal entries. Every automatic entry is tied to the
 * business document that caused it (source) and a purpose ("sale", "cogs",
 * "payment" ...); posting the same source and purpose twice returns the
 * existing entry instead of double-counting. The database independently
 * rejects unbalanced or edited posted entries.
 */
final class JournalPoster
{
    public function __construct(private readonly FiscalCalendar $calendar)
    {
    }

    /**
     * @param  list<array{account: string|int, debit?: string|null, credit?: string|null, contact_id?: int|null, memo?: string|null, store_id?: int|null}>  $lines
     */
    public function post(
        string $purpose,
        ?Model $source,
        string $date,
        string $description,
        array $lines,
        ?int $storeId = null,
        string $kind = 'auto',
    ): ?JournalEntry {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(fn () => $this->post($purpose, $source, $date, $description, $lines, $storeId, $kind));
        }

        if ($source !== null) {
            $existing = JournalEntry::query()
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->where('purpose', $purpose)
                ->whereNull('reversal_of_id')
                ->first();
            if ($existing) {
                return $existing;
            }
        }

        $normalised = $this->normalise($lines);
        if ($normalised === []) {
            return null;
        }
        $this->assertBalanced($normalised, $description);
        $this->calendar->assertOpen($date);

        // Created as a draft, filled, then flipped to posted in one statement so
        // the database balance check sees the complete entry.
        $entry = JournalEntry::create([
            'store_id' => $storeId,
            'number' => Sequences::next('journal'),
            'entry_date' => $date,
            'kind' => $kind,
            'status' => 'draft',
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'purpose' => $purpose,
            'description' => mb_substr($description, 0, 255),
            'created_by' => Auth::id(),
        ]);
        $this->insertLines($entry, $date, array_map(fn ($line) => ['store_id' => $line['store_id'] ?? $storeId] + $line, $normalised));
        $this->markPosted($entry);

        return $entry;
    }

    /** Posts the mirror image of an entry, dated $date, and links it to the original. */
    public function reverse(JournalEntry $entry, string $date, string $reason): JournalEntry
    {
        if ($entry->reversal()->exists()) {
            throw new BusinessRuleException("Journal {$entry->number} has already been reversed.", 'already_reversed');
        }

        return DB::transaction(function () use ($entry, $date, $reason) {
            $this->calendar->assertOpen($date);
            $reversal = JournalEntry::create([
                'store_id' => $entry->store_id,
                'number' => Sequences::next('journal'),
                'entry_date' => $date,
                'kind' => $entry->kind,
                'status' => 'draft',
                'source_type' => $entry->source_type,
                'source_id' => $entry->source_id,
                'purpose' => $entry->purpose,
                'description' => mb_substr("Reversal of {$entry->number}: {$reason}", 0, 255),
                'reversal_of_id' => $entry->id,
                'created_by' => Auth::id(),
            ]);
            $lines = $entry->lines->map(fn ($line) => [
                'account_id' => $line->account_id,
                'debit' => $line->credit,
                'credit' => $line->debit,
                'contact_id' => $line->contact_id,
                'store_id' => $line->store_id,
                'memo' => $line->memo,
            ])->all();
            $this->insertLines($reversal, $date, $lines);
            $this->markPosted($reversal);

            return $reversal;
        });
    }

    /** @param list<array{account_id: int, debit: string, credit: string, contact_id: ?int, memo: ?string, store_id: ?int}> $lines */
    private function insertLines(JournalEntry $entry, string $date, array $lines): void
    {
        JournalLine::insert(array_map(fn ($line) => [
            'company_id' => $entry->company_id,
            'journal_entry_id' => $entry->id,
            'account_id' => $line['account_id'],
            'debit' => $line['debit'],
            'credit' => $line['credit'],
            'contact_id' => $line['contact_id'],
            'store_id' => $line['store_id'],
            'entry_date' => $date,
            'memo' => $line['memo'],
        ], $lines));
    }

    private function markPosted(JournalEntry $entry): void
    {
        $entry->status = 'posted';
        $entry->posted_by = Auth::id();
        $entry->posted_at = now();
        $entry->save();
    }

    /**
     * Resolves accounts, drops zero lines and turns negative amounts into the
     * opposite side so callers can express nets naturally.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array{account_id: int, debit: string, credit: string, contact_id: ?int, memo: ?string, store_id: ?int}>
     */
    private function normalise(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $net = Decimal::sub($line['debit'] ?? '0', $line['credit'] ?? '0');
            $net = Decimal::money($net);
            if (Decimal::isZero($net)) {
                continue;
            }
            $accountId = is_int($line['account']) ? $line['account'] : Accounts::id($line['account']);
            $result[] = [
                'account_id' => $accountId,
                'debit' => Decimal::isPositive($net) ? $net : '0.00',
                'credit' => Decimal::isNegative($net) ? Decimal::money(Decimal::abs($net)) : '0.00',
                'contact_id' => $line['contact_id'] ?? null,
                'memo' => isset($line['memo']) ? mb_substr((string) $line['memo'], 0, 255) : null,
                'store_id' => $line['store_id'] ?? null,
            ];
        }

        return $result;
    }

    /** @param list<array{debit: string, credit: string}> $lines */
    private function assertBalanced(array $lines, string $description): void
    {
        $debit = Decimal::sum(array_column($lines, 'debit'));
        $credit = Decimal::sum(array_column($lines, 'credit'));
        if (! Decimal::eq($debit, $credit)) {
            throw new \LogicException("Unbalanced journal for “{$description}”: debit {$debit}, credit {$credit}.");
        }
    }
}
