<?php

namespace App\Domain\Catalog;

use App\Domain\Inventory\AdjustmentService;
use App\Domain\Inventory\OpeningStock;
use App\Domain\Inventory\Units;
use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\PriceBook;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Store;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds products from a CSV file (e.g. exported from the old system or typed
 * in Excel). Columns: name, name_ur, sku, barcode, category, brand, unit,
 * pack_unit, pack_size, purchase_price, in_store_price, wholesale_price,
 * market_price, pack_in_store_price, pack_wholesale_price, opening_quantity,
 * opening_cost, opening_batch_no, opening_expiry_date.
 * Files with the old names still work: retail_price = in_store_price,
 * mrp = market_price, pack_price = pack_in_store_price.
 * purchase_price, in_store_price, wholesale_price and market_price are per base
 * unit (the unit column); the pack_ prices are for one pack_unit.
 * When opening_cost is empty, the purchase_price is used as the opening cost.
 * Existing SKUs are skipped, never overwritten (their stock is skipped too, so
 * uploading a file twice cannot count stock twice). Each row is checked on its
 * own and errors are reported by row number.
 *
 * Opening stock: rows with an opening_quantity (counted in the row's base unit)
 * are collected into ONE standard "opening" stock adjustment for the store the
 * user is working in, so it lands in the stock ledger and posts to accounting
 * (debit Inventory, credit Opening balance equity) exactly like Inventory →
 * Adjustments → Opening stock. The whole file runs in one transaction: if the
 * stock cannot be recorded (e.g. the month is closed) nothing is imported.
 * Without approval permission the adjustment waits for approval, and the stock
 * does not count until it is approved.
 */
final class ProductImporter
{
    public const COLUMNS = ['name', 'name_ur', 'sku', 'barcode', 'category', 'brand', 'unit', 'pack_unit', 'pack_size',
        'purchase_price', 'in_store_price', 'wholesale_price', 'market_price', 'pack_in_store_price', 'pack_wholesale_price',
        'opening_quantity', 'opening_cost', 'opening_batch_no', 'opening_expiry_date'];

    /** Old column names that still work. */
    public const ALIASES = ['retail_price' => 'in_store_price', 'mrp' => 'market_price', 'pack_price' => 'pack_in_store_price', 'printed_price' => 'market_price'];

    public function __construct(
        private readonly ProductService $products,
        private readonly AdjustmentService $adjustments,
        private readonly OpeningStock $opening,
    ) {
    }

    /** @return array{created: int, skipped: int, errors: list<string>, stock_rows: int, stock_pending: bool, adjustment: string|null} */
    public function import(string $path, User $user, Store $store, bool $canChangePrices, bool $dryRun = false): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            throw new BusinessRuleException('The file is empty.', 'empty_file');
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        $header = array_map(fn ($h) => self::ALIASES[$h] ?? $h, $header);
        if (! in_array('name', $header, true) || ! in_array('unit', $header, true)) {
            throw new BusinessRuleException('The first row must contain column names, at least "name" and "unit".', 'bad_header');
        }
        $retail = PriceBook::query()->where('code', 'RETAIL')->value('id') ?? PriceBook::query()->where('is_default', true)->value('id');
        $wholesale = PriceBook::query()->where('code', 'WHOLESALE')->value('id');
        $result = ['created' => 0, 'skipped' => 0, 'errors' => [], 'stock_rows' => 0, 'stock_pending' => false, 'adjustment' => null];
        $stockLines = [];
        $stockChecked = false;
        $line = 1;
        DB::beginTransaction();
        try {
            while (($cells = fgetcsv($handle)) !== false) {
                $line++;
                if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue;
                }
                $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
                $row = array_map(fn ($v) => trim((string) $v), $row);
                try {
                    // Each row is its own savepoint inside the file's transaction: a row that fails leaves nothing behind.
                    $done = DB::transaction(function () use ($row, $user, $store, $retail, $wholesale, $canChangePrices, &$stockChecked) {
                        return $this->importRow($row, $user, $store, $retail, $wholesale, $canChangePrices, $stockChecked);
                    });
                    if ($done === null) {
                        $result['skipped']++;

                        continue;
                    }
                    if ($done['stock'] !== null) {
                        $stockLines[] = ['variant_id' => $done['variant_id']] + $done['stock'];
                    }
                    $result['created']++;
                } catch (ValidationException $e) {
                    $result['errors'][] = "Row {$line}: ".collect($e->errors())->flatten()->implode(' ');
                } catch (\RuntimeException $e) {
                    if ($e instanceof BusinessRuleException && $e->errorCode === 'stock_not_allowed') {
                        throw $e;
                    }
                    $result['errors'][] = "Row {$line}: ".$e->getMessage();
                }
                if (count($result['errors']) >= 200) {
                    $result['errors'][] = 'Stopped after 200 errors.';
                    break;
                }
            }

            if ($stockLines !== []) {
                $location = $this->opening->locationFor($store);
                $lines = array_map(fn ($l) => $l + ['location_id' => $location->id], $stockLines);
                try {
                    $adjustment = $this->adjustments->create($user, $store, 'opening', $lines, null, 'Opening stock from product import ('.count($lines).' products)');
                } catch (BusinessRuleException $e) {
                    // Includes a closed accounting period. Undo the products too, so stock and products never disagree.
                    throw new BusinessRuleException('Nothing was imported because the opening stock could not be recorded: '.$e->getMessage(), 'opening_stock');
                }
                $result['stock_rows'] = count($lines);
                $result['stock_pending'] = $adjustment->status !== 'posted';
                $result['adjustment'] = $dryRun ? null : $adjustment->number;
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        } finally {
            fclose($handle);
        }
        // A check-only run goes through everything above and then undoes it.
        $dryRun ? DB::rollBack() : DB::commit();

        return $result;
    }

    /**
     * Creates the product for one row. Runs inside a savepoint, so any exception undoes the row.
     *
     * @param  array<string, string>  $row
     * @return array{variant_id: int, stock: array{quantity: string, unit_cost: string, batch_no: string|null, expiry_date: string|null}|null}|null  null when the SKU already exists (row skipped)
     */
    private function importRow(array $row, User $user, Store $store, ?int $retail, ?int $wholesale, bool $canChangePrices, bool &$stockChecked): ?array
    {
        if (! empty($row['sku']) && ProductVariant::query()->where('sku', $row['sku'])->exists()) {
            return null;
        }
        $stock = $this->openingFrom($row);
        if ($stock !== null && ! $stockChecked) {
            $this->assertStoreCanTakeStock($user, $store);
            $stockChecked = true;
        }
        $unit = Unit::query()->whereRaw('lower(code) = ?', [strtolower($row['unit'] ?? '')])->first() ?? throw new BusinessRuleException("unknown unit \"{$row['unit']}\"", 'unit');
        $units = [['unit_id' => $unit->id, 'factor' => '1', 'is_default_sale' => empty($row['pack_unit'])]];
        $prices = [];
        foreach (['purchase_price', 'in_store_price', 'wholesale_price', 'market_price', 'pack_in_store_price', 'pack_wholesale_price'] as $money) {
            if (($row[$money] ?? '') !== '' && (! is_numeric($row[$money]) || (float) $row[$money] < 0)) {
                throw new BusinessRuleException("{$money} is not a number (0 or more)", 'number');
            }
        }
        if (($row['in_store_price'] ?? '') !== '') {
            $prices[] = ['price_book_id' => $retail, 'unit_id' => $unit->id, 'price' => $row['in_store_price']];
        }
        if ($wholesale && ($row['wholesale_price'] ?? '') !== '') {
            $prices[] = ['price_book_id' => $wholesale, 'unit_id' => $unit->id, 'price' => $row['wholesale_price']];
        }
        if (! empty($row['pack_unit'])) {
            $pack = Unit::query()->whereRaw('lower(code) = ?', [strtolower($row['pack_unit'])])->first() ?? throw new BusinessRuleException("unknown pack unit \"{$row['pack_unit']}\"", 'unit');
            if (! is_numeric($row['pack_size'] ?? null) || (float) $row['pack_size'] <= 0) {
                throw new BusinessRuleException('pack_size must be a number above 0', 'pack');
            }
            $units[] = ['unit_id' => $pack->id, 'factor' => $row['pack_size'], 'is_default_sale' => false];
            if (($row['pack_in_store_price'] ?? '') !== '') {
                $prices[] = ['price_book_id' => $retail, 'unit_id' => $pack->id, 'price' => $row['pack_in_store_price']];
            }
            if ($wholesale && ($row['pack_wholesale_price'] ?? '') !== '') {
                $prices[] = ['price_book_id' => $wholesale, 'unit_id' => $pack->id, 'price' => $row['pack_wholesale_price']];
            }
        } elseif (($row['pack_in_store_price'] ?? '') !== '' || ($row['pack_wholesale_price'] ?? '') !== '') {
            throw new BusinessRuleException('a pack price needs pack_unit and pack_size', 'pack');
        }
        // The purchase price, else the opening cost, becomes the product's Purchase price.
        $purchase = ($row['purchase_price'] ?? '') !== '' ? $row['purchase_price'] : ($stock['unit_cost'] ?? null);
        $data = [
            'name' => $row['name'] ?: throw new BusinessRuleException('name is empty', 'name'),
            'name_ur' => $row['name_ur'] ?? null ?: null,
            'type' => 'stocked',
            'category_id' => ! empty($row['category']) ? ProductCategory::query()->firstOrCreate(['name' => $row['category']], ['slug' => \Illuminate\Support\Str::slug($row['category']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4))])->id : null,
            'brand_id' => ! empty($row['brand']) ? Brand::query()->firstOrCreate(['name' => $row['brand']])->id : null,
            // Giving a batch number or an expiry date means the product is tracked that way.
            'track_batches' => $stock !== null && $stock['batch_no'] !== null,
            'track_expiry' => $stock !== null && $stock['expiry_date'] !== null,
            'variants' => [['sku' => $row['sku'] ?? null, 'name' => $row['name'], 'name_ur' => $row['name_ur'] ?? null ?: null, 'base_unit_id' => $unit->id, 'mrp' => $row['market_price'] ?? null,
                'purchase_price' => $purchase,
                'units' => $units, 'prices' => $canChangePrices ? $prices : [], 'barcodes' => ! empty($row['barcode']) ? [['code' => $row['barcode']]] : []]],
        ];
        $product = $this->products->save($data, null, $canChangePrices);
        $variant = $product->variants->first();
        if ($stock !== null) {
            $baseUnit = $variant->units->firstWhere('is_base', true);
            try {
                if ($baseUnit !== null) {
                    Units::assertValidQuantity($stock['quantity'], $baseUnit);
                }
            } catch (BusinessRuleException $e) {
                throw new BusinessRuleException('opening_quantity: '.$e->getMessage(), 'quantity');
            }
        }

        return ['variant_id' => $variant->id, 'stock' => $stock];
    }

    /**
     * The opening stock asked for on this row, or null when there is none.
     * Quantity is counted in the row's base unit (the "unit" column).
     *
     * @param  array<string, string>  $row
     * @return array{quantity: string, unit_cost: string, batch_no: string|null, expiry_date: string|null}|null
     */
    private function openingFrom(array $row): ?array
    {
        $quantity = $row['opening_quantity'] ?? '';
        if ($quantity === '') {
            return null;
        }
        if (! is_numeric($quantity) || (float) $quantity < 0) {
            throw new BusinessRuleException('opening_quantity must be a number, 0 or more', 'number');
        }
        if ((float) $quantity == 0.0) {
            return null;
        }
        $cost = ($row['opening_cost'] ?? '') !== '' ? $row['opening_cost'] : ($row['purchase_price'] ?? '');
        if ($cost === '') {
            throw new BusinessRuleException('opening_cost (or purchase_price) is needed when opening_quantity is given (use 0 if truly unknown)', 'cost');
        }
        if (! is_numeric($cost) || (float) $cost < 0) {
            throw new BusinessRuleException('opening_cost must be a number, 0 or more', 'cost');
        }
        $batch = ($row['opening_batch_no'] ?? '') !== '' ? $row['opening_batch_no'] : null;
        $expiry = ($row['opening_expiry_date'] ?? '') !== '' ? $this->parseDate($row['opening_expiry_date']) : null;
        if ($expiry !== null && $batch === null) {
            throw new BusinessRuleException('opening_expiry_date needs an opening_batch_no too (the expiry belongs to a batch)', 'batch');
        }

        return ['quantity' => $quantity, 'unit_cost' => $cost, 'batch_no' => $batch, 'expiry_date' => $expiry];
    }

    /** Whether this user can put stock into this store at all. Checked once, on the first row that has stock. */
    private function assertStoreCanTakeStock(User $user, Store $store): void
    {
        if (! $user->hasPermission('inventory.adjust', $store->id)) {
            throw new BusinessRuleException('This file has opening stock, but you are not allowed to add stock. Ask someone with stock adjustment permission, or remove the opening columns. Nothing was imported.', 'stock_not_allowed');
        }
        if ($this->opening->locationFor($store) === null) {
            throw new BusinessRuleException("This file has opening stock, but {$store->name} has no selling location to put it in. Add one in Settings → Stores first. Nothing was imported.", 'stock_not_allowed');
        }
    }

    /** Accepts 2027-03-31 and 31/03/2027 (as Excel often saves it); returns year-month-day. */
    private function parseDate(string $value): string
    {
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);
            $problems = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($problems === false || ($problems['warning_count'] === 0 && $problems['error_count'] === 0))) {
                return $date->format('Y-m-d');
            }
        }
        throw new BusinessRuleException('opening_expiry_date must look like 2027-03-31 (year-month-day)', 'date');
    }
}
