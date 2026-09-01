<?php

namespace App\Providers;

use App\Models\StudioSetting;
use App\Support\Sites\CertificateInspector;
use App\Support\Sites\OpenSslCertificateInspector;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // There is exactly one settings row, so anything type-hinting the model
        // wants that row. Without this the container hands out a blank model,
        // and an invoice built through it would quietly use the class defaults
        // for VAT and payment terms rather than what the studio actually set.
        $this->app->bind(StudioSetting::class, fn () => StudioSetting::current());

        // Reading a certificate means opening a real TLS connection, so it sits
        // behind a contract that tests can swap for a fake.
        $this->app->bind(CertificateInspector::class, OpenSslCertificateInspector::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
