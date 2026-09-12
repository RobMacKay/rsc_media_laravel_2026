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
            // Whole pounds were enough while the studio raised every invoice
            // itself. The history coming over from Invoice Ninja has pence on
            // it, and rounding a client's paid invoice is not an option.
            $table->decimal('amount', 10, 2)->change();

            // A note is the only place a breakdown lives, so it has to hold
            // more than a line: some of the imported ones run to paragraphs.
            $table->text('note')->nullable()->change();

            // What has actually come in, for an invoice settled in parts.
            $table->decimal('paid_to_date', 10, 2)->default(0)->after('amount');

            // Held rather than folded into the amount, because the discount is
            // the arrangement: a charity paying £6 of a £25 hosting bill needs
            // to show as exactly that, on the invoice and in a year's time.
            $table->decimal('discount', 10, 2)->default(0)->after('paid_to_date');

            // The client's own reference for the job. Not the same thing as
            // teams.purchase_order_ref, which is one standing ref per client —
            // an agency raises a fresh number for every job it puts through.
            $table->string('po_number')->nullable()->after('discount');

            // Whoever's number this was before it was ours: the Invoice Ninja
            // number on an imported invoice, the agency's remittance
            // reference on a record.
            $table->string('external_reference')->nullable()->after('po_number');

            // An invoice the studio did not raise and must never act on: work
            // an agency self-bills and sends a remittance advice for. Kept for
            // the record and the file, never sent, never chased.
            $table->boolean('record_only')->default(false)->after('status');

            $table->index('record_only');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['record_only']);

            $table->dropColumn([
                'paid_to_date',
                'discount',
                'po_number',
                'external_reference',
                'record_only',
            ]);

            $table->string('note')->nullable()->change();
            $table->unsignedInteger('amount')->change();
        });
    }
};
