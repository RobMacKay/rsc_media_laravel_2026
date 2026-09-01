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
        Schema::table('teams', function (Blueprint $table) {
            $table->string('currency', 3)->default('GBP')->after('billing_email');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // Snapshot at the point the invoice is raised. Moving a client to a
            // different currency later must not silently restate what has
            // already been issued.
            $table->string('currency', 3)->default('GBP')->after('vat_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('currency');
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('currency');
        });
    }
};
