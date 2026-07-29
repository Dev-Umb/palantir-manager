<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiRunController;
use App\Http\Controllers\AiWriteProposalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OntologyController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\RelationOptionsController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\ShopFloorController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->middleware('throttle:login')->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->middleware('throttle:registration')->name('register.store');
});

Route::get('/purchase-request', [RequisitionController::class, 'publicCreate'])->name('requisitions.public.create');
Route::get('/purchase-request/material-options', [RequisitionController::class, 'publicMaterialOptions'])
    ->middleware('throttle:public-requisition-search')
    ->name('requisitions.public.material-options');
Route::post('/purchase-request', [RequisitionController::class, 'publicStore'])
    ->middleware('throttle:public-requisition')
    ->name('requisitions.public.store');
Route::get('/team-log/public', [ShopFloorController::class, 'publicTeamLogCreate'])
    ->middleware(['signed', 'throttle:public-team-log-view'])
    ->name('team-logs.public.create');
Route::post('/team-log/public', [ShopFloorController::class, 'publicTeamLogStore'])
    ->middleware(['signed', 'throttle:public-team-log'])
    ->name('team-logs.public.store');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::middleware('permission:ai.harness.view')->group(function () {
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::post('/ai/runs', [AiRunController::class, 'store'])->middleware('throttle:ai-post')->name('ai.runs.store');
        Route::get('/ai/runs/{run}', [AiRunController::class, 'show'])->name('ai.runs.show');
        Route::get('/ai/runs/{run}/events', [AiRunController::class, 'events'])->name('ai.runs.events');
        Route::post('/ai/runs/{run}/cancel', [AiRunController::class, 'cancel'])->middleware('throttle:ai-post')->name('ai.runs.cancel');
        Route::post('/ai/runs/{run}/proposals/{proposal}/confirm', [AiWriteProposalController::class, 'confirm'])
            ->middleware('throttle:ai-post')
            ->name('ai.proposals.confirm');
        Route::post('/ai/runs/{run}/proposals/{proposal}/reject', [AiWriteProposalController::class, 'reject'])
            ->middleware('throttle:ai-post')
            ->name('ai.proposals.reject');
        Route::post('/ai/messages', [AiController::class, 'messages'])->middleware('throttle:ai-post')->name('ai.messages');
        Route::get('/ai/conversations/{conversation}', [AiController::class, 'show'])->name('ai.conversations.show');
    });
    Route::get('/attachments/{record}/{field}', AttachmentController::class)->name('attachments.download');

    Route::get('/requests/create', [RequisitionController::class, 'create'])
        ->middleware('permission:requisition.create')
        ->name('requisitions.create');
    Route::post('/requests', [RequisitionController::class, 'store'])
        ->middleware('permission:requisition.create')
        ->name('requisitions.store');
    Route::get('/procurement/approvals', [RequisitionController::class, 'approvals'])
        ->middleware('permission:object.requisition.update')
        ->name('requisitions.approvals');
    Route::post('/requests/{record}/approve', [RequisitionController::class, 'approve'])
        ->middleware('permission:object.requisition.update')
        ->name('requisitions.approve');
    Route::post('/requests/{record}/reject', [RequisitionController::class, 'reject'])
        ->middleware('permission:object.requisition.update')
        ->name('requisitions.reject');
    Route::get('/team-log', [ShopFloorController::class, 'teamLogCreate'])
        ->middleware('permission:object.team_log.view')
        ->name('team-logs.create');
    Route::post('/team-log', [ShopFloorController::class, 'teamLogStore'])
        ->middleware('permission:object.team_log.create')
        ->name('team-logs.store');
    Route::get('/relation-options', RelationOptionsController::class)->name('relation-options.index');
    Route::get('/objects/{object}/export.csv', [OntologyController::class, 'exportCsv'])->name('objects.export');
    Route::get('/objects/{object?}', [OntologyController::class, 'index'])->name('objects.index');
    Route::post('/objects/{object}', [OntologyController::class, 'store'])->name('objects.store');
    Route::put('/records/{record}', [OntologyController::class, 'update'])->name('records.update');
    Route::delete('/records/{record}', [OntologyController::class, 'destroy'])->name('records.destroy');

    Route::middleware('permission:rbac.manage')->group(function () {
        Route::get('/admin/rbac', [RbacController::class, 'index'])->name('rbac.index');
        Route::put('/admin/users/{user}/roles', [RbacController::class, 'updateUserRoles'])->name('rbac.users.roles');
        Route::put('/admin/roles/{role}/permissions', [RbacController::class, 'updateRolePermissions'])->name('rbac.roles.permissions');
    });
});
