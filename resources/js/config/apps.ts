import type { DashboardApp } from '@/types/dashboard';

/**
 * Single source of truth for every app tile on the Hassani ERP dashboard.
 * Add a new app here and it appears on the dashboard automatically.
 *
 * `routeName` must match a named Laravel route (checked via Ziggy's route()).
 * `enabled: false` renders the tile greyed-out with a "Coming soon" badge —
 * flip to true once the app's routes/pages exist.
 */
export const apps: DashboardApp[] = [
    {
        key: 'pos',
        name: 'POS',
        description: 'Sell at the counter, scan barcodes, take payments',
        routeName: 'pos.index',
        icon: 'ShoppingCart',
        group: 'operations',
        enabled: true,
    },
    {
        key: 'inventory',
        name: 'Inventory',
        description: 'Stock levels, movements and adjustments',
        routeName: 'inventory.index',
        icon: 'Archive',
        group: 'operations',
        enabled: true,
    },
    {
        key: 'products',
        name: 'Products',
        description: 'Manage your product catalog, variants and barcodes',
        routeName: 'products.index',
        icon: 'Package',
        group: 'operations',
        enabled: true,
    },
    {
        key: 'invoicing',
        name: 'Invoicing',
        description: 'Create and track customer invoices',
        routeName: 'invoicing.index',
        icon: 'Receipt',
        group: 'back-office',
        enabled: true,
    },
    {
        key: 'sales',
        name: 'Sales',
        description: 'Quotes and sales orders',
        routeName: 'sales.index',
        icon: 'TrendingUp',
        group: 'operations',
        enabled: false,
    },
    {
        key: 'accounting',
        name: 'Accounting',
        description: 'Ledgers, payments and financial reports',
        routeName: 'accounting.index',
        icon: 'Calculator',
        group: 'back-office',
        enabled: false,
    },
    {
        key: 'website',
        name: 'Website',
        description: 'Manage your online store',
        routeName: 'website.index',
        icon: 'Globe',
        group: 'customer-facing',
        enabled: false,
    },
    {
        key: 'contacts',
        name: 'Contacts',
        description: 'Customers, suppliers and employees',
        routeName: 'contacts.index',
        icon: 'Users',
        group: 'back-office',
        enabled: true,
    },
    {
        key: 'barcode',
        name: 'Barcode',
        description: 'Print labels and manage barcode lookups',
        routeName: 'barcode.index',
        icon: 'ScanLine',
        group: 'operations',
        enabled: true,
    },
    {
        key: 'todo',
        name: 'To-Do',
        description: 'Tasks for you and your team',
        routeName: 'todo.index',
        icon: 'CheckSquare',
        group: 'productivity',
        enabled: true,
    },
    {
        key: 'chat',
        name: 'Chat',
        description: 'Internal team messaging',
        routeName: 'chat.index',
        icon: 'MessageSquare',
        group: 'productivity',
        enabled: false,
    },
    {
        key: 'users',
        name: 'Users',
        description: 'Manage user accounts and app permissions',
        routeName: 'users.index',
        icon: 'ShieldCheck',
        group: 'back-office',
        enabled: true,
        adminOnly: true,
    },
];