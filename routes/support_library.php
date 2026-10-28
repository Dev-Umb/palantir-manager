<?php

use App\Http\Controllers\SupportLibraryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:support-library.view'])->prefix('support-library')->name('support-library.')->group(function (): void {
    Route::get('/', [SupportLibraryController::class, 'index'])->name('index');
    Route::get('/library', [SupportLibraryController::class, 'library'])->name('library');
    Route::post('/query', [SupportLibraryController::class, 'query'])->name('query');
    Route::middleware('permission:support-library.manage')->group(function (): void {
        Route::put('/library', [SupportLibraryController::class, 'save'])->name('save');
        Route::post('/imports/preview', [SupportLibraryController::class, 'preview'])->name('imports.preview');
        Route::post('/imports/commit', [SupportLibraryController::class, 'commit'])->name('imports.commit');
    });
    Route::middleware('permission:support-library.export')->group(function (): void {
        Route::post('/exports/result', [SupportLibraryController::class, 'exportResult'])->name('exports.result');
        Route::get('/exports/library', [SupportLibraryController::class, 'exportLibrary'])->name('exports.library');
        Route::get('/exports/html', [SupportLibraryController::class, 'exportHtml'])->name('exports.html');
        Route::get('/template', [SupportLibraryController::class, 'template'])->name('template');
    });
});
