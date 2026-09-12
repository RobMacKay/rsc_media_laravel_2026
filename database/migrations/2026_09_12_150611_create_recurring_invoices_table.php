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
        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('ad_hoc');
            $table->string('note');
            $table->decimal('amount', 10, 2);
            $table->decimal('discount', 10, 2)->default(0);
            $table->string('po_number')->nullable();

            // Plan invoices all go out on the first. These are private
            // arrangements and each one bills on its own day, so the schedule
            // has to carry the day rather than assume it.
            $table->unsignedTinyInteger('day_of_month')->default(1);

            // A schedule for work billed by somebody else, which raises a paid
            // record rather than an invoice to send. The agency contract that
            // pays by remittance every month is one of these.
            $table->boolean('record_only')->default(false);

            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'day_of_month']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            // What raised this invoice, and what stops the same schedule
            // raising a second one in the same month — the same job
            // invoices.ticket_id does for a chargeable ticket.
            $table->foreignId('recurring_invoice_id')
                ->nullable()
                ->after('ticket_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
        });

        Schema::dropIfExists('recurring_invoices');
    }
};
