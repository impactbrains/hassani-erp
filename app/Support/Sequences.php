<?php

namespace App\Support;

use App\Models\Sequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Gap-free document numbers per company (and optionally per store), issued
 * under a row lock inside the caller's transaction. If the transaction rolls
 * back, the number is not consumed.
 */
final class Sequences
{
    private const DEFAULT_PREFIXES = [
        'pos_receipt' => 'R-', 'invoice' => 'INV-', 'credit_note' => 'CN-', 'sales_order' => 'SO-',
        'payment' => 'PAY-', 'shift' => 'SH-', 'cash_movement' => 'CM-', 'delivery' => 'DL-', 'rider_handover' => 'RH-',
        'purchase_order' => 'PO-', 'goods_receipt' => 'GRN-', 'supplier_bill' => 'BILL-', 'supplier_return' => 'SR-',
        'stock_adjustment' => 'ADJ-', 'stock_transfer' => 'TR-', 'stock_count' => 'CNT-', 'repack' => 'RP-',
        'journal' => 'JV-', 'crm_case' => 'CASE-', 'payroll_run' => 'PR-', 'employee_advance' => 'ADV-',
        'customer' => 'C-', 'supplier' => 'S-', 'employee' => 'E-',
    ];

    public static function next(string $key, ?int $storeId = null, ?string $storeCode = null): string
    {
        return DB::transaction(function () use ($key, $storeId, $storeCode) {
            $sequence = self::locked($key, $storeId);
            if ($sequence === null) {
                $prefix = (self::DEFAULT_PREFIXES[$key] ?? strtoupper($key).'-').($storeCode ? $storeCode.'-' : '');
                try {
                    // Savepoint: a concurrent first insert must not abort the caller's transaction.
                    DB::transaction(fn () => Sequence::create([
                        'store_id' => $storeId, 'key' => $key, 'prefix' => $prefix, 'next_number' => 1, 'padding' => 6,
                    ]));
                } catch (UniqueConstraintViolationException) {
                    // Another request created it first; fall through and lock it.
                }
                $sequence = self::locked($key, $storeId);
            }

            $number = (int) $sequence->next_number;
            $sequence->next_number = $number + 1;
            $sequence->save();

            return $sequence->prefix.str_pad((string) $number, (int) $sequence->padding, '0', STR_PAD_LEFT);
        });
    }

    private static function locked(string $key, ?int $storeId): ?Sequence
    {
        return Sequence::query()
            ->where('key', $key)
            ->when($storeId, fn ($q) => $q->where('store_id', $storeId), fn ($q) => $q->whereNull('store_id'))
            ->lockForUpdate()
            ->first();
    }
}
