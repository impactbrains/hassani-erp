import { BarChart3, Boxes, Calculator, ClipboardCheck, LayoutDashboard, Package, Settings, ShoppingBasket, ShoppingCart, Truck, UserRound, Users } from 'lucide-vue-next';
import type { Component } from 'vue';

/** `match` is a route name pattern (or several) that marks the item as the current page. */
export interface NavItem { label: string; route: string; params?: Record<string, unknown>; any?: string[]; match?: string | string[] }

/**
 * A sidebar entry. With `items` it is a section that opens and closes; without, it is a single link
 * (then `route`, `any` and `match` work like an item's).
 */
export interface NavGroup { key: string; label: string; icon: Component; items?: NavItem[]; route?: string; any?: string[]; match?: string | string[] }

/** Sidebar. Items show only when the user holds one of `any`; a section with no visible items is hidden. */
export const navigation: NavGroup[] = [
    { key: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, route: 'dashboard', match: 'dashboard' },
    { key: 'me', label: 'My work', icon: ClipboardCheck, route: 'me', match: 'me' },
    { key: 'pos', label: 'POS', icon: ShoppingCart, items: [
        { label: 'Open POS', route: 'pos.screen', any: ['pos.access'], match: 'pos.screen' },
        { label: 'Shifts', route: 'pos.shifts.index', any: ['pos.shift.open', 'pos.shift.view_all'], match: 'pos.shifts.*' },
    ] },
    { key: 'sales', label: 'Sales', icon: ShoppingBasket, items: [
        { label: 'Receipts & invoices', route: 'sales.invoices.index', any: ['sales.invoices.view'], match: 'sales.invoices.*' },
        { label: 'Orders', route: 'sales.orders.index', any: ['sales.orders.view'], match: 'sales.orders.*' },
        { label: 'Riders', route: 'sales.riders', any: ['sales.deliveries.manage'], match: 'sales.riders' },
        { label: 'Wasooli', route: 'sales.collections', any: ['sales.payments.receive'], match: 'sales.collections' },
        { label: 'Verify payments', route: 'sales.payments.verify', any: ['sales.payments.verify'], match: 'sales.payments.*' },
        { label: 'Targets', route: 'sales.targets', any: ['sales.targets.manage'], match: 'sales.targets' },
    ] },
    { key: 'customers', label: 'Customers & Khata', icon: Users, items: [
        { label: 'Customers', route: 'crm.customers.index', any: ['crm.customers.view'], match: 'crm.customers.*' },
        { label: 'Follow-ups', route: 'crm.work', any: ['crm.customers.view'], match: 'crm.work' },
        { label: 'Segments', route: 'crm.segments', any: ['crm.customers.view'], match: 'crm.segments' },
    ] },
    { key: 'products', label: 'Products', icon: Package, items: [
        { label: 'All products', route: 'catalog.products.index', any: ['catalog.products.view'], match: ['catalog.products.*', 'catalog.import*', 'catalog.labels'] },
        { label: 'Pricing rules', route: 'catalog.price-rules.index', any: ['catalog.products.view'], match: 'catalog.price-rules.*' },
        { label: 'Products to create', route: 'inventory.unlisted', any: ['catalog.products.view'], match: 'inventory.unlisted' },
    ] },
    { key: 'stock', label: 'Stock', icon: Boxes, items: [
        { label: 'Stock on hand', route: 'inventory.stock', any: ['inventory.stock.view'], match: 'inventory.stock*' },
        { label: 'Purchase stock', route: 'purchasing.purchase-stock', any: ['inventory.purchase_stock'], match: 'purchasing.purchase-stock' },
        { label: 'Adjustments', route: 'inventory.adjustments.index', any: ['inventory.adjust', 'inventory.adjust.approve'], match: 'inventory.adjustments.*' },
        { label: 'Transfers', route: 'inventory.transfers.index', any: ['inventory.transfers.manage', 'inventory.transfers.approve'], match: 'inventory.transfers.*' },
        { label: 'Counts', route: 'inventory.counts.index', any: ['inventory.counts.count'], match: 'inventory.counts.*' },
        { label: 'Batches', route: 'inventory.batches', any: ['inventory.stock.view'], match: 'inventory.batches' },
        { label: 'Reorder', route: 'inventory.replenishment', any: ['inventory.replenishment.manage'], match: 'inventory.replenishment' },
        { label: 'Repack', route: 'inventory.repack', any: ['inventory.repack'], match: 'inventory.repack' },
    ] },
    { key: 'purchasing', label: 'Purchasing', icon: Truck, items: [
        { label: 'Suppliers', route: 'purchasing.suppliers.index', any: ['purchasing.suppliers.view'], match: 'purchasing.suppliers.*' },
        { label: 'Purchase orders', route: 'purchasing.orders.index', any: ['purchasing.orders.manage', 'purchasing.orders.approve'], match: 'purchasing.orders.*' },
        { label: 'Goods received', route: 'purchasing.receipts.index', any: ['purchasing.receive', 'inventory.purchase_stock'], match: 'purchasing.receipts.*' },
        { label: 'Supplier bills', route: 'purchasing.bills', any: ['purchasing.bills.manage'], match: 'purchasing.bills*' },
    ] },
    { key: 'accounting', label: 'Accounting', icon: Calculator, items: [
        { label: 'Accounts', route: 'accounting.accounts.index', any: ['accounting.view'], match: 'accounting.accounts.*' },
        { label: 'Journals', route: 'accounting.journals.index', any: ['accounting.view'], match: 'accounting.journals.*' },
        { label: 'Periods', route: 'accounting.periods', any: ['accounting.view'], match: 'accounting.periods' },
        { label: 'Budgets', route: 'accounting.budgets', any: ['accounting.view'], match: 'accounting.budgets' },
    ] },
    { key: 'hr', label: 'HR', icon: UserRound, items: [
        { label: 'Employees', route: 'hr.employees.index', any: ['hr.employees.view'], match: 'hr.employees.*' },
        { label: 'Attendance', route: 'hr.attendance', any: ['hr.attendance.view'], match: 'hr.attendance' },
        { label: 'Roster', route: 'hr.roster', any: ['hr.attendance.view'], match: 'hr.roster' },
        { label: 'Leave', route: 'hr.leave', any: ['hr.leave.view'], match: 'hr.leave' },
        { label: 'Payroll', route: 'hr.payroll.index', any: ['hr.payroll.view'], match: 'hr.payroll.*' },
        { label: 'Peshgi', route: 'hr.advances', any: ['hr.payroll.view'], match: 'hr.advances' },
    ] },
    { key: 'reports', label: 'Reports', icon: BarChart3, route: 'reports.index', match: 'reports.*',
        any: ['reports.sales', 'reports.pos', 'reports.products', 'reports.customers', 'reports.inventory', 'reports.purchasing', 'reports.finance', 'reports.hr', 'reports.audit', 'reports.forecasts', 'inventory.valuation.view', 'hr.payroll.view'] },
    { key: 'settings', label: 'Settings', icon: Settings, items: [
        { label: 'Business', route: 'settings.index', any: ['settings.company.manage', 'settings.stores.manage', 'settings.security.manage'], match: 'settings.index' },
        { label: 'Users', route: 'settings.users.index', any: ['settings.users.view'], match: 'settings.users.*' },
        { label: 'Roles', route: 'settings.roles.index', any: ['settings.users.view'], match: 'settings.roles.*' },
        { label: 'Stores', route: 'settings.stores.index', any: ['settings.stores.manage'], match: 'settings.stores.*' },
        { label: 'Lists', route: 'settings.lists', any: ['settings.catalog.manage', 'settings.company.manage', 'hr.policies.manage', 'inventory.locations.manage'], match: 'settings.lists' },
        { label: 'Security', route: 'settings.security', any: ['settings.security.view'], match: 'settings.security' },
        { label: 'API keys', route: 'settings.api.index', any: ['settings.api.manage'], match: 'settings.api.*' },
    ] },
];
