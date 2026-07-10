export type AppGroup = 'operations' | 'back-office' | 'customer-facing' | 'productivity';

export interface DashboardApp {
    key: string;
    name: string;
    description: string;
    routeName: string;
    icon: string; // heroicon name, resolved in AppTile.vue
    group: AppGroup;
    enabled: boolean; // false = "coming soon", tile shown but not clickable
    adminOnly?: boolean; // true = only visible to users with is_admin
}