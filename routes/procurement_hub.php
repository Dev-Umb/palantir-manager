<?php

use App\Http\Controllers\HubAdminController;
use App\Http\Controllers\HubController;
use App\Http\Middleware\EnsureHubAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureHubAccess::class])->prefix('procurement-hub')->name('hub.')->group(function (): void {
    Route::get('/', [HubController::class, 'index'])->name('index');
    Route::get('/notices/{notice}', [HubController::class, 'show'])->name('show');
    Route::post('/notices/{notice}/bookmark', [HubController::class, 'bookmark'])->name('bookmark');
    Route::get('/research', [HubController::class, 'research'])->name('research');
    Route::post('/research', [HubController::class, 'storeResearch'])->middleware('throttle:ai-post')->name('research.store');
    Route::post('/runs/{run}/cancel', [HubController::class, 'cancel'])->name('runs.cancel');
    Route::post('/runs/{run}/retry', [HubController::class, 'retry'])->middleware('throttle:ai-post')->name('runs.retry');
    Route::get('/follows', [HubController::class, 'follows'])->name('follows');
    Route::post('/subscriptions', [HubController::class, 'subscribe'])->name('subscriptions.store');
    Route::delete('/subscriptions/{subscription}', [HubController::class, 'deleteSubscription'])->name('subscriptions.destroy');
    Route::post('/subscriptions/{subscription}/read', [HubController::class, 'readSubscription'])->name('subscriptions.read');
    Route::middleware(EnsureHubAccess::class.':manage')->group(function (): void {
        Route::post('/runs/{run}/review', [HubAdminController::class, 'review'])->name('runs.review');
        Route::get('/admin', [HubAdminController::class, 'index'])->name('admin');
        Route::post('/sources', [HubAdminController::class, 'source'])->name('sources.store');
        Route::put('/sources/{source}', [HubAdminController::class, 'source'])->name('sources.update');
        Route::post('/sources/{source}/collect', [HubAdminController::class, 'collect'])->middleware('throttle:ai-post')->name('sources.collect');
        Route::get('/company', [HubAdminController::class, 'company'])->name('company');
        Route::post('/company', [HubAdminController::class, 'saveCompany'])->name('company.store');
        Route::put('/company/{company}', [HubAdminController::class, 'saveCompany'])->name('company.update');
        Route::post('/company/{company}/confirm', [HubAdminController::class, 'confirmCompany'])->name('company.confirm');
        Route::post('/imports/preview', [HubAdminController::class, 'preview'])->name('imports.preview');
        Route::post('/imports/{import}/confirm', [HubAdminController::class, 'confirmImport'])->name('imports.confirm');
    });
});
