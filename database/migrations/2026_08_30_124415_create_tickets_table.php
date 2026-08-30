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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description');
            $table->string('system')->nullable();
            $table->string('page_url')->nullable();
            $table->string('type')->default('bug');
            $table->string('priority')->default('normal');
            $table->string('status')->default('open');
            $table->date('target_on')->nullable();
            $table->decimal('quoted_hours', 5, 2)->nullable();
            $table->unsignedInteger('quoted_rate')->nullable();
            $table->string('billing_mode')->default('support_hours');
            $table->timestamp('quote_sent_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
