<?php

use App\Console\Commands\RaisePlanInvoices;
use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

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
