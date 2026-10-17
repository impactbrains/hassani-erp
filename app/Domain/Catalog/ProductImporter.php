<?php

namespace App\Domain\Catalog;

use App\Exceptions\BusinessRuleException;
use App\Models\Brand;
use App\Models\PriceBook;
use App\Models\ProductCategory;
use App\Models\ProductVariant;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;

/**
 * Adds products from a CSV file (e.g. exported from the old system or typed
 * in Excel). Columns: name, name_ur, sku, barcode, category, brand, unit,
 * pack_unit, pack_size, retail_price, wholesale_price, pack_price, mrp.
 * Existing SKUs are skipped, never overwritten. Each row is checked on its
 * own and errors are reported by row number.
 */
final class ProductImporter
{
    public const COLUMNS = ['name', 'name_ur', 'sku', 'barcode', 'category', 'brand', 'unit', 'pack_unit', 'pack_size', 'retail_price', 'wholesale_price', 'pack_price', 'mrp'];

    public function __construct(private readonly ProductService $products)
    {
    }

    /** @return array{created: int, skipped: int, errors: list<string>} */
    public function import(string $path, bool $canChangePrices, bool $dryRun = false): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        if (! $header) {
            throw new BusinessRuleException('The file is empty.', 'empty_file');
        }
        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h))), $header);
        if (! in_array('name', $header, true) || ! in_array('unit', $header, true)) {
            throw new BusinessRuleException('The first row must contain column names, at least "name" and "unit".', 'bad_header');
        }
        $retail = PriceBook::query()->where('code', 'RETAIL')->value('id') ?? PriceBook::query()->where('is_default', true)->value('id');
        $wholesale = PriceBook::query()->where('code', 'WHOLESALE')->value('id');
        $result = ['created' => 0, 'skipped' => 0, 'errors' => []];
        $line = 1;
        while (($cells = fgetcsv($handle)) !== false) {
            $line++;
            if (count(array_filter($cells, fn ($c) => trim((string) $c) !== '')) === 0) {
                continue;
            }
            $row = array_combine($header, array_pad(array_slice($cells, 0, count($header)), count($header), ''));
            $row = array_map(fn ($v) => trim((string) $v), $row);
            try {
                if (! empty($row['sku']) && ProductVariant::query()->where('sku', $row['sku'])->exists()) {
                    $result['skipped']++;

                    continue;
                }
                $unit = Unit::query()->whereRaw('lower(code) = ?', [strtolower($row['unit'] ?? '')])->first() ?? throw new BusinessRuleException("unknown unit \"{$row['unit']}\"", 'unit');
                $units = [['unit_id' => $unit->id, 'factor' => '1', 'is_default_sale' => empty($row['pack_unit'])]];
                $prices = [];
                if (($row['retail_price'] ?? '') !== '') {
                    $prices[] = ['price_book_id' => $retail, 'unit_id' => $unit->id, 'price' => $row['retail_price']];
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
                    if (($row['pack_price'] ?? '') !== '') {
                        $prices[] = ['price_book_id' => $retail, 'unit_id' => $pack->id, 'price' => $row['pack_price']];
                    }
                }
                foreach (['retail_price', 'wholesale_price', 'pack_price', 'mrp'] as $money) {
                    if (($row[$money] ?? '') !== '' && ! is_numeric($row[$money])) {
                        throw new BusinessRuleException("{$money} is not a number", 'number');
                    }
                }
                $data = [
                    'name' => $row['name'] ?: throw new BusinessRuleException('name is empty', 'name'),
                    'name_ur' => $row['name_ur'] ?? null ?: null,
                    'type' => 'stocked',
                    'category_id' => ! empty($row['category']) ? ProductCategory::query()->firstOrCreate(['name' => $row['category']], ['slug' => \Illuminate\Support\Str::slug($row['category']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4))])->id : null,
                    'brand_id' => ! empty($row['brand']) ? Brand::query()->firstOrCreate(['name' => $row['brand']])->id : null,
                    'variants' => [['sku' => $row['sku'] ?? null, 'name' => $row['name'], 'name_ur' => $row['name_ur'] ?? null ?: null, 'base_unit_id' => $unit->id, 'mrp' => $row['mrp'] ?? null,
                        'units' => $units, 'prices' => $canChangePrices ? $prices : [], 'barcodes' => ! empty($row['barcode']) ? [['code' => $row['barcode']]] : []]],
                ];
                DB::transaction(function () use ($data, $canChangePrices, $dryRun) {
                    $this->products->save($data, null, $canChangePrices);
                    if ($dryRun) {
                        throw new \RuntimeException('dry-run');
                    }
                });
                $result['created']++;
            } catch (\RuntimeException $e) {
                if ($e->getMessage() === 'dry-run') {
                    $result['created']++;

                    continue;
                }
                $result['errors'][] = "Row {$line}: ".$e->getMessage();
            }
            if (count($result['errors']) >= 200) {
                $result['errors'][] = 'Stopped after 200 errors.';
                break;
            }
        }
        fclose($handle);

        return $result;
    }
}
