<?php

use App\Http\Controllers\ReimbursementController;
use App\Http\Controllers\ReimbursementDraftController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'reimbursement-access'])->prefix('reimbursements')->name('reimbursements.')->group(function (): void {
    Route::get('/', [ReimbursementController::class, 'index'])->name('index');
    Route::get('/drafts', [ReimbursementDraftController::class, 'index'])->name('drafts.index');
    Route::get('/drafts/{draft}', [ReimbursementDraftController::class, 'show'])->whereUuid('draft')->name('drafts.show');
    Route::put('/drafts/{draft}', [ReimbursementDraftController::class, 'update'])->whereUuid('draft')->middleware('throttle:120,1')->name('drafts.update');
    Route::get('/drafts/{draft}/files/{upload}', [ReimbursementDraftController::class, 'file'])->whereUuid('draft')->name('drafts.file');
    Route::get('/options', [ReimbursementController::class, 'options'])->name('options');
    Route::post('/', [ReimbursementController::class, 'submit'])->middleware('throttle:30,1')->name('submit');
    Route::post('/uploads', [ReimbursementController::class, 'upload'])->middleware('throttle:30,1')->name('upload');
    Route::post('/uploads/{upload}/recognize', [ReimbursementController::class, 'recognizeInvoice'])->middleware('throttle:12,1')->name('uploads.recognize');
    Route::get('/workflow', [ReimbursementController::class, 'workflow'])->name('workflow');
    Route::put('/workflow', [ReimbursementController::class, 'saveWorkflow'])->name('workflow.save');
    Route::get('/notices', [ReimbursementController::class, 'notices'])->name('notices');
    Route::patch('/notices/{notice}/read', [ReimbursementController::class, 'readNotice'])->name('notices.read');
    Route::get('/versions/{version}', [ReimbursementController::class, 'show'])->name('show');
    Route::post('/versions/{version}/decision', [ReimbursementController::class, 'decide'])->name('decide');
    Route::get('/versions/{version}/invoices.zip', [ReimbursementController::class, 'zip'])->name('zip');
    Route::get('/versions/{version}/files/{upload}', [ReimbursementController::class, 'file'])->name('file');
});
