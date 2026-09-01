<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('email');
            $table->string('contact_preference')->default('email')->after('phone');
            // MySQL rejects a default on a JSON column, so the default for this
            // and for teams.systems is declared on the model instead.
            $table->json('notification_preferences')->nullable()->after('contact_preference');
            $table->timestamp('onboarded_at')->nullable()->after('is_admin');
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->string('company_number')->nullable()->after('name');
            $table->text('address')->nullable()->after('company_number');
            $table->string('vat_number')->nullable()->after('address');
            $table->json('systems')->nullable()->after('vat_number');
        });

        Schema::table('studio_settings', function (Blueprint $table) {
            $table->string('welcome_video_url')->nullable()->after('website');
        });

        // Everyone who already has an account predates the wizard. Leaving them
        // null would ambush them with it on their next sign-in.
        DB::table('users')->update(['onboarded_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'contact_preference', 'notification_preferences', 'onboarded_at']);
        });

        Schema::table('teams', function (Blueprint $table) {
            $table->dropColumn(['company_number', 'address', 'vat_number', 'systems']);
        });

        Schema::table('studio_settings', function (Blueprint $table) {
            $table->dropColumn('welcome_video_url');
        });
    }
};
