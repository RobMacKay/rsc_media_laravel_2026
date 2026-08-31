<?php

use App\Http\Controllers\InvoiceDownloadController;
use App\Http\Middleware\EnsureUserHasClientAccess;
use App\Http\Middleware\EnsureUserIsStudioAdmin;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::home')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::prefix('client')->name('client.')->group(function () {
        Route::livewire('/', 'pages::client.dashboard')->name('dashboard');
        Route::livewire('tickets', 'pages::client.tickets')->name('tickets');
        Route::livewire('projects', 'pages::client.projects')->name('projects');
        Route::livewire('team', 'pages::client.team')->name('team');
        Route::livewire('plan', 'pages::client.plan')->name('plan');

        Route::middleware(EnsureUserHasClientAccess::class.':billing')->group(function () {
            Route::livewire('invoices', 'pages::client.invoices')->name('invoices');
            Route::livewire('invoices/{invoice:number}', 'pages::client.invoice')->name('invoices.show');
            Route::get('invoices/{invoice:number}/pdf', InvoiceDownloadController::class)->name('invoices.pdf');
        });
    });

    Route::prefix('admin')->name('admin.')->middleware(EnsureUserIsStudioAdmin::class)->group(function () {
        Route::livewire('/', 'pages::admin.queue')->name('queue');
        Route::livewire('proposals', 'pages::admin.proposals')->name('proposals');
        Route::livewire('jobs', 'pages::admin.jobs')->name('jobs');
        Route::livewire('invoices', 'pages::admin.invoices')->name('invoices');
        Route::livewire('settings', 'pages::admin.settings')->name('settings');
    });
});

require __DIR__.'/settings.php';
