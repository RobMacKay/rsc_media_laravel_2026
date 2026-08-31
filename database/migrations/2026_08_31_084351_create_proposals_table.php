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
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();

            // What the client asked for.
            $table->string('title');
            $table->text('brief');
            $table->text('goal')->nullable();
            $table->string('budget_guide')->nullable();
            $table->string('needed_by')->nullable();
            $table->string('contact')->nullable();

            // What the studio wrote back. Scope is one line per bullet and
            // phases are "name | date | note", matching the admin textareas.
            $table->text('scope')->nullable();
            $table->text('phases')->nullable();
            $table->string('excluded')->nullable();
            $table->unsignedInteger('price')->default(0);
            $table->unsignedSmallInteger('deposit_percent')->default(40);
            $table->unsignedSmallInteger('weeks')->default(4);

            $table->string('status')->default('submitted');
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
