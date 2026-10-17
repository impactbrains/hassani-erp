<?php

namespace App\Domain\Catalog;

use App\Models\Barcode;
use App\Models\ProductVariant;
use App\Models\VariantUnit;

/**
 * Resolves a scanned or typed code to a SKU and pack unit.
 *
 * Accepts EAN/UPC codes, internal codes, SKUs, and GS1-128 / DataMatrix
 * strings that carry a GTIN in application identifier (01). A barcode
 * registered on a pack (e.g. a carton) resolves to that pack unit.
 */
final class BarcodeLookup
{
    /** @return array{variant: ProductVariant, unit: VariantUnit}|null */
    public function find(string $code): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        foreach ($this->candidates($code) as $candidate) {
            $barcode = Barcode::query()->with(['variant.product', 'variant.units.unit', 'variantUnit.unit'])->where('code', $candidate)->first();
            if ($barcode && $barcode->variant?->is_active) {
                $unit = $barcode->variantUnit ?? $this->defaultSaleUnit($barcode->variant);

                return $unit ? ['variant' => $barcode->variant, 'unit' => $unit] : null;
            }
        }

        $variant = ProductVariant::query()->with(['product', 'units.unit'])->where('is_active', true)->whereRaw('lower(sku) = ?', [mb_strtolower($code)])->first();
        if ($variant) {
            $unit = $this->defaultSaleUnit($variant);

            return $unit ? ['variant' => $variant, 'unit' => $unit] : null;
        }

        return null;
    }

    public function defaultSaleUnit(ProductVariant $variant): ?VariantUnit
    {
        $units = $variant->relationLoaded('units') ? $variant->units : $variant->units()->with('unit')->get();

        return $units->firstWhere('is_default_sale', true)
            ?? $units->firstWhere('is_base', true)
            ?? $units->first();
    }

    /** @return list<string> */
    public function candidates(string $code): array
    {
        $candidates = [$code];
        $digits = preg_replace('/\D/', '', $code) ?? '';
        // GS1 element strings: "(01)08901234567890(17)..." or "0108901234567890..."
        if (preg_match('/^\(?01\)?(\d{14})/', $code, $match)) {
            $gtin14 = $match[1];
            $candidates[] = $gtin14;
            $candidates[] = ltrim(substr($gtin14, 1), '') ;
            $candidates[] = ltrim($gtin14, '0');
        }
        if (strlen($digits) === 12) {
            $candidates[] = '0'.$digits; // UPC-A as EAN-13
        }
        if (strlen($digits) === 13 && str_starts_with($digits, '0')) {
            $candidates[] = substr($digits, 1); // EAN-13 with leading zero as UPC-A
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /** Validates the check digit of an EAN-8/EAN-13/UPC-A/GTIN-14 code. */
    public static function hasValidCheckDigit(string $code): bool
    {
        if (! preg_match('/^\d{8}$|^\d{12,14}$/', $code)) {
            return false;
        }
        $digits = array_map('intval', str_split($code));
        $check = array_pop($digits);
        $sum = 0;
        foreach (array_reverse($digits) as $i => $digit) {
            $sum += $digit * ($i % 2 === 0 ? 3 : 1);
        }

        return (10 - ($sum % 10)) % 10 === $check;
    }
}
