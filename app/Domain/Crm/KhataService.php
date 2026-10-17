<?php

namespace App\Domain\Crm;

use App\Domain\Accounting\Accounts;
use App\Models\Contact;
use App\Models\JournalLine;
use App\Models\SalesInvoice;
use App\Support\Decimal;
use Carbon\CarbonImmutable;

/**
 * Customer statements and aging. Statement lines come straight from the
 * accounts-receivable ledger, so the closing balance always equals the
 * Khata balance shown at the POS and in reports.
 */
final class KhataService
{
    /** @return array{opening: string, closing: string, lines: list<array<string, mixed>>, from: string, to: string} */
    public function statement(Contact $customer, string $from, string $to): array
    {
        $ar = Accounts::id('accounts_receivable');
        $opening = JournalLine::query()->where('account_id', $ar)->where('contact_id', $customer->id)->where('entry_date', '<', $from)
            ->selectRaw('COALESCE(SUM(debit - credit), 0) as balance')->value('balance');
        $rows = JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $ar)->where('journal_lines.contact_id', $customer->id)
            ->whereBetween('journal_lines.entry_date', [$from, $to])
            ->orderBy('journal_lines.entry_date')->orderBy('journal_lines.id')
            ->get(['journal_lines.entry_date', 'journal_lines.debit', 'journal_lines.credit', 'journal_entries.description', 'journal_entries.number', 'journal_entries.source_type', 'journal_entries.source_id', 'journal_entries.purpose']);

        $balance = Decimal::money($opening ?? '0');
        $lines = [];
        foreach ($rows as $row) {
            $balance = Decimal::money(Decimal::add($balance, Decimal::sub($row->debit, $row->credit)));
            $lines[] = [
                'date' => CarbonImmutable::parse($row->entry_date)->toDateString(),
                'description' => $row->description,
                'journal' => $row->number,
                'purpose' => $row->purpose,
                'source_type' => $row->source_type,
                'source_id' => $row->source_id,
                'debit' => $row->debit,
                'credit' => $row->credit,
                'balance' => $balance,
            ];
        }

        return ['opening' => Decimal::money($opening ?? '0'), 'closing' => $balance, 'lines' => $lines, 'from' => $from, 'to' => $to];
    }

    /**
     * Open invoice balances by days past due.
     *
     * @return array{buckets: array<string, string>, total: string, invoices: list<array<string, mixed>>}
     */
    public function aging(int $customerId, string $asOf): array
    {
        $buckets = ['current' => '0', '1_30' => '0', '31_60' => '0', '61_90' => '0', 'over_90' => '0'];
        $invoices = [];
        foreach (SalesInvoice::query()->where('customer_id', $customerId)->where('status', 'posted')->whereIn('payment_status', ['unpaid', 'partial'])->orderBy('due_date')->get() as $invoice) {
            $due = $invoice->balanceDue();
            if (! Decimal::isPositive($due)) {
                continue;
            }
            $days = $invoice->due_date ? max(0, (int) $invoice->due_date->diffInDays(CarbonImmutable::parse($asOf), false)) : 0;
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                $days <= 90 => '61_90',
                default => 'over_90',
            };
            $buckets[$bucket] = Decimal::add($buckets[$bucket], $due);
            $invoices[] = ['id' => $invoice->id, 'number' => $invoice->number, 'date' => $invoice->business_date->toDateString(), 'due_date' => $invoice->due_date?->toDateString(), 'total' => $invoice->total, 'balance' => $due, 'days_overdue' => $days, 'bucket' => $bucket];
        }

        return ['buckets' => array_map(fn ($v) => Decimal::money($v), $buckets), 'total' => Decimal::money(Decimal::sum($buckets)), 'invoices' => $invoices];
    }
}
