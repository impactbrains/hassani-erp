<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\ProductCategory;
use App\Models\Sequence;
use App\Models\Tax;
use App\Models\Unit;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // --- Document number sequences ---
        Sequence::firstOrCreate(
            ['document_type' => 'pos_order'],
            ['prefix' => 'POS-', 'next_number' => 1, 'padding' => 4]
        );

        Sequence::firstOrCreate(
            ['document_type' => 'invoice'],
            ['prefix' => 'INV-' . date('Y') . '-', 'next_number' => 1, 'padding' => 4]
        );

        Sequence::firstOrCreate(
            ['document_type' => 'sales_order'],
            ['prefix' => 'SO-', 'next_number' => 1, 'padding' => 4]
        );

        Sequence::firstOrCreate(
            ['document_type' => 'credit_note'],
            ['prefix' => 'CN-', 'next_number' => 1, 'padding' => 4]
        );

        // --- Default location (your store) ---
        Location::firstOrCreate(
            ['name' => 'Main Store'],
            ['type' => 'store', 'is_default' => true]
        );

        // --- Base units ---
        $units = [
            ['name' => 'Piece', 'short_code' => 'pc'],
            ['name' => 'Kilogram', 'short_code' => 'kg'],
            ['name' => 'Gram', 'short_code' => 'g'],
            ['name' => 'Litre', 'short_code' => 'l'],
            ['name' => 'Millilitre', 'short_code' => 'ml'],
            ['name' => 'Box', 'short_code' => 'box'],
        ];

        foreach ($units as $unit) {
            Unit::firstOrCreate(['short_code' => $unit['short_code']], $unit);
        }

        // --- Default tax rate (adjust rate to your actual local sales tax) ---
        Tax::firstOrCreate(
            ['name' => 'Standard Tax'],
            ['rate' => 0, 'type' => 'percentage', 'is_active' => true]
        );

        // --- A starter product category so the catalog isn't empty ---
        ProductCategory::firstOrCreate(['name' => 'Uncategorized']);
    }
}