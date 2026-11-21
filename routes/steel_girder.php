<?php

use App\Http\Controllers\SteelDemoController;
use App\Http\Controllers\SteelGirderProjectController as Projects;
use Illuminate\Support\Facades\Route;

Route::get('/s/{site}', [SteelDemoController::class, 'visit'])->whereUuid('site')->name('steel-demo.visit');

Route::middleware(['auth', 'permission:steel-girder-projects.view'])->prefix('steel-girder-projects')->name('steel-girder-projects.')->group(function (): void {
    Route::get('/', [Projects::class, 'index'])->name('index');
    Route::get('/workspace', [Projects::class, 'workspace'])->name('workspace');
    Route::get('/projects', [Projects::class, 'list'])->name('list');
    Route::get('/projects/{project}', [Projects::class, 'show'])->name('show');
    Route::get('/projects/{project}/drawings', [Projects::class, 'drawings'])->name('drawings');
    Route::middleware('permission:steel-girder-projects.manage')->group(function (): void {
        Route::get('/demos', [SteelDemoController::class, 'index'])->name('demos.index');
        Route::get('/demos/{site}', [SteelDemoController::class, 'show'])->whereUuid('site')->name('demos.show');
        Route::post('/demos/{id}/promote', [SteelDemoController::class, 'promote'])->whereUuid('id')->name('demos.promote');
        Route::post('/demos', [SteelDemoController::class, 'store'])->name('demos.store');
        Route::delete('/demos/{id}', [SteelDemoController::class, 'destroy'])->whereUuid('id')->name('demos.destroy');
        Route::post('/projects', [Projects::class, 'create'])->name('create');
        Route::delete('/projects/{project}', [Projects::class, 'destroy'])->name('destroy');
        Route::post('/projects/{project}/material', [Projects::class, 'material'])->name('material');
        Route::post('/projects/{project}/drawings', [Projects::class, 'upload'])->name('drawing.upload');
        Route::delete('/projects/{project}/drawings/{drawing}', [Projects::class, 'removeDrawing'])->name('drawing.remove');
    });
    Route::middleware('permission:steel-girder-projects.export')->group(function (): void {
        Route::get('/projects/{project}/original', [Projects::class, 'original'])->name('original');
        Route::get('/projects/{project}/drawings.zip', [Projects::class, 'exportDrawings'])->name('drawings.export');
        Route::get('/projects/{project}/drawings/{drawing}', [Projects::class, 'downloadDrawing'])->name('drawing.download');
    });
});
