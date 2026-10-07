<?php

use App\Http\Controllers\QuotationController;
use App\Http\Middleware\EnsureQuotationAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureQuotationAccess::class])->prefix('quotations')->name('quotations.')->group(function (): void {
    Route::get('/', [QuotationController::class, 'index'])->name('index');
    Route::post('/chat', [QuotationController::class, 'chat'])->middleware('throttle:10,1')->name('chat');
    Route::post('/market', [QuotationController::class, 'market'])->middleware('throttle:6,1')->name('market');
    Route::post('/history', [QuotationController::class, 'history'])->name('history');
    Route::post('/price', [QuotationController::class, 'price'])->middleware('throttle:30,1')->name('price');
    Route::post('/calculate', [QuotationController::class, 'calculate'])->middleware('throttle:30,1')->name('calculate');
    Route::post('/adopt', [QuotationController::class, 'adopt'])->middleware('throttle:30,1')->name('adopt');
    Route::post('/export', [QuotationController::class, 'export'])->name('export');
    Route::get('/archives', [QuotationController::class, 'archives'])->name('archives');
    Route::get('/archives/{archive}', [QuotationController::class, 'show'])->name('show');
    Route::get('/archives/{archive}/download', [QuotationController::class, 'download'])->name('download');
});
