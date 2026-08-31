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
        Schema::table('studio_settings', function (Blueprint $table) {
            $table->string('company_name')->nullable()->after('id');
            $table->string('company_number')->nullable()->after('company_name');
            $table->text('address')->nullable()->after('company_number');
            $table->string('email')->nullable()->after('address');
            $table->string('phone')->nullable()->after('email');
            $table->string('website')->nullable()->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('studio_settings', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_number', 'address', 'email', 'phone', 'website']);
        });
    }
};
