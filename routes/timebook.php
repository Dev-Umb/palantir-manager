<?php

use App\Http\Controllers\TimebookController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:timebook.view'])->prefix('timebook')->name('timebook.')->group(function (): void {
    Route::get('/', [TimebookController::class, 'index'])->name('index');
    Route::get('/records', [TimebookController::class, 'records'])->name('records');
    Route::get('/names', [TimebookController::class, 'names'])->name('names');
    Route::get('/export.xlsx', [TimebookController::class, 'export'])->middleware('permission:timebook.export')->name('export');
    Route::post('/entries', [TimebookController::class, 'store'])->middleware('permission:timebook.create')->name('store');
    Route::put('/entries/{entry}', [TimebookController::class, 'update'])->middleware('permission:timebook.update')->name('update');
    Route::delete('/entries/{entry}', [TimebookController::class, 'destroy'])->middleware('permission:timebook.delete')->name('destroy');
    Route::post('/entries/{entry}/restore', [TimebookController::class, 'restore'])->middleware('permission:timebook.delete')->name('restore');
    Route::get('/entries/{entry}/history', [TimebookController::class, 'history'])->middleware('permission:timebook.audit')->name('history');
});
