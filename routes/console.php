<?php

use App\Domain\Hr\AttendanceService;
use App\Domain\Reporting\ForecastService;
use App\Domain\Reporting\ReportExporter;
use App\Models\Company;
use App\Models\Store;
use App\Support\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
| Background jobs. The one-click starter runs `php artisan schedule:work`
| next to the web server, so these run while the shop computer is on.
*/

$eachStore = function (callable $callback): void {
    foreach (Company::query()->where('status', 'active')->get() as $company) {
        CurrentCompany::runAs($company, function () use ($callback) {
            foreach (Store::query()->where('is_active', true)->get() as $store) {
                $callback($store);
            }
        });
    }
};

Artisan::command('hassani:forecasts', function () use ($eachStore) {
    $eachStore(fn (Store $store) => $this->info($store->name.': '.app(ForecastService::class)->run($store)->series_count.' products forecast'));
})->purpose('Recalculate weekly demand forecasts for every store');

Artisan::command('hassani:mark-absences {date?}', function (?string $date = null) {
    foreach (Company::query()->where('status', 'active')->get() as $company) {
        CurrentCompany::runAs($company, function () use ($company, $date) {
            $day = $date ?? now($company->timezone)->subDay()->toDateString();
            $this->info($company->name.': '.app(AttendanceService::class)->markAbsences($day).' absences recorded for '.$day);
        });
    }
})->purpose('Record absences for rostered staff who did not clock in (yesterday by default)');

Artisan::command('hassani:housekeeping', function () {
    $purged = CurrentCompany::bypass(fn () => app(ReportExporter::class)->purgeExpired());
    $expired = CurrentCompany::bypass(fn () => \App\Models\StockReservation::query()->withoutGlobalScopes()->where('status', 'active')->whereNotNull('expires_at')->where('expires_at', '<', now())->update(['status' => 'expired']));
    $carts = CurrentCompany::bypass(fn () => \App\Models\HeldCart::query()->withoutGlobalScopes()->where('status', 'held')->where('expires_at', '<', now())->update(['status' => 'expired']));
    $trash = 0;
    foreach (Company::query()->where('status', 'active')->get() as $company) {
        $trash += CurrentCompany::runAs($company, fn () => app(\App\Domain\Catalog\ProductService::class)->purgeExpired());
    }
    $this->info("Purged {$purged} expired exports, released {$expired} reservations, expired {$carts} held carts, removed {$trash} products that were in the Trash for over ".\App\Domain\Catalog\ProductService::TRASH_DAYS.' days.');
})->purpose('Remove expired export files, stock reservations and held carts');

Artisan::command('hassani:reset-owner-password {email}', function (string $email) {
    $user = \App\Models\User::query()->where('email', mb_strtolower($email))->firstOrFail();
    $temporary = \Illuminate\Support\Str::password(14, symbols: false);
    $user->forceFill(['password' => $temporary, 'must_change_password' => true, 'status' => 'active'])->save();
    \Illuminate\Support\Facades\DB::table('sessions')->where('user_id', $user->id)->delete();
    $this->warn("Temporary password for {$user->email}: {$temporary}");
    $this->line('It must be changed at the next sign-in. Run this only on the shop computer.');
})->purpose('Emergency: reset a forgotten password from the shop computer');

Schedule::command('hassani:mark-absences')->dailyAt('01:15');
Schedule::command('hassani:housekeeping')->hourly();
Schedule::command('hassani:forecasts')->weeklyOn(1, '02:30');
Schedule::command('queue:prune-failed --hours=720')->daily();
