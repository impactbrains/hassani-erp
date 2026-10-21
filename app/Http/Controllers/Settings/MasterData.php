<?php

namespace App\Http\Controllers\Settings;

use App\Models;

/**
 * Lists people maintain themselves (categories, units, payment methods ...),
 * described once and edited on one generic screen. Each entry names its
 * model, the permission that edits it, its fields and validation.
 * Effective-dated lists (tax rates) are append-only: a new row replaces the
 * old one from its start date.
 */
final class MasterData
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $text = fn (string $label, bool $required = false, int $max = 120) => ['label' => $label, 'type' => 'text', 'rules' => [$required ? 'required' : 'nullable', 'string', "max:{$max}"]];
        $urdu = fn (string $label = 'Urdu name') => ['label' => $label, 'type' => 'text', 'dir' => 'rtl', 'rules' => ['nullable', 'string', 'max:120']];
        $bool = fn (string $label, bool $default = true) => ['label' => $label, 'type' => 'bool', 'default' => $default, 'rules' => ['boolean']];
        $int = fn (string $label, int $min = 0, int $max = 100000) => ['label' => $label, 'type' => 'number', 'rules' => ['nullable', 'integer', "min:{$min}", "max:{$max}"]];
        $select = fn (string $label, array $options, bool $required = true) => ['label' => $label, 'type' => 'select', 'options' => $options, 'rules' => [$required ? 'required' : 'nullable', 'in:'.implode(',', array_keys($options))]];
        $ref = fn (string $label, string $source, bool $required = false) => ['label' => $label, 'type' => 'ref', 'source' => $source, 'rules' => [$required ? 'required' : 'nullable', 'integer']];

        return [
            'categories' => ['label' => 'Product categories', 'model' => Models\ProductCategory::class, 'permission' => 'settings.catalog.manage', 'section' => 'catalog', 'order' => 'name',
                'fields' => ['name' => $text('Name', true), 'name_ur' => $urdu(), 'parent_id' => $ref('Inside category', 'categories'), 'sort_order' => $int('Sort order'), 'is_active' => $bool('Active')],
                'defaults' => fn (array $d) => ['slug' => \Illuminate\Support\Str::slug($d['name']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4))]],
            'brands' => ['label' => 'Brands', 'model' => Models\Brand::class, 'permission' => 'settings.catalog.manage', 'section' => 'catalog', 'order' => 'name',
                'fields' => ['name' => $text('Name', true), 'name_ur' => $urdu(), 'manufacturer' => $text('Manufacturer / company'), 'is_active' => $bool('Active')]],
            'units' => ['label' => 'Units and pack sizes', 'model' => Models\Unit::class, 'permission' => 'settings.catalog.manage', 'section' => 'catalog', 'order' => 'name',
                'fields' => ['code' => $text('Short code', true, 12), 'name' => $text('Name', true), 'name_ur' => $urdu(), 'kind' => $select('Measures', ['count' => 'Count', 'weight' => 'Weight', 'volume' => 'Volume', 'length' => 'Length']), 'allows_fraction' => $bool('Can sell fractions (e.g. 0.5 kg)', false), 'decimals' => $int('Decimals', 0, 3), 'is_active' => $bool('Active')]],
            'price-books' => ['label' => 'Price lists', 'model' => Models\PriceBook::class, 'permission' => 'catalog.prices.manage', 'section' => 'catalog', 'order' => 'name',
                'fields' => ['code' => $text('Code', true, 20), 'name' => $text('Name', true), 'name_ur' => $urdu(), 'kind' => $select('Kind', ['retail' => 'In-store', 'wholesale' => 'Wholesale', 'special' => 'Special customers', 'online' => 'Online']), 'is_active' => $bool('Active')]],
            'tax-categories' => ['label' => 'Tax categories', 'model' => Models\TaxCategory::class, 'permission' => 'settings.catalog.manage', 'section' => 'catalog', 'order' => 'name',
                'fields' => ['code' => $text('Code', true, 20), 'name' => $text('Name', true)]],
            'tax-rates' => ['label' => 'Tax rates (effective-dated)', 'model' => Models\TaxRate::class, 'permission' => 'settings.catalog.manage', 'section' => 'catalog', 'order' => 'effective_from', 'append_only' => true,
                'fields' => ['tax_category_id' => $ref('Tax category', 'tax-categories', true), 'rate' => ['label' => 'Rate %', 'type' => 'decimal', 'rules' => ['required', 'numeric', 'min:0', 'max:100']], 'is_inclusive' => $bool('Prices include this tax'), 'effective_from' => ['label' => 'Starts on', 'type' => 'date', 'rules' => ['required', 'date']], 'note' => $text('Note (e.g. Finance Act reference)', false, 200)]],
            'payment-methods' => ['label' => 'Payment methods', 'model' => Models\PaymentMethod::class, 'permission' => 'settings.company.manage', 'section' => 'sales', 'order' => 'sort_order',
                'fields' => ['code' => $text('Code', true, 30), 'name' => $text('Name', true), 'name_ur' => $urdu(), 'type' => $select('Type', ['cash' => 'Cash', 'card' => 'Card', 'wallet' => 'Mobile wallet', 'bank' => 'Bank transfer', 'cheque' => 'Cheque', 'other' => 'Other']),
                    'account_id' => $ref('Money goes to account', 'accounts', true), 'requires_reference' => $bool('Ask for a reference number', false), 'requires_verification' => $bool('Needs verification against statement', false), 'available_in_pos' => $bool('Available at the POS'), 'available_online' => $bool('Available online', false), 'sort_order' => $int('Sort order'), 'is_active' => $bool('Active')]],
            'reason-codes' => ['label' => 'Reasons (discounts, returns, write-offs, payouts ...)', 'model' => Models\ReasonCode::class, 'permission' => 'settings.company.manage', 'section' => 'sales', 'order' => 'type',
                'fields' => ['type' => $select('Used for', ['discount' => 'Discounts', 'return' => 'Returns', 'write_off' => 'Write-offs', 'adjustment' => 'Stock adjustments', 'payout' => 'POS payouts', 'no_sale' => 'No-sale drawer opening', 'transfer_discrepancy' => 'Transfer differences', 'order_cancel' => 'Order cancellations', 'supplier_return' => 'Supplier returns', 'price_override' => 'Price overrides', 'cash_in' => 'Cash pay-ins']),
                    'code' => $text('Code', true, 40), 'label' => $text('Label', true), 'label_ur' => $urdu('Urdu label'), 'requires_note' => $bool('Note required', false), 'account_id' => $ref('Expense account (payouts)', 'accounts'), 'is_active' => $bool('Active')]],
            'locations' => ['label' => 'Stock locations', 'model' => Models\Location::class, 'permission' => 'inventory.locations.manage', 'section' => 'inventory', 'order' => 'store_id', 'store_scoped' => true,
                'fields' => ['store_id' => $ref('Store', 'stores', true), 'code' => $text('Code', true, 20), 'name' => $text('Name', true), 'name_ur' => $urdu(), 'type' => $select('Type', ['sales_floor' => 'Shop floor', 'godown' => 'Godown / warehouse', 'shelf' => 'Shelf / bin', 'cold' => 'Cold storage', 'quarantine' => 'Quarantine', 'damaged' => 'Damaged', 'returns' => 'Returns inspection']),
                    'is_sellable' => $bool('Stock here can be sold'), 'is_default_receiving' => $bool('Default for receiving', false), 'is_default_selling' => $bool('Default for selling', false), 'is_active' => $bool('Active')]],
            'registers' => ['label' => 'POS counters', 'model' => Models\Register::class, 'permission' => 'settings.stores.manage', 'section' => 'sales', 'order' => 'store_id', 'store_scoped' => true,
                'fields' => ['store_id' => $ref('Store', 'stores', true), 'code' => $text('Code', true, 20), 'name' => $text('Name', true), 'location_id' => $ref('Sells from location', 'locations'), 'is_active' => $bool('Active')]],
            'departments' => ['label' => 'Departments', 'model' => Models\Department::class, 'permission' => 'hr.policies.manage', 'section' => 'hr', 'order' => 'name',
                'fields' => ['name' => $text('Name', true), 'name_ur' => $urdu()]],
            'positions' => ['label' => 'Job positions', 'model' => Models\JobPosition::class, 'permission' => 'hr.policies.manage', 'section' => 'hr', 'order' => 'name',
                'fields' => ['name' => $text('Name', true), 'name_ur' => $urdu(), 'department_id' => $ref('Department', 'departments')]],
            'leave-types' => ['label' => 'Leave types', 'model' => Models\LeaveType::class, 'permission' => 'hr.policies.manage', 'section' => 'hr', 'order' => 'name',
                'fields' => ['code' => $text('Code', true, 20), 'name' => $text('Name', true), 'name_ur' => $urdu(), 'is_paid' => $bool('Paid'), 'default_days_per_year' => ['label' => 'Days per year', 'type' => 'decimal', 'rules' => ['required', 'numeric', 'min:0', 'max:365']], 'carry_forward_max' => ['label' => 'Max carried to next year', 'type' => 'decimal', 'rules' => ['nullable', 'numeric', 'min:0']], 'requires_document' => $bool('Document required', false), 'is_active' => $bool('Active')]],
            'work-shifts' => ['label' => 'Work shifts', 'model' => Models\WorkShift::class, 'permission' => 'hr.policies.manage', 'section' => 'hr', 'order' => 'start_time',
                'fields' => ['name' => $text('Name', true), 'store_id' => $ref('Store (blank = all)', 'stores'), 'start_time' => ['label' => 'Starts', 'type' => 'time', 'rules' => ['required', 'date_format:H:i']], 'end_time' => ['label' => 'Ends', 'type' => 'time', 'rules' => ['required', 'date_format:H:i']], 'break_minutes' => $int('Break (minutes)', 0, 240), 'grace_minutes' => $int('Grace for late (minutes)', 0, 120), 'color' => $select('Colour', ['amber' => 'Amber', 'indigo' => 'Indigo', 'emerald' => 'Green', 'rose' => 'Rose', 'sky' => 'Sky', 'slate' => 'Grey']), 'is_active' => $bool('Active')]],
            'tags' => ['label' => 'Customer tags', 'model' => Models\Tag::class, 'permission' => 'crm.customers.manage', 'section' => 'crm', 'order' => 'name',
                'fields' => ['name' => $text('Name', true, 60), 'color' => $select('Colour', ['amber' => 'Amber', 'indigo' => 'Indigo', 'emerald' => 'Green', 'rose' => 'Rose', 'sky' => 'Sky', 'slate' => 'Grey'])]],
        ];
    }

    /** @return array<string, mixed> */
    public static function find(string $key): array
    {
        return self::all()[$key] ?? abort(404);
    }
}
