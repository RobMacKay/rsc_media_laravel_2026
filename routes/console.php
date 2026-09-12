<?php

use App\Console\Commands\ChaseInvoices;
use App\Console\Commands\CheckSites;
use App\Console\Commands\RaisePlanInvoices;
use App\Console\Commands\RaiseRecurringInvoices;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Leaves a mark the preflight check can read. Everything else on this schedule
// only queues work, so without this there is no way to tell from inside the
// application whether the scheduler is running at all.
Schedule::call(fn () => Cache::forever('scheduler.last_run', now()->toIso8601String()))
    ->everyMinute()
    ->description('Record that the scheduler ran');

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

// Plan invoices go out on the first of the month. The admin invoices screen can
// raise them early by hand; the action refuses to bill the same client twice in
// a month, so pressing the button does not double up with this.
Schedule::command(RaisePlanInvoices::class)
    ->monthlyOn(1, '07:00')
    ->withoutOverlapping()
    ->description('Raise monthly support plan invoices');

// Standing monthly arrangements. Daily rather than monthly, because each one
// bills on its own day of the month — the action will not raise a second
// invoice for a schedule that already has one this month.
Schedule::command(RaiseRecurringInvoices::class)
    ->dailyAt('07:15')
    ->withoutOverlapping()
    ->description('Raise invoices for standing monthly arrangements');

// Site health. Every fifteen minutes is often enough to catch an outage while
// it still matters, without hammering a client's site.
Schedule::command(CheckSites::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->description('Check monitored sites');

// Unpaid invoices, once a day. Marks anything past its date as overdue and
// sends whichever reminder stage is now due; each stage goes out once, and
// there is nothing automatic after a fortnight.
Schedule::command(ChaseInvoices::class)
    ->dailyAt('08:00')
    ->withoutOverlapping()
    ->description('Chase unpaid invoices');
