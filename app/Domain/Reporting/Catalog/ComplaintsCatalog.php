<?php

namespace App\Domain\Reporting\Catalog;

use App\Domain\Reporting\ReportFilters as F;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\DB;

/** Customer complaints and cases (everything added from a customer's page under Complaints). */
final class ComplaintsCatalog
{
    /** Cases added in the period, for the stores the user may see (a case with no store is included). */
    private static function cases(F $f)
    {
        return DB::table('crm_cases as k')->where('k.company_id', CurrentCompany::require())
            ->whereBetween(DB::raw("(k.created_at AT TIME ZONE '{$f->timezone}')::date"), [$f->from, $f->to])
            ->where(fn ($q) => $q->whereIn('k.store_id', $f->storeIds)->orWhereNull('k.store_id'));
    }

    /** @return list<array<string, mixed>> */
    public static function reports(): array
    {
        $count = fn (F $p) => (int) self::cases($p)->count();

        return [
            [
                'key' => 'complaints.list', 'section' => 'complaints', 'title' => 'Complaints', 'title_ur' => 'شکایات', 'permission' => 'reports.customers',
                'description' => 'Every complaint and customer case added in the period, with the total, how many are still open, resolved or overdue.', 'comparison' => true,
                'filters' => ['period', 'stores', 'search'],
                'definition' => [
                    'source' => 'Complaints and cases added on customer pages',
                    'date_basis' => 'Date the complaint was added',
                    'definitions' => ['Total' => 'Every complaint or case added in the period, whatever its type.', 'Still open' => 'Status Open or In progress.', 'Overdue' => 'Still open and past its due date.'],
                    'limitations' => ['Status is as of now, not as of the end date.'],
                ],
                'columns' => [
                    'number' => ['label' => 'Number', 'type' => 'text'], 'created' => ['label' => 'Added', 'type' => 'date'], 'customer' => ['label' => 'Customer', 'type' => 'text'],
                    'type' => ['label' => 'Type', 'type' => 'text'], 'subject' => ['label' => 'Subject', 'type' => 'text'], 'priority' => ['label' => 'Priority', 'type' => 'text'],
                    'status' => ['label' => 'Status', 'type' => 'status'], 'owner' => ['label' => 'Owner', 'type' => 'text'], 'due' => ['label' => 'Due', 'type' => 'date'],
                    'resolved' => ['label' => 'Resolved', 'type' => 'date'], 'contact_id' => ['label' => 'id', 'type' => 'hidden'],
                ],
                'rows' => fn (F $f) => self::cases($f)->leftJoin('contacts as c', 'c.id', '=', 'k.contact_id')->leftJoin('users as u', 'u.id', '=', 'k.owner_id')
                    ->when($f->search, fn ($q, $s) => $q->where(fn ($w) => $w->where('k.subject', 'ilike', "%{$s}%")->orWhere('k.number', 'ilike', "%{$s}%")->orWhere('c.name', 'ilike', "%{$s}%")->orWhere('k.walk_in_name', 'ilike', "%{$s}%")->orWhere('k.walk_in_phone', 'ilike', "%{$s}%")))
                    ->selectRaw("k.number, (k.created_at AT TIME ZONE '{$f->timezone}')::date created, COALESCE(c.name, CASE WHEN NULLIF(k.walk_in_name, '') IS NOT NULL THEN 'Walk-in customer (' || k.walk_in_name || ')' ELSE 'Walk-in customer' END) customer, initcap(replace(k.type, '_', ' ')) AS type, k.subject,
                        initcap(k.priority) priority, k.status, COALESCE(u.name, '-') AS owner, (k.due_at AT TIME ZONE '{$f->timezone}')::date due,
                        (k.resolved_at AT TIME ZONE '{$f->timezone}')::date resolved, k.contact_id"),
                'summary' => function (F $f) use ($count) {
                    $now = self::cases($f);
                    $open = (clone $now)->whereIn('k.status', ['open', 'in_progress'])->count();
                    $done = (clone $now)->whereIn('k.status', ['resolved', 'closed'])->count();
                    $overdue = (clone $now)->whereIn('k.status', ['open', 'in_progress'])->whereNotNull('k.due_at')->where('k.due_at', '<', now())->count();
                    $previous = $f->comparison() ? $count($f->comparison()) : null;

                    return [
                        SalesCatalog::card('total', 'Total complaints', (int) $now->count(), 'int', $previous),
                        SalesCatalog::card('walk_in', 'Walk-in customers', (int) (clone $now)->whereNull('k.contact_id')->count(), 'int'),
                        SalesCatalog::card('open', 'Still open', $open, 'int'),
                        SalesCatalog::card('resolved', 'Resolved or closed', $done, 'int'),
                        SalesCatalog::card('overdue', 'Overdue', $overdue, 'int'),
                    ];
                },
                'insights' => fn (F $f, array $s) => SalesCatalog::changeInsights($f, $s),
                'drill' => ['column' => 'customer', 'route' => 'crm.customers.show', 'param' => 'contact_id'],
                'sort' => ['created', 'desc'],
            ],
        ];
    }
}
