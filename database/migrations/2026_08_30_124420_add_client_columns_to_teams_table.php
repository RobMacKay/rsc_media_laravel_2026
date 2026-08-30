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
            $table->foreignId('plan_id')->nullable()->after('is_personal')->constrained()->nullOnDelete();
            $table->string('requested_plan')->nullable()->after('plan_id');
            $table->string('billing_email')->nullable()->after('requested_plan');
            $table->string('purchase_order_ref')->nullable()->after('billing_email');
            $table->unsignedInteger('hour_rate')->nullable()->after('purchase_order_ref');
            $table->unsignedInteger('day_rate')->nullable()->after('hour_rate');
            $table->decimal('support_hours', 5, 2)->nullable()->after('day_rate');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('support_hours');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plan_id');
            $table->dropColumn([
                'requested_plan',
                'billing_email',
                'purchase_order_ref',
                'hour_rate',
                'day_rate',
                'support_hours',
                'payment_terms_days',
            ]);
        });
    }
};
