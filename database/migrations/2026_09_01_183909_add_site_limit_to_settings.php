<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * How many sites a client may monitor, with the usual per-client override
     * so the studio can give someone more than the standard allowance.
     */
    public function up(): void
    {
        Schema::table('studio_settings', function (Blueprint $table) {
            $table->unsignedSmallInteger('site_limit')->default(5)->after('payment_terms_days');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->unsignedSmallInteger('site_limit')->nullable()->after('support_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_settings', function (Blueprint $table) {
            $table->dropColumn('site_limit');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn('site_limit');
        });
    }
};
