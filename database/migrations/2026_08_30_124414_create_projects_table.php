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
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->string('summary')->nullable();
            $table->string('phase')->default('scoping');
            $table->unsignedTinyInteger('percent')->default(0);
            $table->string('milestone')->nullable();
            $table->date('due_on')->nullable();
            $table->string('waiting_on_client')->nullable();
            $table->decimal('hours_used', 6, 2)->default(0);
            $table->decimal('hours_budgeted', 6, 2)->nullable();
            $table->string('value_label')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'phase']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
