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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('ad_hoc');
            $table->string('note')->nullable();
            $table->unsignedInteger('amount');
            $table->decimal('vat_rate', 5, 2)->default(0);
            $table->date('issued_on');
            $table->date('due_on');
            $table->string('status')->default('draft');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
