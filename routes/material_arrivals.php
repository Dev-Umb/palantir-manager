<?php

use App\Http\Controllers\MaterialArrivalController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:material-arrivals.view'])->prefix('material-arrivals')->name('material-arrivals.')->group(function (): void {
    Route::get('/', [MaterialArrivalController::class, 'index'])->name('index');
    Route::get('/workbench', [MaterialArrivalController::class, 'workbench'])->name('workbench');
    Route::get('/state', [MaterialArrivalController::class, 'state'])->name('state');
    Route::post('/mutate', [MaterialArrivalController::class, 'mutate'])->name('mutate');
    Route::put('/preferences', [MaterialArrivalController::class, 'preferences'])->name('preferences');
    Route::get('/export', [MaterialArrivalController::class, 'export'])->middleware('permission:material-arrivals.export')->name('export');
});
