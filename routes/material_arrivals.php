<?php

use App\Http\Controllers\MaterialArrivalController;
use App\Http\Controllers\ProductionWorkbenchController;
use App\Http\Controllers\ProductionWorkspaceController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:material-arrivals.view'])->prefix('material-arrivals')->name('material-arrivals.')->group(function (): void {
    Route::get('/', [MaterialArrivalController::class, 'index'])->name('index');
    Route::get('/workbench', [MaterialArrivalController::class, 'workbench'])->name('workbench');
    Route::get('/state', [MaterialArrivalController::class, 'state'])->name('state');
    Route::post('/mutate', [MaterialArrivalController::class, 'mutate'])->name('mutate');
    Route::put('/preferences', [MaterialArrivalController::class, 'preferences'])->name('preferences');
    Route::get('/export', [MaterialArrivalController::class, 'export'])->middleware('permission:material-arrivals.export')->name('export');
});

Route::get('/production-workbench', ProductionWorkbenchController::class)->middleware('auth')->name('production-workbench');

Route::middleware(['auth', 'permission:material-arrivals.view', 'permission:template-accessories.view'])->prefix('production-workbench')->name('production-workbench.')->group(function (): void {
    Route::get('/development', [ProductionWorkspaceController::class, 'workbench'])->name('development');
    Route::get('/data/state', [ProductionWorkspaceController::class, 'state'])->name('state');
    Route::post('/data/mutate', [ProductionWorkspaceController::class, 'mutate'])->name('mutate');
    Route::get('/data/snapshot', [ProductionWorkspaceController::class, 'snapshot'])->name('snapshot');
});
