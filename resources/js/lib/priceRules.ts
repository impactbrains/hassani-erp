import { money } from './format';
import { localName, t } from './i18n';

/**
 * Price rules on screen: the shapes, the words used for them, and one-line summaries such as
 * "When quantity is more than 5 pcs → Wholesale price". The server checks and applies them
 * (App\Domain\Catalog\PriceRules); this file only helps people read and write them.
 */
export type Op = 'gte' | 'gt' | 'lte' | 'lt';
export interface Condition {
    type: 'quantity' | 'line_value' | 'bill_total' | 'customer_type' | 'customer' | 'price_list' | 'store' | 'pack' | 'date';
    op?: Op;
    value?: string;
    unit_id?: number | null;
    values?: string[];
    ids?: number[];
    unit_ids?: number[];
    from?: string | null;
    to?: string | null;
}
export interface Rule {
    id?: number | null;
    name?: string | null;
    scope?: 'variant' | 'category' | 'brand' | 'all';
    category_id?: number | null;
    brand_id?: number | null;
    match: 'all' | 'any';
    conditions: Condition[];
    action: 'wholesale' | 'fixed' | 'percent_off';
    value?: string | null;
    unit_id?: number | null;
    is_active: boolean;
}
export interface RuleOptions {
    units: any[];
    stores: any[];
    priceBooks: any[];
    categories: any[];
    brands: any[];
    customers: any[];
}

export const OPS: Record<Op, string> = { gte: 'at least', gt: 'more than', lte: 'at most', lt: 'less than' };
export const CUSTOMER_TYPES: Record<string, string> = { walk_in: 'Walk-in (no customer chosen)', retail: 'Retail', wholesale: 'Wholesale', shopkeeper: 'Shopkeeper', institution: 'Institution' };
/** What a condition can look at. `shared` ones also make sense for a category, brand or all products. */
export const CONDITION_TYPES: { type: Condition['type']; label: string }[] = [
    { type: 'quantity', label: 'Quantity of this item' },
    { type: 'line_value', label: 'Amount for this item' },
    { type: 'bill_total', label: 'Bill total' },
    { type: 'customer_type', label: 'Customer type' },
    { type: 'customer', label: 'Particular customers' },
    { type: 'price_list', label: 'Customer’s price list' },
    { type: 'store', label: 'Store' },
    { type: 'date', label: 'Dates' },
    { type: 'pack', label: 'Pack size sold' },
];

export function blankCondition(type: Condition['type'] = 'quantity'): Condition {
    switch (type) {
        case 'quantity':
            return { type, op: 'gt', value: '5', unit_id: null };
        case 'line_value':
        case 'bill_total':
            return { type, op: 'gte', value: '' };
        case 'customer_type':
            return { type, values: ['wholesale'] };
        case 'pack':
            return { type, unit_ids: [] };
        case 'date':
            return { type, from: '', to: '' };
        default:
            return { type, ids: [] };
    }
}

/** A rule as saved by the server, made ready for the form (empty optional fields filled in, numbers as plain text). */
export function normalizeRule<T extends Partial<Rule>>(r: T): Rule & T {
    const conditions = (r.conditions ?? []).map((c: Condition) => ({
        ...c,
        ...(c.type === 'quantity' ? { unit_id: c.unit_id ?? null } : {}),
        ...(c.value !== undefined && c.value !== null ? { value: String(Number(c.value)) } : {}),
        ...(c.type === 'date' ? { from: c.from ?? '', to: c.to ?? '' } : {}),
    }));
    return { ...r, name: r.name ?? '', match: r.match ?? 'all', conditions, action: r.action ?? 'wholesale', value: r.value !== null && r.value !== undefined && r.value !== '' ? String(Number(r.value)) : '', unit_id: r.unit_id ?? null, is_active: r.is_active ?? true } as Rule & T;
}

export function blankRule(scope: Rule['scope'] = 'variant'): Rule {
    return { id: null, name: '', scope, match: 'all', conditions: [blankCondition('quantity')], action: 'wholesale', value: '', unit_id: null, is_active: true };
}

const unitCode = (o: RuleOptions, id: number | null | undefined, base = '') => (id ? (o.units.find((u) => u.id === id)?.code ?? '') : base);
const names = (list: any[], ids: number[] = []) => ids.map((id) => localName(list.find((x) => x.id === id)) || `#${id}`).join(', ');

/** "quantity is more than 5 pcs" (read after "When") */
export function describeCondition(c: Condition, o: RuleOptions, baseUnit = ''): string {
    const op = OPS[c.op ?? 'gte'];
    switch (c.type) {
        // One sentence per comparison, so each language can put the number where it belongs.
        case 'quantity':
            return t(`quantity is ${op} :value :unit`, { value: c.value ?? '', unit: unitCode(o, c.unit_id, baseUnit) }).trim();
        case 'line_value':
            return t(`amount for this item is ${op} :amount`, { amount: money(c.value ?? 0) });
        case 'bill_total':
            return t(`bill total is ${op} :amount`, { amount: money(c.value ?? 0) });
        case 'customer_type':
            return t('customer type is :list', { list: (c.values ?? []).map((v) => t(CUSTOMER_TYPES[v] ?? v)).join(', ') });
        case 'customer':
            return t('customer is :list', { list: names(o.customers, c.ids) });
        case 'price_list':
            return t('customer’s price list is :list', { list: names(o.priceBooks, c.ids) });
        case 'store':
            return t('store is :list', { list: names(o.stores, c.ids) });
        case 'pack':
            return t('pack sold is :list', { list: (c.unit_ids ?? []).map((id) => unitCode(o, id)).join(', ') });
        case 'date':
            if (c.from && c.to) return t('date is :from to :to', { from: c.from, to: c.to });
            return c.from ? t('date is :from or later', { from: c.from }) : t('date is :to or earlier', { to: c.to ?? '' });
    }
}

/** "Wholesale price" / "Rs 2,880 per ctn" / "5% off the In-store price" */
export function describeAction(r: Rule, o: RuleOptions, baseUnit = ''): string {
    if (r.action === 'wholesale') return t('Wholesale price');
    if (r.action === 'fixed') return t(':price per :unit', { price: money(r.value ?? 0), unit: unitCode(o, r.unit_id, baseUnit) });
    return t(':percent% off the In-store price', { percent: String(Number(r.value ?? 0)) });
}

/** "When quantity is more than 5 pcs → Wholesale price" */
export function describeRule(r: Rule, o: RuleOptions, baseUnit = ''): string {
    const parts = r.conditions.map((c) => describeCondition(c, o, baseUnit));
    const price = describeAction(r, o, baseUnit);
    if (parts.length === 0) return t('Always → :price', { price });
    return t('When :conditions → :price', { conditions: parts.join(r.match === 'any' ? ` ${t('or')} ` : ` ${t('and')} `), price });
}

/** Who a shared rule is for: "All products", "Category: Soap", "Brand: Tapal". */
export function describeScope(r: Rule & { category?: any; brand?: any }, o: RuleOptions): string {
    if (r.scope === 'category') return t('Category: :name', { name: localName(r.category ?? o.categories.find((c) => c.id === r.category_id)) });
    if (r.scope === 'brand') return t('Brand: :name', { name: localName(r.brand ?? o.brands.find((b) => b.id === r.brand_id)) });
    return t('All products');
}

const actionLabel = (tag: any) => (tag.action === 'wholesale' ? t('Wholesale price') : tag.action === 'percent_off' ? t(':percent% off', { percent: tag.percent }) : t('Special price'));

/** The small tag on a cart line: "Wholesale price · 6+" */
export function tagText(tag: any): string {
    if (!tag) return '';
    const label = actionLabel(tag);
    if (!tag.from) return label;
    const unit = tag.from.unit ? ` ${tag.from.unit}` : '';
    const from = tag.from.op === 'gt' ? t('over :n', { n: `${tag.from.value}${unit}` }) : `${tag.from.value}+${unit}`;
    return `${label} · ${from}`;
}

/** Under a cart line: "Add 2 more for the wholesale price" */
export function hintText(hint: any): string {
    if (!hint) return '';
    const label = actionLabel(hint).toLowerCase();
    if (hint.more) return t('Add :n more for :label', { n: hint.more, label: currentLabel(hint, label) });
    return t(':label from :qty :unit', { label: actionLabel(hint), qty: hint.target, unit: hint.unit ?? '' }).trim();
}
// English reads "the wholesale price"; other texts use the label as it is.
const currentLabel = (hint: any, label: string) => (hint.action === 'wholesale' ? t('the wholesale price') : label);
