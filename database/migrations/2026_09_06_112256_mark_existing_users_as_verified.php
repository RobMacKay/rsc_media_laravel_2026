<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Verification is being switched on for accounts that pre-date it.
     *
     * Without this every existing client is bounced to "check your inbox" for
     * a link that was never sent, on their next visit. Anyone still waiting on
     * a welcome link is left alone: setting their password verifies them, and
     * they cannot sign in before that anyway.
     */
    public function up(): void
    {
        DB::table('users')
            ->whereNull('email_verified_at')
            ->where('must_set_password', false)
            ->update(['email_verified_at' => now()]);
    }

    /**
     * Nothing to undo: there is no record of which accounts were stamped here,
     * and clearing the lot would lock out everyone who has verified since.
     */
    public function down(): void
    {
        //
    }
};
