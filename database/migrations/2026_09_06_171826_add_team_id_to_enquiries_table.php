<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record the account an enquiry turned into, where it turned into one.
     *
     * Most never will. Null means it is still just an enquiry, and the account
     * being deleted later leaves the enquiry behind rather than taking it with
     * it — what somebody asked for is worth keeping either way.
     */
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
        });
    }
};
