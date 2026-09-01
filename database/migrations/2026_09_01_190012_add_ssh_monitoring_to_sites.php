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
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('ssh_enabled')->default(false)->after('is_active');
            $table->unsignedSmallInteger('ssh_port')->default(22)->after('ssh_enabled');
            $table->boolean('ssh_ok')->nullable()->after('ssl_issuer');
            $table->string('ssh_banner')->nullable()->after('ssh_ok');
            $table->string('ssh_error')->nullable()->after('ssh_banner');
        });

        Schema::table('site_checks', function (Blueprint $table) {
            $table->boolean('ssh_ok')->nullable()->after('ssl_expires_at');
            $table->string('ssh_banner')->nullable()->after('ssh_ok');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['ssh_enabled', 'ssh_port', 'ssh_ok', 'ssh_banner', 'ssh_error']);
        });

        Schema::table('site_checks', function (Blueprint $table) {
            $table->dropColumn(['ssh_ok', 'ssh_banner']);
        });
    }
};
