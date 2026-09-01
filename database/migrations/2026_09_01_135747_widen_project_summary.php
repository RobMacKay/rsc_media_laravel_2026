<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `proposals.brief` is a text column and the client's form allows 5,000
     * characters, but `Proposal::approve()` copies it into `projects.summary`,
     * which was a varchar(255). SQLite does not enforce that, so it passed
     * locally; MySQL throws 1406 and rolls the whole approval back, leaving
     * the client with an error and no project or deposit invoice.
     */
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->text('summary')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('summary')->nullable()->change();
        });
    }
};
