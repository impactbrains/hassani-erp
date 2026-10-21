<?php

namespace App\Domain\Reporting;

use App\Domain\Reporting\Catalog\ComplaintsCatalog;
use App\Domain\Reporting\Catalog\FinanceCatalog;
use App\Domain\Reporting\Catalog\InventoryCatalog;
use App\Domain\Reporting\Catalog\SalesCatalog;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Every report the system offers, grouped into sections for the report centre. */
final class ReportRegistry
{
    public const SECTIONS = [
        'executive' => ['label' => 'Overview', 'label_ur' => 'خلاصہ'],
        'sales' => ['label' => 'Sales', 'label_ur' => 'فروخت'],
        'products' => ['label' => 'Products', 'label_ur' => 'مصنوعات'],
        'customers' => ['label' => 'Customers and Khata', 'label_ur' => 'گاہک اور کھاتہ'],
        'complaints' => ['label' => 'Complaints', 'label_ur' => 'شکایات'],
        'payments' => ['label' => 'Payments and invoices', 'label_ur' => 'ادائیگیاں'],
        'agents' => ['label' => 'Sales agents and orders', 'label_ur' => 'سیلز ایجنٹ'],
        'pos' => ['label' => 'POS, shifts and riders', 'label_ur' => 'پی او ایس'],
        'inventory' => ['label' => 'Inventory', 'label_ur' => 'اسٹاک'],
        'purchasing' => ['label' => 'Purchasing and suppliers', 'label_ur' => 'خریداری'],
        'finance' => ['label' => 'Finance', 'label_ur' => 'مالیات'],
        'hr' => ['label' => 'HR and payroll', 'label_ur' => 'ایچ آر'],
        'audit' => ['label' => 'Audit and exceptions', 'label_ur' => 'آڈٹ'],
        'forecasts' => ['label' => 'Forecasts', 'label_ur' => 'پیش گوئی'],
    ];

    /** @var array<string, Report>|null */
    private static ?array $reports = null;

    /** @return array<string, Report> */
    public static function all(): array
    {
        if (self::$reports === null) {
            self::$reports = [];
            foreach ([...SalesCatalog::reports(), ...InventoryCatalog::reports(), ...FinanceCatalog::reports(), ...ComplaintsCatalog::reports()] as $definition) {
                self::$reports[$definition['key']] = new DefinedReport($definition);
            }
        }

        return self::$reports;
    }

    public static function find(string $key): Report
    {
        return self::all()[$key] ?? throw new NotFoundHttpException('Unknown report.');
    }

    /** Reports the user may open (permission in at least one store). */
    public static function forUser(User $user): array
    {
        return array_filter(self::all(), fn (Report $r) => $user->hasPermission($r->permission()) || $user->storeIdsFor($r->permission()) !== []);
    }
}
