<?php

namespace App\Support\Permissions;

/**
 * The single list of permissions the application understands.
 *
 * Keys follow "app.resource.action". Every route, API endpoint, export and
 * queued job checks one of these keys on the server; hiding a button in the
 * UI is never the control. Permissions marked "sensitive" expose money
 * costs, personal data or security controls and are highlighted in the role
 * editor.
 */
final class PermissionRegistry
{
    /** @return array<string, array{label: string, permissions: array<string, array{label: string, description?: string, sensitive?: bool}>}> */
    public static function groups(): array
    {
        return [
            'dashboard' => ['label' => 'Dashboard', 'permissions' => [
                'dashboard.view' => ['label' => 'See the dashboard'],
                'dashboard.view_financials' => ['label' => 'See revenue, margin and cash on the dashboard', 'sensitive' => true],
            ]],
            'pos' => ['label' => 'Point of Sale', 'permissions' => [
                'pos.access' => ['label' => 'Open the POS'],
                'pos.sell' => ['label' => 'Ring up sales'],
                'pos.sell_on_credit' => ['label' => 'Sell on Khata (customer credit)'],
                'pos.discount' => ['label' => 'Give discounts within the allowed limit'],
                'pos.discount.approve' => ['label' => 'Approve discounts above the limit', 'description' => 'Manager approval with PIN.'],
                'pos.price.override' => ['label' => 'Approve price overrides', 'sensitive' => true],
                'pos.refund' => ['label' => 'Process returns and refunds'],
                'pos.refund.approve' => ['label' => 'Approve refunds above the threshold'],
                'pos.hold' => ['label' => 'Hold and resume carts'],
                'pos.shift.open' => ['label' => 'Open a cash shift'],
                'pos.shift.close' => ['label' => 'Close a cash shift (Z-Report)'],
                'pos.shift.view_all' => ['label' => 'See every cashier\'s shifts'],
                'pos.shift.approve_variance' => ['label' => 'Approve cash shortages and overages', 'sensitive' => true],
                'pos.cash.drop' => ['label' => 'Record cash drops and pay-ins'],
                'pos.cash.payout' => ['label' => 'Request POS payouts'],
                'pos.cash.payout.approve' => ['label' => 'Approve POS payouts', 'sensitive' => true],
                'pos.no_sale.approve' => ['label' => 'Approve opening the drawer without a sale'],
                'pos.reports' => ['label' => 'Print X and Z reports'],
            ]],
            'sales' => ['label' => 'Sales', 'permissions' => [
                'sales.orders.view' => ['label' => 'See sales orders'],
                'sales.orders.manage' => ['label' => 'Create and edit sales orders'],
                'sales.orders.confirm' => ['label' => 'Confirm and cancel sales orders'],
                'sales.orders.view_all_agents' => ['label' => 'See orders of other sales agents'],
                'sales.invoices.view' => ['label' => 'See invoices and receipts'],
                'sales.invoices.post' => ['label' => 'Issue invoices'],
                'sales.credit_notes.create' => ['label' => 'Issue credit notes'],
                'sales.payments.receive' => ['label' => 'Receive customer payments (Wasooli)'],
                'sales.payments.verify' => ['label' => 'Verify wallet and card payments', 'sensitive' => true],
                'sales.credit_limit.override' => ['label' => 'Approve sales over a customer\'s credit limit', 'sensitive' => true],
                'sales.deliveries.manage' => ['label' => 'Assign and track deliveries'],
                'sales.deliveries.rider' => ['label' => 'Work as a rider (own deliveries only)'],
                'sales.riders.handover' => ['label' => 'Receive rider cash handovers'],
                'sales.targets.manage' => ['label' => 'Set sales targets'],
            ]],
            'catalog' => ['label' => 'Products & Prices', 'permissions' => [
                'catalog.products.view' => ['label' => 'See products'],
                'catalog.products.manage' => ['label' => 'Create and edit products'],
                'catalog.products.import' => ['label' => 'Import products in bulk'],
                'catalog.products.export' => ['label' => 'Export products'],
                'catalog.prices.manage' => ['label' => 'Change selling prices', 'sensitive' => true],
                'catalog.costs.view' => ['label' => 'See purchase costs and margins', 'sensitive' => true],
                'catalog.labels.print' => ['label' => 'Print barcode labels'],
            ]],
            'inventory' => ['label' => 'Inventory', 'permissions' => [
                'inventory.stock.view' => ['label' => 'See stock levels'],
                'inventory.adjust' => ['label' => 'Request stock adjustments and write-offs'],
                'inventory.adjust.approve' => ['label' => 'Approve adjustments and write-offs', 'sensitive' => true],
                'inventory.transfers.manage' => ['label' => 'Create, dispatch and receive transfers'],
                'inventory.transfers.approve' => ['label' => 'Approve transfers'],
                'inventory.counts.count' => ['label' => 'Count stock'],
                'inventory.counts.manage' => ['label' => 'Plan stock counts'],
                'inventory.counts.approve' => ['label' => 'Approve count variances', 'sensitive' => true],
                'inventory.batches.manage' => ['label' => 'Quarantine, release and recall batches'],
                'inventory.repack' => ['label' => 'Repack bulk goods'],
                'inventory.locations.manage' => ['label' => 'Manage stock locations'],
                'inventory.replenishment.manage' => ['label' => 'Manage reorder levels and suggestions'],
                'inventory.valuation.view' => ['label' => 'See inventory valuation', 'sensitive' => true],
            ]],
            'purchasing' => ['label' => 'Purchasing', 'permissions' => [
                'purchasing.suppliers.view' => ['label' => 'See suppliers'],
                'purchasing.suppliers.manage' => ['label' => 'Create and edit suppliers'],
                'purchasing.orders.manage' => ['label' => 'Create purchase orders'],
                'purchasing.orders.approve' => ['label' => 'Approve purchase orders', 'sensitive' => true],
                'purchasing.receive' => ['label' => 'Receive goods'],
                'purchasing.bills.manage' => ['label' => 'Record supplier bills', 'sensitive' => true],
                'purchasing.payments.make' => ['label' => 'Pay suppliers', 'sensitive' => true],
                'purchasing.returns.manage' => ['label' => 'Return goods to suppliers'],
            ]],
            'crm' => ['label' => 'Customers (CRM)', 'permissions' => [
                'crm.customers.view' => ['label' => 'See customers'],
                'crm.customers.manage' => ['label' => 'Create and edit customers'],
                'crm.customers.contact_details' => ['label' => 'See customer phone numbers and addresses', 'sensitive' => true],
                'crm.customers.merge' => ['label' => 'Merge duplicate customers'],
                'crm.customers.export' => ['label' => 'Export customer lists', 'sensitive' => true],
                'crm.khata.view' => ['label' => 'See Khata balances and statements', 'sensitive' => true],
                'crm.credit.manage' => ['label' => 'Set credit limits and blocks', 'sensitive' => true],
                'crm.notes.sensitive' => ['label' => 'See restricted customer notes', 'sensitive' => true],
                'crm.cases.manage' => ['label' => 'Handle complaints and cases'],
                'crm.followups.manage' => ['label' => 'Manage follow-ups and payment promises'],
                'crm.segments.manage' => ['label' => 'Manage tags and segments'],
            ]],
            'accounting' => ['label' => 'Accounting', 'permissions' => [
                'accounting.view' => ['label' => 'See the ledger and journals', 'sensitive' => true],
                'accounting.journals.manage' => ['label' => 'Write manual journals', 'sensitive' => true],
                'accounting.journals.post' => ['label' => 'Post and reverse journals', 'sensitive' => true],
                'accounting.accounts.manage' => ['label' => 'Manage the chart of accounts', 'sensitive' => true],
                'accounting.periods.manage' => ['label' => 'Close and reopen periods', 'sensitive' => true],
                'accounting.budgets.manage' => ['label' => 'Manage budgets'],
            ]],
            'hr' => ['label' => 'HR & Payroll', 'permissions' => [
                'hr.employees.view' => ['label' => 'See employee directory'],
                'hr.employees.manage' => ['label' => 'Create and edit employees'],
                'hr.employees.sensitive' => ['label' => 'See CNIC, bank details and documents', 'sensitive' => true],
                'hr.employees.lifecycle' => ['label' => 'Confirm, transfer, suspend and offboard employees', 'sensitive' => true],
                'hr.attendance.view' => ['label' => 'See attendance'],
                'hr.attendance.manage' => ['label' => 'Record attendance and manage rosters'],
                'hr.attendance.approve' => ['label' => 'Approve attendance corrections and shift swaps'],
                'hr.leave.view' => ['label' => 'See leave requests'],
                'hr.leave.approve' => ['label' => 'Approve leave'],
                'hr.payroll.view' => ['label' => 'See salaries and payroll', 'sensitive' => true],
                'hr.payroll.manage' => ['label' => 'Prepare payroll and salary structures', 'sensitive' => true],
                'hr.payroll.approve' => ['label' => 'Approve and post payroll', 'sensitive' => true],
                'hr.advances.manage' => ['label' => 'Record Peshgi requests'],
                'hr.advances.approve' => ['label' => 'Approve and disburse Peshgi', 'sensitive' => true],
                'hr.cases.manage' => ['label' => 'Handle grievances, incidents and discipline', 'sensitive' => true],
                'hr.reviews.manage' => ['label' => 'Write performance reviews'],
                'hr.policies.manage' => ['label' => 'Manage shifts, leave types and holidays'],
            ]],
            'reports' => ['label' => 'Reports', 'permissions' => [
                'reports.sales' => ['label' => 'Sales reports'],
                'reports.pos' => ['label' => 'POS, shift and cash reports'],
                'reports.products' => ['label' => 'Product and category reports'],
                'reports.customers' => ['label' => 'Customer and Khata reports', 'sensitive' => true],
                'reports.inventory' => ['label' => 'Inventory reports'],
                'reports.purchasing' => ['label' => 'Purchasing and supplier reports'],
                'reports.finance' => ['label' => 'Financial statements', 'sensitive' => true],
                'reports.hr' => ['label' => 'HR and payroll reports', 'sensitive' => true],
                'reports.audit' => ['label' => 'Audit and exception reports', 'sensitive' => true],
                'reports.forecasts' => ['label' => 'Forecasts and planning'],
                'reports.forecasts.run' => ['label' => 'Recalculate forecasts'],
                'reports.export' => ['label' => 'Export reports to CSV', 'sensitive' => true],
                'reports.all_stores' => ['label' => 'Report across all stores'],
            ]],
            'settings' => ['label' => 'Settings & Security', 'permissions' => [
                'settings.company.manage' => ['label' => 'Business details and defaults', 'sensitive' => true],
                'settings.stores.manage' => ['label' => 'Stores, registers and receipts'],
                'settings.catalog.manage' => ['label' => 'Units, categories, taxes, payment methods, reason codes'],
                'settings.users.view' => ['label' => 'See user accounts'],
                'settings.users.manage' => ['label' => 'Invite, suspend and deactivate users', 'sensitive' => true],
                'settings.roles.manage' => ['label' => 'Edit roles and permissions', 'sensitive' => true],
                'settings.security.view' => ['label' => 'See login, audit and export logs', 'sensitive' => true],
                'settings.security.manage' => ['label' => 'Change security policies and revoke sessions', 'sensitive' => true],
                'settings.api.manage' => ['label' => 'Create and revoke API keys', 'sensitive' => true],
                'settings.data.manage' => ['label' => 'Data retention and backups', 'sensitive' => true],
            ]],
            'workspace' => ['label' => 'Workspace apps', 'permissions' => [
                'workspace.todo' => ['label' => 'Use To-Do'],
                'workspace.todo.manage_all' => ['label' => 'Assign tasks to anyone'],
                'workspace.chat' => ['label' => 'Use team chat'],
                'workspace.chat.manage' => ['label' => 'Manage chat groups'],
                'workspace.recruitment' => ['label' => 'Use recruitment'],
                'workspace.website' => ['label' => 'Edit the website'],
                'workspace.website.admin' => ['label' => 'Manage website blocks and templates'],
            ]],
        ];
    }

    /** @return array<string, array{label: string, description?: string, sensitive?: bool, group: string}> */
    public static function all(): array
    {
        static $flat = null;
        if ($flat !== null) {
            return $flat;
        }

        $flat = [];
        foreach (self::groups() as $groupKey => $group) {
            foreach ($group['permissions'] as $key => $meta) {
                $flat[$key] = $meta + ['group' => $groupKey];
            }
        }

        return $flat;
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::all());
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * Expands patterns like "pos.*" or "reports.*" into concrete keys.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function expand(array $patterns): array
    {
        $keys = [];
        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                foreach (self::keys() as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $keys[] = $key;
                    }
                }

                continue;
            }
            if (! self::exists($pattern)) {
                throw new \InvalidArgumentException("Unknown permission [{$pattern}]");
            }
            $keys[] = $pattern;
        }

        return array_values(array_unique($keys));
    }
}
