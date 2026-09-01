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
            $table->foreignId('ticket_id')->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        Schema::table('projects', function (Blueprint $table) {
            // The contract value as a number. value_label stays for display,
            // but nothing could work out a balance from "£6,600 fixed".
            // Null means there is no fixed total to bill against, such as a
            // recurring care plan.
            $table->unsignedInteger('agreed_value')->nullable()->after('value_label');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ticket_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('agreed_value');
        });
    }
};
