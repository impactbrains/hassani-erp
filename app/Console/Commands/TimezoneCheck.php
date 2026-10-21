<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\Store;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows, for every store, the time zone in use and how the newest posted sale reads in it.
 * Run it when a time on screen looks hours out: `php artisan hassani:timezone-check`.
 */
class TimezoneCheck extends Command
{
    protected $signature = 'hassani:timezone-check';

    protected $description = 'Show each store\'s time zone and how its latest sale time is read';

    public function handle(): int
    {
        $this->line('Database session time zone: '.DB::selectOne('SHOW TimeZone')->TimeZone.'   (server now: '.DB::selectOne('SELECT now()::text AS n')->n.')');

        $session = DB::selectOne('SHOW TimeZone')->TimeZone;
        $this->line('App time zone: '.config('app.timezone').($session === config('app.timezone') || ($session === 'UTC' && config('app.timezone') === 'UTC') ? '  (matches the database: good)' : '  <-- does NOT match the database session zone; stored times will be shifted'));

        foreach (Company::query()->where('status', 'active')->get() as $company) {
            CurrentCompany::runAs($company, function () use ($company) {
                $this->info("{$company->name} (company zone {$company->timezone})");
                foreach (Store::query()->orderBy('id')->get() as $store) {
                    $zone = $store->timezone();
                    $latest = SalesInvoice::query()->where('store_id', $store->id)->where('status', 'posted')->orderByDesc('posted_at')->first();
                    $row = $latest ? DB::selectOne('SELECT posted_at::text AS raw, (posted_at AT TIME ZONE ?)::text AS local FROM sales_invoices WHERE id = ?', [$zone, $latest->id]) : null;
                    $this->line(sprintf('  %-10s %-22s clock there now: %s%s', $store->code, $zone, now($zone)->format('d-m-Y h:i A'),
                        $row ? "   | last sale stored as {$row->raw}, reads {$row->local} at the store" : '   | no posted sales yet'));
                }
            });
        }

        return self::SUCCESS;
    }
}
