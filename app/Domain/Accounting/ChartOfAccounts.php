<?php

namespace App\Domain\Accounting;

/**
 * Starting chart of accounts for a Pakistani retail/wholesale business.
 * Accounts with a system key are used by automatic postings; companies can
 * add their own accounts beside them.
 */
final class ChartOfAccounts
{
    /** @return list<array{code: string, name: string, name_ur?: string, type: string, subtype?: string, key?: string, parent?: string, postable?: bool}> */
    public static function template(): array
    {
        return [
            ['code' => '1000', 'name' => 'Assets', 'name_ur' => 'اثاثے', 'type' => 'asset', 'postable' => false],
            ['code' => '1010', 'name' => 'Cash on hand', 'name_ur' => 'نقد رقم', 'type' => 'asset', 'subtype' => 'cash', 'key' => 'cash_on_hand', 'parent' => '1000'],
            ['code' => '1015', 'name' => 'Cash with riders', 'name_ur' => 'رائیڈرز کے پاس نقد', 'type' => 'asset', 'subtype' => 'cash', 'key' => 'cash_with_riders', 'parent' => '1000'],
            ['code' => '1020', 'name' => 'Bank accounts', 'name_ur' => 'بینک', 'type' => 'asset', 'subtype' => 'bank', 'key' => 'bank', 'parent' => '1000'],
            ['code' => '1030', 'name' => 'Card settlements receivable', 'type' => 'asset', 'subtype' => 'clearing', 'key' => 'card_clearing', 'parent' => '1000'],
            ['code' => '1040', 'name' => 'Easypaisa wallet', 'type' => 'asset', 'subtype' => 'wallet', 'key' => 'wallet_easypaisa', 'parent' => '1000'],
            ['code' => '1041', 'name' => 'JazzCash wallet', 'type' => 'asset', 'subtype' => 'wallet', 'key' => 'wallet_jazzcash', 'parent' => '1000'],
            ['code' => '1042', 'name' => 'NayaPay wallet', 'type' => 'asset', 'subtype' => 'wallet', 'key' => 'wallet_nayapay', 'parent' => '1000'],
            ['code' => '1043', 'name' => 'SadaPay wallet', 'type' => 'asset', 'subtype' => 'wallet', 'key' => 'wallet_sadapay', 'parent' => '1000'],
            ['code' => '1100', 'name' => 'Accounts receivable (Khata)', 'name_ur' => 'کھاتہ وصولی', 'type' => 'asset', 'subtype' => 'receivable', 'key' => 'accounts_receivable', 'parent' => '1000'],
            ['code' => '1200', 'name' => 'Inventory', 'name_ur' => 'اسٹاک', 'type' => 'asset', 'subtype' => 'inventory', 'key' => 'inventory', 'parent' => '1000'],
            ['code' => '1300', 'name' => 'Employee advances (Peshgi)', 'name_ur' => 'پیشگی', 'type' => 'asset', 'subtype' => 'receivable', 'key' => 'employee_advances', 'parent' => '1000'],
            ['code' => '1400', 'name' => 'Input sales tax', 'type' => 'asset', 'subtype' => 'tax', 'key' => 'input_tax', 'parent' => '1000'],
            ['code' => '1500', 'name' => 'Supplier advances / debit balances', 'type' => 'asset', 'subtype' => 'receivable', 'key' => 'supplier_advances', 'parent' => '1000'],

            ['code' => '2000', 'name' => 'Liabilities', 'name_ur' => 'واجبات', 'type' => 'liability', 'postable' => false],
            ['code' => '2010', 'name' => 'Accounts payable', 'name_ur' => 'سپلائرز کی ادائیگی', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'accounts_payable', 'parent' => '2000'],
            ['code' => '2020', 'name' => 'Goods received not invoiced', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'grni', 'parent' => '2000'],
            ['code' => '2100', 'name' => 'Sales tax payable', 'type' => 'liability', 'subtype' => 'tax', 'key' => 'sales_tax_payable', 'parent' => '2000'],
            ['code' => '2200', 'name' => 'Salaries payable', 'name_ur' => 'واجب الادا تنخواہیں', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'salaries_payable', 'parent' => '2000'],
            ['code' => '2210', 'name' => 'EOBI payable', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'eobi_payable', 'parent' => '2000'],
            ['code' => '2220', 'name' => 'Social security payable', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'social_security_payable', 'parent' => '2000'],
            ['code' => '2230', 'name' => 'Other payroll deductions payable', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'payroll_deductions_payable', 'parent' => '2000'],
            ['code' => '2300', 'name' => 'Customer credit balances', 'type' => 'liability', 'subtype' => 'payable', 'key' => 'customer_credits', 'parent' => '2000'],

            ['code' => '3000', 'name' => 'Equity', 'name_ur' => 'سرمایہ', 'type' => 'equity', 'postable' => false],
            ['code' => '3010', 'name' => 'Owner\'s capital', 'type' => 'equity', 'key' => 'owner_capital', 'parent' => '3000'],
            ['code' => '3020', 'name' => 'Owner\'s drawings', 'type' => 'equity', 'key' => 'owner_drawings', 'parent' => '3000'],
            ['code' => '3100', 'name' => 'Retained earnings', 'type' => 'equity', 'key' => 'retained_earnings', 'parent' => '3000'],
            ['code' => '3200', 'name' => 'Opening balance equity', 'type' => 'equity', 'key' => 'opening_balance_equity', 'parent' => '3000'],

            ['code' => '4000', 'name' => 'Income', 'name_ur' => 'آمدنی', 'type' => 'income', 'postable' => false],
            ['code' => '4010', 'name' => 'Sales', 'name_ur' => 'فروخت', 'type' => 'income', 'subtype' => 'revenue', 'key' => 'sales', 'parent' => '4000'],
            ['code' => '4020', 'name' => 'Sales returns', 'name_ur' => 'واپسی', 'type' => 'income', 'subtype' => 'contra_revenue', 'key' => 'sales_returns', 'parent' => '4000'],
            ['code' => '4030', 'name' => 'Delivery charges', 'type' => 'income', 'subtype' => 'revenue', 'key' => 'delivery_income', 'parent' => '4000'],
            ['code' => '4100', 'name' => 'Rounding adjustments', 'type' => 'income', 'subtype' => 'other_income', 'key' => 'rounding', 'parent' => '4000'],
            ['code' => '4200', 'name' => 'Other income', 'type' => 'income', 'subtype' => 'other_income', 'key' => 'other_income', 'parent' => '4000'],

            ['code' => '5000', 'name' => 'Cost of sales', 'name_ur' => 'لاگت فروخت', 'type' => 'expense', 'postable' => false],
            ['code' => '5010', 'name' => 'Cost of goods sold', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'cogs', 'parent' => '5000'],
            ['code' => '5020', 'name' => 'Stock write-offs (damage, expiry)', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'stock_write_offs', 'parent' => '5000'],
            ['code' => '5030', 'name' => 'Stock count differences', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'stock_count_variance', 'parent' => '5000'],
            ['code' => '5040', 'name' => 'Purchase price variance', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'purchase_price_variance', 'parent' => '5000'],
            ['code' => '5050', 'name' => 'Stock adjustments', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'stock_adjustments', 'parent' => '5000'],
            ['code' => '5060', 'name' => 'Repacking losses', 'type' => 'expense', 'subtype' => 'cogs', 'key' => 'repack_variance', 'parent' => '5000'],

            ['code' => '6000', 'name' => 'Operating expenses', 'name_ur' => 'اخراجات', 'type' => 'expense', 'postable' => false],
            ['code' => '6010', 'name' => 'Salaries and wages', 'name_ur' => 'تنخواہیں', 'type' => 'expense', 'key' => 'salaries_expense', 'parent' => '6000'],
            ['code' => '6020', 'name' => 'Bonuses and allowances', 'type' => 'expense', 'key' => 'bonus_expense', 'parent' => '6000'],
            ['code' => '6030', 'name' => 'Overtime', 'type' => 'expense', 'key' => 'overtime_expense', 'parent' => '6000'],
            ['code' => '6040', 'name' => 'Employer EOBI and social security', 'type' => 'expense', 'key' => 'employer_contributions', 'parent' => '6000'],
            ['code' => '6100', 'name' => 'Rent', 'name_ur' => 'کرایہ', 'type' => 'expense', 'key' => 'rent', 'parent' => '6000'],
            ['code' => '6110', 'name' => 'Electricity, gas and water', 'name_ur' => 'بجلی گیس پانی', 'type' => 'expense', 'key' => 'utilities', 'parent' => '6000'],
            ['code' => '6120', 'name' => 'Fuel and transport', 'name_ur' => 'پیٹرول اور کرایہ', 'type' => 'expense', 'key' => 'transport', 'parent' => '6000'],
            ['code' => '6130', 'name' => 'Mobile and internet', 'type' => 'expense', 'key' => 'communication', 'parent' => '6000'],
            ['code' => '6140', 'name' => 'Repairs and maintenance', 'type' => 'expense', 'key' => 'repairs', 'parent' => '6000'],
            ['code' => '6150', 'name' => 'Staff tea and meals', 'name_ur' => 'چائے اور کھانا', 'type' => 'expense', 'key' => 'staff_meals', 'parent' => '6000'],
            ['code' => '6160', 'name' => 'Packaging and shopping bags', 'type' => 'expense', 'key' => 'packaging', 'parent' => '6000'],
            ['code' => '6170', 'name' => 'Bank and wallet charges', 'type' => 'expense', 'key' => 'bank_charges', 'parent' => '6000'],
            ['code' => '6180', 'name' => 'Cash over and short', 'name_ur' => 'کیش کمی بیشی', 'type' => 'expense', 'key' => 'cash_over_short', 'parent' => '6000'],
            ['code' => '6190', 'name' => 'Miscellaneous expenses', 'type' => 'expense', 'key' => 'misc_expense', 'parent' => '6000'],
            ['code' => '6200', 'name' => 'Bad debts written off', 'type' => 'expense', 'key' => 'bad_debts', 'parent' => '6000'],
        ];
    }
}
