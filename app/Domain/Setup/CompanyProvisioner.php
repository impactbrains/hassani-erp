<?php

namespace App\Domain\Setup;

use App\Domain\Accounting\Accounts;
use App\Domain\Accounting\ChartOfAccounts;
use App\Domain\Accounting\FiscalCalendar;
use App\Models\Account;
use App\Models\ApplicationStage;
use App\Models\Company;
use App\Models\Department;
use App\Models\LeaveType;
use App\Models\Location;
use App\Models\PaymentMethod;
use App\Models\PriceBook;
use App\Models\ReasonCode;
use App\Models\Register;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Store;
use App\Models\TaxCategory;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\WorkShift;
use App\Support\Permissions\PermissionRegistry;
use App\Support\Permissions\RoleTemplates;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a ready-to-use company: first store with its stock locations and
 * a POS counter, role templates, chart of accounts, payment methods, units, tax
 * category, price books, reason codes, leave types and shifts.
 *
 * Nothing here is business data (no products, prices or balances are
 * invented); it is the configuration every shop needs before its first sale.
 */
final class CompanyProvisioner
{
    /** @param array{name: string, city?: string|null, phone?: string|null, address?: string|null, store_name?: string|null, store_code?: string|null, timezone?: string|null} $details */
    public function provision(array $details): Company
    {
        return DB::transaction(function () use ($details) {
            $company = Company::create([
                'name' => $details['name'],
                'slug' => $this->uniqueSlug($details['name']),
                'city' => $details['city'] ?? null,
                'phone' => $details['phone'] ?? null,
                'address' => $details['address'] ?? null,
                'timezone' => $details['timezone'] ?? 'Asia/Karachi',
            ]);

            return CurrentCompany::runAs($company, function () use ($company, $details) {
                $this->createRoles();
                $this->createChartOfAccounts();
                $this->createUnits();
                $this->createTaxAndPriceBooks();
                $this->createPaymentMethods();
                $this->createReasonCodes();
                $this->createHrDefaults();
                $this->createStore($details['store_name'] ?? $company->name, $details['store_code'] ?? 'MAIN', $company->city, $company->address);
                (new FiscalCalendar)->createYearContaining(now()->setTimezone($company->timezone)->toDateString());

                return $company;
            });
        });
    }

    public function createStore(string $name, string $code, ?string $city = null, ?string $address = null): Store
    {
        $store = Store::create(['code' => strtoupper($code), 'name' => $name, 'city' => $city, 'address' => $address]);
        $locations = [
            ['code' => 'FLOOR', 'name' => 'Shop floor', 'name_ur' => 'دکان', 'type' => 'sales_floor', 'is_sellable' => true, 'is_default_selling' => true],
            ['code' => 'GODOWN', 'name' => 'Godown', 'name_ur' => 'گودام', 'type' => 'godown', 'is_sellable' => true, 'is_default_receiving' => true],
            ['code' => 'QC', 'name' => 'Quarantine / QC hold', 'type' => 'quarantine', 'is_sellable' => false],
            ['code' => 'DAMAGED', 'name' => 'Damaged', 'type' => 'damaged', 'is_sellable' => false],
            ['code' => 'RETURNS', 'name' => 'Returns inspection', 'type' => 'returns', 'is_sellable' => false],
            ['code' => 'TRANSIT', 'name' => 'In transit', 'type' => 'transit', 'is_sellable' => false],
        ];
        foreach ($locations as $location) {
            Location::create($location + ['store_id' => $store->id]);
        }
        Register::create([
            'store_id' => $store->id,
            'code' => 'C1',
            'name' => 'POS 1',
            'location_id' => $store->defaultSellingLocation()->id,
        ]);

        return $store;
    }

    private function createRoles(): void
    {
        foreach (RoleTemplates::all() as $key => $template) {
            $role = Role::create([
                'key' => $key,
                'name' => $template['name'],
                'description' => $template['description'],
                'is_owner' => $template['owner'] ?? false,
                'is_system' => true,
            ]);
            $permissions = ($template['owner'] ?? false) ? PermissionRegistry::keys() : PermissionRegistry::expand($template['permissions']);
            RolePermission::insert(array_map(fn ($p) => ['role_id' => $role->id, 'permission' => $p], $permissions));
        }
    }

    private function createChartOfAccounts(): void
    {
        $ids = [];
        foreach (ChartOfAccounts::template() as $row) {
            $account = Account::create([
                'code' => $row['code'],
                'name' => $row['name'],
                'name_ur' => $row['name_ur'] ?? null,
                'type' => $row['type'],
                'subtype' => $row['subtype'] ?? null,
                'system_key' => $row['key'] ?? null,
                'parent_id' => isset($row['parent']) ? $ids[$row['parent']] : null,
                'is_postable' => $row['postable'] ?? true,
            ]);
            $ids[$row['code']] = $account->id;
        }
        Accounts::flush();
    }

    private function createUnits(): void
    {
        $units = [
            ['pcs', 'Piece', 'عدد', 'count', false, 0], ['box', 'Box', 'ڈبہ', 'count', false, 0],
            ['pack', 'Packet', 'پیکٹ', 'count', false, 0], ['sachet', 'Sachet', 'ساشے', 'count', false, 0],
            ['ladi', 'Ladi (strip)', 'لڑی', 'count', false, 0], ['ctn', 'Carton', 'کارٹن', 'count', false, 0],
            ['crate', 'Crate', 'کریٹ', 'count', false, 0], ['bag', 'Bag', 'بوری', 'count', false, 0],
            ['dozen', 'Dozen', 'درجن', 'count', false, 0], ['bottle', 'Bottle', 'بوتل', 'count', false, 0],
            ['kg', 'Kilogram', 'کلو', 'weight', true, 3], ['g', 'Gram', 'گرام', 'weight', false, 0],
            ['l', 'Litre', 'لیٹر', 'volume', true, 3], ['ml', 'Millilitre', 'ملی لیٹر', 'volume', false, 0],
        ];
        foreach ($units as [$code, $name, $nameUr, $kind, $fraction, $decimals]) {
            Unit::create(['code' => $code, 'name' => $name, 'name_ur' => $nameUr, 'kind' => $kind, 'allows_fraction' => $fraction, 'decimals' => $decimals]);
        }
    }

    private function createTaxAndPriceBooks(): void
    {
        $category = TaxCategory::create(['code' => 'STANDARD', 'name' => 'Standard', 'is_default' => true]);
        TaxRate::create([
            'tax_category_id' => $category->id,
            'rate' => '0',
            'is_inclusive' => true,
            'effective_from' => '2000-01-01',
            'note' => 'Placeholder 0%. Set the rate that applies to your business; no rate is assumed.',
        ]);
        TaxCategory::create(['code' => 'EXEMPT', 'name' => 'Exempt']);

        PriceBook::create(['code' => 'RETAIL', 'name' => 'In-store', 'name_ur' => 'دکان کی قیمت', 'kind' => 'retail', 'is_default' => true]);
        PriceBook::create(['code' => 'WHOLESALE', 'name' => 'Wholesale', 'name_ur' => 'ہول سیل', 'kind' => 'wholesale']);
    }

    private function createPaymentMethods(): void
    {
        $methods = [
            ['cash', 'Cash', 'نقد', 'cash', 'cash_on_hand', false, false, true],
            ['card', 'Debit / credit card', 'کارڈ', 'card', 'card_clearing', true, true, false],
            ['easypaisa', 'Easypaisa', 'ایزی پیسہ', 'wallet', 'wallet_easypaisa', true, true, true],
            ['jazzcash', 'JazzCash', 'جاز کیش', 'wallet', 'wallet_jazzcash', true, true, true],
            ['nayapay', 'NayaPay', 'نیا پے', 'wallet', 'wallet_nayapay', true, true, false],
            ['sadapay', 'SadaPay', 'سدا پے', 'wallet', 'wallet_sadapay', true, true, false],
            ['bank_transfer', 'Bank transfer', 'بینک ٹرانسفر', 'bank', 'bank', true, true, true],
        ];
        foreach ($methods as $order => [$code, $name, $nameUr, $type, $account, $reference, $verify, $online]) {
            PaymentMethod::create([
                'code' => $code, 'name' => $name, 'name_ur' => $nameUr, 'type' => $type, 'account_id' => Accounts::id($account),
                'requires_reference' => $reference, 'requires_verification' => $verify, 'available_online' => $online || $code === 'cash',
                'sort_order' => $order,
            ]);
        }
    }

    private function createReasonCodes(): void
    {
        $codes = [
            'adjustment' => [['found', 'Stock found'], ['data_entry', 'Data entry correction'], ['opening', 'Opening stock']],
            'write_off' => [['damaged', 'Damaged', 'خراب'], ['expired', 'Expired', 'میعاد ختم'], ['pest', 'Pest affected'], ['leaked', 'Leaked or broken'], ['missing', 'Missing (under investigation)'], ['sample', 'Free sample / tasting']],
            'return' => [['changed_mind', 'Customer changed mind'], ['damaged_item', 'Damaged item'], ['expired_item', 'Expired item'], ['wrong_item', 'Wrong item given'], ['quality', 'Quality complaint']],
            'discount' => [['regular_customer', 'Regular customer'], ['bulk', 'Bulk purchase'], ['damaged_packaging', 'Damaged packaging'], ['near_expiry', 'Near expiry'], ['manager_special', 'Manager special']],
            'payout' => [['staff_meals', 'Staff tea / meals', 'staff_meals'], ['transport', 'Transport / fuel', 'transport'], ['utilities', 'Utility bill', 'utilities'], ['repairs', 'Repairs', 'repairs'], ['packaging', 'Shopping bags / packaging', 'packaging'], ['misc', 'Other expense', 'misc_expense']],
            'no_sale' => [['change', 'Change for a customer'], ['check', 'Drawer check']],
            'transfer_discrepancy' => [['short_shipped', 'Short shipped'], ['damaged_in_transit', 'Damaged in transit']],
            'order_cancel' => [['customer_cancelled', 'Customer cancelled'], ['out_of_stock', 'Out of stock'], ['unreachable', 'Customer unreachable']],
            'supplier_return' => [['damaged', 'Damaged on arrival'], ['expired', 'Expired / near expiry'], ['wrong_item', 'Wrong item supplied']],
        ];
        foreach ($codes as $type => $entries) {
            foreach ($entries as $entry) {
                ReasonCode::create([
                    'type' => $type,
                    'code' => $entry[0],
                    'label' => $entry[1],
                    'label_ur' => ($type !== 'payout' && isset($entry[2])) ? $entry[2] : null,
                    'account_id' => $type === 'payout' ? Accounts::id($entry[2]) : null,
                    'requires_note' => in_array($entry[0], ['missing', 'misc', 'data_entry'], true),
                ]);
            }
        }
    }

    private function createHrDefaults(): void
    {
        foreach (['Sales floor', 'Warehouse', 'Delivery', 'Accounts', 'Administration'] as $name) {
            Department::create(['name' => $name]);
        }
        // Entitlements are placeholders to edit: statutory leave differs by
        // province and establishment type and must be confirmed locally.
        foreach ([['CASUAL', 'Casual leave', 'اتفاقی چھٹی', true, 10], ['SICK', 'Sick leave', 'بیماری کی چھٹی', true, 8], ['ANNUAL', 'Annual leave', 'سالانہ چھٹی', true, 14], ['UNPAID', 'Unpaid leave', 'بغیر تنخواہ چھٹی', false, 0]] as [$code, $name, $nameUr, $paid, $days]) {
            LeaveType::create(['code' => $code, 'name' => $name, 'name_ur' => $nameUr, 'is_paid' => $paid, 'default_days_per_year' => $days]);
        }
        WorkShift::create(['name' => 'Morning', 'start_time' => '09:00', 'end_time' => '17:00', 'break_minutes' => 30, 'color' => 'amber']);
        WorkShift::create(['name' => 'Evening', 'start_time' => '15:00', 'end_time' => '23:00', 'break_minutes' => 30, 'color' => 'indigo']);
        foreach ([['Applied', false, true], ['Reviewing', false, false], ['Interview', false, false], ['Hired', true, false], ['Rejected', true, false]] as $order => [$name, $terminal, $default]) {
            ApplicationStage::create(['name' => $name, 'sort_order' => $order, 'is_terminal' => $terminal, 'is_default' => $default]);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $i = 2;
        while (Company::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
