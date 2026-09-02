<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            // The last reminder stage sent, so each one goes out once.
            $table->string('reminder_stage', 32)->nullable()->after('paid_at');
            $table->timestamp('last_reminded_at')->nullable()->after('reminder_stage');
            // Set when the studio silences one invoice, usually because a
            // conversation about it is already happening.
            $table->timestamp('reminders_paused_at')->nullable()->after('last_reminded_at');
        });

        Schema::table('studio_settings', function (Blueprint $table) {
            $table->boolean('invoice_reminders')->default(true)->after('late_fee_percent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['reminder_stage', 'last_reminded_at', 'reminders_paused_at']);
        });

        Schema::table('studio_settings', function (Blueprint $table) {
            $table->dropColumn('invoice_reminders');
        });
    }
};
