<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * A site with no client is one of the studio's own. The unique index on
     * (team_id, host) still keeps a client from adding the same host twice;
     * SQL treats nulls as distinct, so the studio's own list is checked in
     * the component instead.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable(false)->change();
        });
    }
};
