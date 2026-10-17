<?php

use App\Support\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

pest()->extend(Tests\TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Run the deferred balance/integrity triggers at statement end so a
        // test's (never committed) transaction still exercises them.
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        CurrentCompany::clear();
        \App\Support\Settings\Settings::flush();
        \App\Domain\Accounting\Accounts::flush();
    })
    ->in('Feature');

pest()->extend(Tests\TestCase::class)->in('Unit');
