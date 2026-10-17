<?php

namespace App\Support\Permissions;

/**
 * Default roles every new company starts with. Companies can edit these or
 * create their own; the owner role always holds every permission and the
 * last owner can never be removed.
 */
final class RoleTemplates
{
    /** @return array<string, array{name: string, description: string, owner?: bool, permissions: list<string>}> */
    public static function all(): array
    {
        return [
            'owner' => [
                'name' => 'Owner / Admin',
                'description' => 'Full control of the business, including users, roles, money and security.',
                'owner' => true,
                'permissions' => ['*'],
            ],
            'store_manager' => [
                'name' => 'Store manager',
                'description' => 'Runs a store: approves discounts, refunds, payouts and cash variances; manages stock and staff schedules.',
                'permissions' => [
                    'dashboard.*', 'pos.*', 'sales.orders.*', 'sales.invoices.*', 'sales.credit_notes.create',
                    'sales.payments.receive', 'sales.payments.verify', 'sales.credit_limit.override', 'sales.deliveries.manage',
                    'sales.riders.handover', 'sales.targets.manage',
                    'catalog.products.view', 'catalog.products.manage', 'catalog.prices.manage', 'catalog.costs.view', 'catalog.labels.print',
                    'inventory.*', 'purchasing.suppliers.view', 'purchasing.orders.manage', 'purchasing.receive', 'purchasing.returns.manage',
                    'crm.customers.view', 'crm.customers.manage', 'crm.customers.contact_details', 'crm.khata.view', 'crm.credit.manage',
                    'crm.cases.manage', 'crm.followups.manage', 'crm.segments.manage',
                    'hr.employees.view', 'hr.attendance.*', 'hr.leave.*', 'hr.reviews.manage',
                    'reports.sales', 'reports.pos', 'reports.products', 'reports.customers', 'reports.inventory', 'reports.purchasing',
                    'reports.forecasts', 'reports.export', 'reports.audit',
                    'settings.users.view', 'settings.stores.manage',
                    'workspace.*',
                ],
            ],
            'cashier' => [
                'name' => 'Cashier',
                'description' => 'Rings up sales, takes payments, holds carts and closes their own shift.',
                'permissions' => [
                    'pos.access', 'pos.sell', 'pos.sell_on_credit', 'pos.discount', 'pos.refund', 'pos.hold',
                    'pos.shift.open', 'pos.shift.close', 'pos.cash.drop', 'pos.cash.payout', 'pos.reports',
                    'sales.invoices.view', 'sales.payments.receive', 'catalog.products.view', 'inventory.stock.view',
                    'crm.customers.view', 'crm.customers.manage', 'crm.customers.contact_details',
                    'workspace.todo', 'workspace.chat',
                ],
            ],
            'accountant' => [
                'name' => 'Accountant',
                'description' => 'Keeps the books: journals, periods, supplier bills and payments, financial statements.',
                'permissions' => [
                    'dashboard.*', 'accounting.*', 'sales.invoices.view', 'sales.payments.receive', 'sales.payments.verify',
                    'purchasing.suppliers.view', 'purchasing.bills.manage', 'purchasing.payments.make',
                    'catalog.products.view', 'catalog.costs.view', 'inventory.stock.view', 'inventory.valuation.view',
                    'crm.customers.view', 'crm.khata.view', 'hr.payroll.view',
                    'reports.sales', 'reports.pos', 'reports.customers', 'reports.inventory', 'reports.purchasing', 'reports.finance',
                    'reports.export', 'reports.audit', 'reports.all_stores',
                    'workspace.todo', 'workspace.chat',
                ],
            ],
            'inventory_manager' => [
                'name' => 'Inventory manager',
                'description' => 'Manages products, stock, counts, transfers, purchasing and replenishment.',
                'permissions' => [
                    'dashboard.view', 'catalog.*', 'inventory.*', 'purchasing.suppliers.*', 'purchasing.orders.manage', 'purchasing.receive',
                    'purchasing.returns.manage', 'reports.inventory', 'reports.products', 'reports.purchasing', 'reports.forecasts',
                    'reports.forecasts.run', 'reports.export', 'workspace.todo', 'workspace.chat',
                ],
            ],
            'hr_manager' => [
                'name' => 'HR manager',
                'description' => 'Manages employees, attendance, leave, payroll and Peshgi. Does not see sales or accounting by default.',
                'permissions' => [
                    'hr.*', 'reports.hr', 'reports.export', 'workspace.todo', 'workspace.chat', 'workspace.recruitment',
                ],
            ],
            'sales_agent' => [
                'name' => 'Sales agent',
                'description' => 'Books wholesale orders for assigned customers and records collections.',
                'permissions' => [
                    'sales.orders.view', 'sales.orders.manage', 'sales.invoices.view', 'sales.payments.receive',
                    'catalog.products.view', 'inventory.stock.view', 'crm.customers.view', 'crm.customers.manage',
                    'crm.customers.contact_details', 'crm.khata.view', 'crm.followups.manage', 'workspace.todo', 'workspace.chat',
                ],
            ],
            'rider' => [
                'name' => 'Rider',
                'description' => 'Sees and updates only their own deliveries and cash collections.',
                'permissions' => ['sales.deliveries.rider', 'workspace.chat'],
            ],
            'warehouse_worker' => [
                'name' => 'Warehouse worker',
                'description' => 'Receives goods, counts stock, picks orders and moves stock between locations.',
                'permissions' => [
                    'catalog.products.view', 'inventory.stock.view', 'inventory.counts.count', 'inventory.transfers.manage',
                    'inventory.repack', 'purchasing.receive', 'catalog.labels.print', 'workspace.todo', 'workspace.chat',
                ],
            ],
        ];
    }
}
