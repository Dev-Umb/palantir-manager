<?php

use App\Http\Controllers\ProcurementController;
use App\Http\Middleware\EnsureHubAccess;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', EnsureHubAccess::class])->prefix('procurement-hub')->name('hub.')->group(function (): void {
    Route::get('/', [ProcurementController::class, 'index'])->name('index');
    Route::get('/notices/{notice}', [ProcurementController::class, 'show'])->whereNumber('notice')->name('show');
    Route::get('/batches', [ProcurementController::class, 'batches'])->name('batches');
    Route::get('/batches/{batch}', [ProcurementController::class, 'batch'])->name('batch');
    Route::get('/clients', [ProcurementController::class, 'clients'])->name('clients');
    Route::post('/clients', [ProcurementController::class, 'createClient'])->name('clients.store');
    Route::delete('/clients/{client}', [ProcurementController::class, 'revoke'])->name('clients.revoke');
    Route::post('/observations/{observation}/accept', [ProcurementController::class, 'accept'])->name('observations.accept');
});
