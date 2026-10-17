<?php

namespace App\Domain\Catalog;

use App\Models\PendingProduct;
use App\Models\PendingProductSale;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SalesInvoice;
use App\Models\Unit;
use App\Models\VariantUnit;
use App\Support\Audit;
use App\Support\Decimal;
use Illuminate\Support\Facades\DB;

/**
 * Selling something at the POS that is not in the product list yet.
 *
 * The sale is recorded on one system product ("Unlisted item", not stock
 * tracked) with the name the cashier typed, so the receipt, the money and
 * the accounts are right. Each such sale is also logged as a "product to
 * be made" so the team can add it to the catalogue, with how often it
 * sells: frequently (5+ sales in 30 days), occasionally (2–4) or rarely.
 */
final class UnlistedItems
{
    public const SKU = 'UNLISTED';

    /** The system product used for unlisted items (created on first use). */
    public function variant(): ProductVariant
    {
        $variant = ProductVariant::query()->where('sku', self::SKU)->with(['product', 'units.unit'])->first();
        if ($variant) {
            return $variant;
        }

        return DB::transaction(function () {
            $pcs = Unit::query()->where('code', 'pcs')->value('id') ?? Unit::query()->value('id');
            $product = Product::create(['name' => 'Unlisted item', 'name_ur' => 'غیر درج شدہ شے', 'type' => 'service', 'is_open_item' => true, 'is_online' => false, 'is_active' => true]);
            $variant = ProductVariant::create(['product_id' => $product->id, 'sku' => self::SKU, 'name' => 'Unlisted item', 'name_ur' => 'غیر درج شدہ شے', 'base_unit_id' => $pcs, 'is_default' => true, 'is_active' => true]);
            VariantUnit::create(['variant_id' => $variant->id, 'unit_id' => $pcs, 'factor' => '1', 'is_base' => true, 'can_sell' => true, 'can_purchase' => false, 'is_default_sale' => true]);

            return $variant->load(['product', 'units.unit']);
        });
    }

    /** Logs every unlisted line of a posted invoice. */
    public function recordSales(SalesInvoice $invoice, array $barcodesByDescription = []): void
    {
        foreach ($invoice->lines()->whereHas('variant', fn ($q) => $q->where('sku', self::SKU))->get() as $line) {
            $name = ltrim(trim($line->description), '*'); // the receipt marks unlisted items with *
            $key = self::key($name);
            $barcode = $barcodesByDescription[$line->description] ?? null;
            $pending = PendingProduct::query()->where('status', 'pending')
                ->where(fn ($q) => $q->where('name_key', $key)->when($barcode, fn ($w) => $w->orWhere('barcode', $barcode)))
                ->lockForUpdate()->first()
                ?? PendingProduct::create(['name' => $name, 'name_key' => $key, 'barcode' => $barcode, 'status' => 'pending', 'first_sold_at' => $invoice->posted_at]);
            $pending->fill([
                'times_sold' => $pending->times_sold + 1,
                'quantity_sold' => Decimal::add((string) $pending->quantity_sold, $line->quantity),
                'total_sales' => Decimal::add((string) $pending->total_sales, $line->line_total),
                'last_price' => $line->unit_price,
                'last_sold_at' => $invoice->posted_at,
                'last_store_id' => $invoice->store_id,
                'barcode' => $pending->barcode ?? $barcode,
            ])->save();
            PendingProductSale::create(['pending_product_id' => $pending->id, 'sales_invoice_line_id' => $line->id, 'store_id' => $invoice->store_id, 'user_id' => $invoice->posted_by, 'quantity' => $line->quantity, 'unit_price' => $line->unit_price, 'sold_at' => $invoice->posted_at]);
        }
    }

    /** frequently / occasionally / rarely, from sales in the last 30 days. */
    public static function frequency(int $salesLast30Days): string
    {
        return $salesLast30Days >= 5 ? 'frequently' : ($salesLast30Days >= 2 ? 'occasionally' : 'rarely');
    }

    public function resolve(PendingProduct $pending, string $status, int $userId, ?int $productId = null): void
    {
        $pending->update(['status' => $status, 'product_id' => $productId, 'resolved_by' => $userId, 'resolved_at' => now()]);
        Audit::record('catalog.unlisted_'.$status, "Unlisted item “{$pending->name}” marked {$status}", $pending);
    }

    public static function key(string $name): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($name)));
    }
}

