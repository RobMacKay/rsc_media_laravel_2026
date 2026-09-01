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
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('host');
            $table->boolean('is_active')->default(true);

            // The latest result, kept on the row so a list does not have to
            // reach into the check log for every site.
            $table->string('status', 16)->default('unknown');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->boolean('ssl_valid')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->string('ssl_issuer')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_up_at')->nullable();
            $table->timestamp('last_down_at')->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            // Set when the "your site is down" email goes out, and cleared when
            // it recovers, so one outage sends one email.
            $table->timestamp('down_notified_at')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'host']);
        });

        Schema::create('site_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->string('status', 16);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->boolean('ssl_valid')->nullable();
            $table->timestamp('ssl_expires_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'checked_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('site_checks');
        Schema::dropIfExists('sites');
    }
};
