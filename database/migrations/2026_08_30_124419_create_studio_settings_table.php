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
        Schema::create('studio_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('hour_rate')->default(65);
            $table->unsignedInteger('day_rate')->default(460);
            $table->decimal('day_length', 4, 2)->default(7.5);
            $table->decimal('minimum_charge', 4, 2)->default(0.5);
            $table->unsignedSmallInteger('out_of_hours_uplift')->default(50);
            $table->unsignedSmallInteger('payment_terms_days')->default(21);
            $table->decimal('late_fee_percent', 5, 2)->default(2);
            $table->boolean('vat_registered')->default(true);
            $table->string('vat_number')->nullable();
            $table->decimal('vat_rate', 5, 2)->default(20);
            $table->string('account_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('sort_code', 16)->nullable();
            $table->string('account_number', 16)->nullable();
            $table->string('reference_format')->default('RSC-{invoice}');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('studio_settings');
    }
};
