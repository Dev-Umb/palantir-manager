<?php

use App\Http\Controllers\TemplateAccessoryController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'permission:template-accessories.view'])->prefix('template-accessories')->name('template-accessories.')->group(function (): void {
    Route::get('/', [TemplateAccessoryController::class, 'index'])->name('index');
    Route::get('/workbench', [TemplateAccessoryController::class, 'workbench'])->name('workbench');
    Route::get('/state', [TemplateAccessoryController::class, 'state'])->name('state');
    Route::post('/mutate', [TemplateAccessoryController::class, 'mutate'])->name('mutate');
    Route::put('/preferences', [TemplateAccessoryController::class, 'preferences'])->name('preferences');
    Route::get('/export', [TemplateAccessoryController::class, 'export'])->middleware('permission:template-accessories.export')->name('export');
});
