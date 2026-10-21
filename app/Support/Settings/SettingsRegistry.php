<?php

namespace App\Support\Settings;

/**
 * Every configurable setting, with its type, default, validation, allowed
 * scopes, the permission that owns it and a plain explanation of its effect.
 *
 * Precedence when reading: user > store > company > default, limited to the
 * scopes a setting allows. Values that fail validation are rejected on save;
 * unknown keys can never be stored. Rules with a legal or financial effect
 * that must change on a date (tax rates, prices, salaries, holidays) live in
 * their own effective-dated tables, not here.
 */
final class SettingsRegistry
{
    public const SCOPE_COMPANY = 'company';

    public const SCOPE_STORE = 'store';

    public const SCOPE_USER = 'user';

    /**
     * @return array<string, array{group: string, label: string, description: string, type: string, default: mixed,
     *     scopes: list<string>, rules: list<mixed>, permission: string, options?: array<string, string>, high_impact?: bool, locked_after?: string}>
     */
    public static function definitions(): array
    {
        $company = [self::SCOPE_COMPANY];
        $companyStore = [self::SCOPE_COMPANY, self::SCOPE_STORE];
        $userPreference = [self::SCOPE_COMPANY, self::SCOPE_USER];

        return [
            // Business identity & localisation
            'business.receipt_name' => ['group' => 'business', 'label' => 'Name printed on receipts', 'description' => 'Shown at the top of every receipt and invoice. Leave empty to use the business name.', 'type' => 'string', 'default' => null, 'scopes' => $companyStore, 'rules' => ['nullable', 'string', 'max:80'], 'permission' => 'settings.company.manage'],
            'business.receipt_name_ur' => ['group' => 'business', 'label' => 'Urdu name printed on receipts', 'description' => 'Printed under the English name when receipts are in Urdu or both languages.', 'type' => 'string', 'default' => null, 'scopes' => $companyStore, 'rules' => ['nullable', 'string', 'max:80'], 'permission' => 'settings.company.manage'],
            'localization.default_locale' => ['group' => 'localization', 'label' => 'Language', 'description' => 'Language of menus, dashboards and reports. Urdu switches the layout to right-to-left.', 'type' => 'enum', 'default' => 'en', 'options' => ['en' => 'English', 'ur' => 'اردو (Urdu)'], 'scopes' => $userPreference, 'rules' => ['required', 'in:en,ur'], 'permission' => 'settings.company.manage'],
            'localization.date_format' => ['group' => 'localization', 'label' => 'Date format', 'description' => 'How dates are shown on screens and printouts.', 'type' => 'enum', 'default' => 'd/m/Y', 'options' => ['d/m/Y' => '31/12/2026', 'd M Y' => '31 Dec 2026', 'Y-m-d' => '2026-12-31'], 'scopes' => $userPreference, 'rules' => ['required', 'in:d/m/Y,d M Y,Y-m-d'], 'permission' => 'settings.company.manage'],
            'reporting.week_start' => ['group' => 'reporting', 'label' => 'Week starts on', 'description' => 'Used by weekly reports, forecasts and "this week" filters.', 'type' => 'enum', 'default' => 'monday', 'options' => ['monday' => 'Monday', 'saturday' => 'Saturday', 'sunday' => 'Sunday'], 'scopes' => $company, 'rules' => ['required', 'in:monday,saturday,sunday'], 'permission' => 'settings.company.manage', 'high_impact' => true],
            'fiscal.year_start_month' => ['group' => 'reporting', 'label' => 'Financial year starts in', 'description' => 'Pakistan\'s tax year starts in July. Changing this affects year-to-date reports and new fiscal years only.', 'type' => 'int', 'default' => 7, 'options' => ['1' => 'January', '7' => 'July'], 'scopes' => $company, 'rules' => ['required', 'integer', 'between:1,12'], 'permission' => 'settings.company.manage', 'high_impact' => true],

            // Pricing & discounts
            'pricing.rounding_step' => ['group' => 'pricing', 'label' => 'Round bill totals to', 'description' => 'The rounding adjustment is shown separately on the receipt and in the accounts; it is never hidden inside prices or discounts.', 'type' => 'enum', 'default' => '0', 'options' => ['0' => 'No rounding', '1' => 'Nearest Rs 1', '5' => 'Nearest Rs 5', '10' => 'Nearest Rs 10'], 'scopes' => $companyStore, 'rules' => ['required', 'in:0,1,5,10'], 'permission' => 'settings.company.manage', 'high_impact' => true],
            'pricing.max_discount_percent' => ['group' => 'pricing', 'label' => 'Cashier discount limit (%)', 'description' => 'Line or bill discounts above this percentage need a manager PIN.', 'type' => 'decimal', 'default' => '5', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'between:0,100'], 'permission' => 'settings.company.manage'],
            'pricing.margin_floor_percent' => ['group' => 'pricing', 'label' => 'Minimum margin (%)', 'description' => 'Discounts or price overrides that would sell below this margin over cost need a manager PIN. Ignored when the cost is unknown.', 'type' => 'decimal', 'default' => '0', 'scopes' => $company, 'rules' => ['required', 'numeric', 'between:-100,100'], 'permission' => 'settings.company.manage'],
            'pricing.enforce_mrp' => ['group' => 'pricing', 'label' => 'Block selling above the Market price (MRP)', 'description' => 'When on, no one can charge more than a product\'s Market price (MRP). Products without a Market price, such as loose items, are not checked.', 'type' => 'bool', 'default' => true, 'scopes' => $company, 'rules' => ['required', 'boolean'], 'permission' => 'settings.company.manage'],

            // POS
            'pos.receipt_language' => ['group' => 'pos', 'label' => 'Receipt language', 'description' => 'What prints on receipts by default. English only or Urdu only prints a shorter receipt; the cashier can still switch for one receipt before printing. In Urdu, item names come from the Urdu name typed on each product; items without one print in English.', 'type' => 'enum', 'default' => 'en', 'options' => ['en' => 'English only', 'ur' => 'Urdu only', 'both' => 'English and Urdu'], 'scopes' => $companyStore, 'rules' => ['required', 'in:en,ur,both'], 'permission' => 'settings.stores.manage'],
            'pos.receipt_width' => ['group' => 'pos', 'label' => 'Receipt paper width', 'description' => 'Match your thermal printer roll.', 'type' => 'enum', 'default' => '80', 'options' => ['80' => '80 mm', '58' => '58 mm'], 'scopes' => $companyStore, 'rules' => ['required', 'in:80,58'], 'permission' => 'settings.stores.manage'],
            'pos.receipt_footer' => ['group' => 'pos', 'label' => 'Receipt footer', 'description' => 'For example your return policy or a thank-you line.', 'type' => 'string', 'default' => 'Thank you for shopping with us.', 'scopes' => $companyStore, 'rules' => ['nullable', 'string', 'max:300'], 'permission' => 'settings.stores.manage'],
            'pos.receipt_footer_ur' => ['group' => 'pos', 'label' => 'Urdu receipt footer', 'description' => 'Printed when receipts are in Urdu or both languages.', 'type' => 'string', 'default' => null, 'scopes' => $companyStore, 'rules' => ['nullable', 'string', 'max:300'], 'permission' => 'settings.stores.manage'],
            'pos.auto_print' => ['group' => 'pos', 'label' => 'Print receipt after every sale', 'description' => 'Opens the print dialog (or prints silently in kiosk mode) as soon as a sale completes. With the printer driver\'s drawer setting on, this also opens the cash drawer.', 'type' => 'bool', 'default' => true, 'scopes' => $companyStore, 'rules' => ['required', 'boolean'], 'permission' => 'settings.stores.manage'],
            'pos.held_cart_hours' => ['group' => 'pos', 'label' => 'Keep held carts for (hours)', 'description' => 'Held carts older than this can no longer be resumed.', 'type' => 'int', 'default' => 12, 'scopes' => $companyStore, 'rules' => ['required', 'integer', 'between:1,72'], 'permission' => 'settings.stores.manage'],
            'pos.refund_approval_amount' => ['group' => 'pos', 'label' => 'Refunds above this need a manager (Rs)', 'description' => 'Cashiers can refund up to this amount on their own.', 'type' => 'decimal', 'default' => '2000', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.stores.manage'],
            'pos.payout_approval_amount' => ['group' => 'pos', 'label' => 'POS payouts above this need a manager (Rs)', 'description' => 'Small expenses paid from the drawer up to this amount need no approval.', 'type' => 'decimal', 'default' => '500', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.stores.manage'],
            'pos.variance_tolerance' => ['group' => 'pos', 'label' => 'Cash variance tolerance (Rs)', 'description' => 'Shortages or overages within this amount close without manager review. Variances are never deducted from wages automatically.', 'type' => 'decimal', 'default' => '0', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.stores.manage'],
            'pos.blind_close' => ['group' => 'pos', 'label' => 'Blind cash count at close', 'description' => 'Cashiers count the drawer before seeing the expected amount.', 'type' => 'bool', 'default' => true, 'scopes' => $companyStore, 'rules' => ['required', 'boolean'], 'permission' => 'settings.stores.manage'],
            'pos.denominations' => ['group' => 'pos', 'label' => 'Cash denominations', 'description' => 'Notes and coins shown in the cash count grid.', 'type' => 'json', 'default' => [5000, 1000, 500, 100, 50, 20, 10, 5, 2, 1], 'scopes' => $companyStore, 'rules' => ['required', 'array', 'min:1'], 'permission' => 'settings.stores.manage'],

            // Inventory
            'inventory.valuation_method' => ['group' => 'inventory', 'label' => 'Inventory valuation method', 'description' => 'How the cost of goods sold is measured. Both are tracked; this choice decides what is posted to the accounts. It can only be changed before the first stock movement, because switching later would change closed results.', 'type' => 'enum', 'default' => 'weighted_average', 'options' => ['weighted_average' => 'Weighted average cost', 'fifo' => 'FIFO (first in, first out)'], 'scopes' => $company, 'rules' => ['required', 'in:weighted_average,fifo'], 'permission' => 'settings.company.manage', 'high_impact' => true, 'locked_after' => 'first_stock_movement'],
            'inventory.near_expiry_days' => ['group' => 'inventory', 'label' => 'Near-expiry warning (days)', 'description' => 'Batches expiring within this many days are flagged at checkout and in reports.', 'type' => 'int', 'default' => 30, 'scopes' => $companyStore, 'rules' => ['required', 'integer', 'between:1,365'], 'permission' => 'settings.company.manage'],
            'inventory.adjustment_approval_value' => ['group' => 'inventory', 'label' => 'Adjustments above this value need approval (Rs)', 'description' => 'Write-offs and adjustments worth more than this wait for an approver.', 'type' => 'decimal', 'default' => '0', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.company.manage'],
            'inventory.dual_approval_value' => ['group' => 'inventory', 'label' => 'Second approval above (Rs)', 'description' => 'Very large write-offs need two different approvers.', 'type' => 'decimal', 'default' => '50000', 'scopes' => $company, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.company.manage'],
            'inventory.count_variance_review_percent' => ['group' => 'inventory', 'label' => 'Recount lines off by more than (%)', 'description' => 'Count lines with a bigger difference are flagged for a second count before approval.', 'type' => 'decimal', 'default' => '5', 'scopes' => $company, 'rules' => ['required', 'numeric', 'between:0,100'], 'permission' => 'settings.company.manage'],
            'inventory.transfer_requires_approval' => ['group' => 'inventory', 'label' => 'Transfers need approval', 'description' => 'When on, a transfer request must be approved before it can be dispatched.', 'type' => 'bool', 'default' => true, 'scopes' => $company, 'rules' => ['required', 'boolean'], 'permission' => 'settings.company.manage'],

            // Sales & credit
            'sales.default_credit_days' => ['group' => 'sales', 'label' => 'Default Khata terms (days)', 'description' => 'Due date for credit sales when a customer has no terms of their own.', 'type' => 'int', 'default' => 30, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:0,365'], 'permission' => 'settings.company.manage'],
            'sales.block_when_overdue_days' => ['group' => 'sales', 'label' => 'Block new credit when overdue by (days)', 'description' => 'Customers with a balance overdue longer than this need a manager approval for more credit. 0 turns this off.', 'type' => 'int', 'default' => 60, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:0,365'], 'permission' => 'settings.company.manage'],
            'sales.reservation_hours' => ['group' => 'sales', 'label' => 'Hold stock for confirmed orders (hours)', 'description' => 'Confirmed orders reserve stock for this long; 0 keeps the reservation until the order is invoiced or cancelled.', 'type' => 'int', 'default' => 0, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:0,720'], 'permission' => 'settings.company.manage'],

            // Purchasing
            'purchasing.approval_amount' => ['group' => 'purchasing', 'label' => 'Purchase orders above this need a second approver (Rs)', 'description' => 'Smaller orders can be approved by their creator if they hold the approval permission.', 'type' => 'decimal', 'default' => '100000', 'scopes' => $companyStore, 'rules' => ['required', 'numeric', 'min:0'], 'permission' => 'settings.company.manage'],
            'purchasing.receipt_tolerance_percent' => ['group' => 'purchasing', 'label' => 'Allow receiving more than ordered by (%)', 'description' => 'Receipts above the ordered quantity by more than this are refused.', 'type' => 'decimal', 'default' => '0', 'scopes' => $company, 'rules' => ['required', 'numeric', 'between:0,100'], 'permission' => 'settings.company.manage'],

            // HR
            'hr.grace_minutes' => ['group' => 'hr', 'label' => 'Late arrival grace (minutes)', 'description' => 'Arrivals within this many minutes after shift start count as on time.', 'type' => 'int', 'default' => 10, 'scopes' => $companyStore, 'rules' => ['required', 'integer', 'between:0,120'], 'permission' => 'hr.policies.manage'],
            'hr.half_day_minutes' => ['group' => 'hr', 'label' => 'Half day below (minutes worked)', 'description' => 'A day with fewer worked minutes is a half day.', 'type' => 'int', 'default' => 240, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:0,720'], 'permission' => 'hr.policies.manage'],
            'hr.overtime_after_minutes' => ['group' => 'hr', 'label' => 'Overtime starts after (minutes past shift end)', 'description' => 'Time worked beyond shift end plus this buffer counts as overtime.', 'type' => 'int', 'default' => 30, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:0,240'], 'permission' => 'hr.policies.manage'],
            'hr.payroll_days_basis' => ['group' => 'hr', 'label' => 'Daily rate for monthly salaries', 'description' => 'Used for unpaid absence and leave deductions. Confirm the method with your accountant.', 'type' => 'enum', 'default' => 'calendar', 'options' => ['calendar' => 'Days in the month', '30' => 'Fixed 30 days', '26' => 'Fixed 26 working days'], 'scopes' => $company, 'rules' => ['required', 'in:calendar,30,26'], 'permission' => 'hr.policies.manage', 'high_impact' => true],
            'hr.break_allowed_minutes' => ['group' => 'hr', 'label' => 'Allowed break time per day (minutes)', 'description' => 'Total break time a day above this is flagged on the Attendance and Breaks screens. 0 turns the flag off.', 'type' => 'int', 'default' => 60, 'scopes' => $companyStore, 'rules' => ['required', 'integer', 'between:0,480'], 'permission' => 'hr.policies.manage'],
            'hr.deduct_unpaid_breaks' => ['group' => 'hr', 'label' => 'Deduct unpaid breaks from worked time', 'description' => 'When on, breaks marked unpaid are taken off the hours worked (this can turn a short day into a half day and lowers hourly pay). When off, breaks are only recorded and flagged.', 'type' => 'bool', 'default' => false, 'scopes' => $company, 'rules' => ['required', 'boolean'], 'permission' => 'hr.policies.manage', 'high_impact' => true],
            'hr.max_advance_installments' => ['group' => 'hr', 'label' => 'Maximum Peshgi installments', 'description' => 'Longest repayment schedule allowed for an employee advance.', 'type' => 'int', 'default' => 6, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:1,24'], 'permission' => 'hr.policies.manage'],

            // Dashboard & reports (user preferences)
            'dashboard.widgets' => ['group' => 'dashboard', 'label' => 'Dashboard widgets', 'description' => 'Which cards appear on your dashboard and in what order.', 'type' => 'json', 'default' => ['sales_today', 'payments_today', 'sales_trend', 'top_products', 'open_shifts', 'low_stock', 'khata', 'pending_approvals'], 'scopes' => $userPreference, 'rules' => ['required', 'array'], 'permission' => 'dashboard.view'],
            'reports.default_period' => ['group' => 'dashboard', 'label' => 'Default report period', 'description' => 'The period reports open with.', 'type' => 'enum', 'default' => 'this_month', 'options' => ['today' => 'Today', 'this_week' => 'This week', 'this_month' => 'This month'], 'scopes' => $userPreference, 'rules' => ['required', 'in:today,this_week,this_month'], 'permission' => 'dashboard.view'],

            // Security & data
            'security.session_minutes' => ['group' => 'security', 'label' => 'Sign out after inactivity (minutes)', 'description' => 'Shared counter computers should use a short timeout.', 'type' => 'int', 'default' => 240, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:5,1440'], 'permission' => 'settings.security.manage'],
            'security.password_min_length' => ['group' => 'security', 'label' => 'Minimum password length', 'description' => 'Long passwords protect better than forced monthly changes; common and repeated-character passwords are always refused.', 'type' => 'int', 'default' => 10, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:8,64'], 'permission' => 'settings.security.manage'],
            'security.require_pin_for_managers' => ['group' => 'security', 'label' => 'Approvers must set a PIN', 'description' => 'Managers use a personal PIN to approve discounts, refunds and payouts at the counter.', 'type' => 'bool', 'default' => true, 'scopes' => $company, 'rules' => ['required', 'boolean'], 'permission' => 'settings.security.manage'],
            'data.export_retention_hours' => ['group' => 'data', 'label' => 'Delete export files after (hours)', 'description' => 'Generated CSV files are removed after this time.', 'type' => 'int', 'default' => 24, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:1,720'], 'permission' => 'settings.data.manage'],
            'data.audit_retention_days' => ['group' => 'data', 'label' => 'Keep audit history for (days)', 'description' => 'Audit and login history older than this is deleted by the nightly job. Financial records are never deleted.', 'type' => 'int', 'default' => 2555, 'scopes' => $company, 'rules' => ['required', 'integer', 'between:365,3650'], 'permission' => 'settings.data.manage', 'high_impact' => true],
        ];
    }

    /** @return array<string, string> */
    public static function groups(): array
    {
        return [
            'business' => 'Business & receipts', 'localization' => 'Language & formats', 'reporting' => 'Reporting & fiscal year',
            'pricing' => 'Pricing & discounts', 'pos' => 'POS & cash', 'inventory' => 'Inventory', 'sales' => 'Sales & credit',
            'purchasing' => 'Purchasing', 'hr' => 'HR & attendance', 'dashboard' => 'Dashboard & reports', 'security' => 'Security', 'data' => 'Data & retention',
        ];
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::definitions());
    }

    /** @return array{group: string, label: string, description: string, type: string, default: mixed, scopes: list<string>, rules: list<mixed>, permission: string} */
    public static function definition(string $key): array
    {
        $definitions = self::definitions();
        if (! isset($definitions[$key])) {
            throw new \InvalidArgumentException("Unknown setting [{$key}]");
        }

        return $definitions[$key];
    }
}
