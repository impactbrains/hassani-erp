<?php

namespace Database\Seeders;

use App\Domain\Catalog\ProductService;
use App\Domain\Setup\CompanyProvisioner;
use App\Models\Brand;
use App\Models\Company;
use App\Models\PriceBook;
use App\Models\ProductCategory;
use App\Models\Unit;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

/**
 * First-run data for Hassani Bachat Bazaar: the company, its Karachi store
 * with standard configuration, and the two products requested for launch.
 * No stock, costs, Urdu names or users are invented: stock is received
 * through the app and the owner account is created on the first-run screen.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BlockDefinitionSeeder::class);

        if (Company::query()->exists()) {
            return;
        }

        $company = app(CompanyProvisioner::class)->provision([
            'name' => 'Hassani Bachat Bazaar',
            'city' => 'Karachi',
            'store_name' => 'Hassani Bachat Bazaar – Karachi',
            'store_code' => 'KHI',
        ]);

        CurrentCompany::runAs($company, function () {
            $tea = ProductCategory::create(['name' => 'Tea', 'name_ur' => null, 'slug' => 'tea']);
            $tapal = Brand::create(['name' => 'Tapal', 'manufacturer' => 'Tapal Tea (Pvt) Ltd']);
            $box = Unit::query()->where('code', 'box')->firstOrFail();
            $retail = PriceBook::query()->where('code', 'RETAIL')->firstOrFail();

            app(ProductService::class)->save([
                'name' => 'Tapal Family Mixture',
                'category_id' => $tea->id,
                'brand_id' => $tapal->id,
                'type' => 'stocked',
                'is_active' => true,
                'variants' => [
                    [
                        'sku' => 'TAPAL-FM-50',
                        'name' => 'Rs 50 box',
                        'base_unit_id' => $box->id,
                        'mrp' => '50',
                        'units' => [['unit_id' => $box->id, 'factor' => '1', 'is_default_sale' => true, 'is_default_purchase' => true]],
                        'prices' => [['price_book_id' => $retail->id, 'unit_id' => $box->id, 'price' => '50']],
                    ],
                    [
                        'sku' => 'TAPAL-FM-100',
                        'name' => 'Rs 100 box',
                        'base_unit_id' => $box->id,
                        'mrp' => '100',
                        'units' => [['unit_id' => $box->id, 'factor' => '1', 'is_default_sale' => true, 'is_default_purchase' => true]],
                        'prices' => [['price_book_id' => $retail->id, 'unit_id' => $box->id, 'price' => '100']],
                    ],
                ],
            ]);
        });
    }
}
