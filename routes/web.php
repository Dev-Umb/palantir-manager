<?php

use App\Http\Controllers\AccountSettingsController;
use App\Http\Controllers\AiContractIntakeController;
use App\Http\Controllers\AiController;
use App\Http\Controllers\AiRunController;
use App\Http\Controllers\AiWriteProposalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AttachmentPreviewController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FeishuEventController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OntologyController;
use App\Http\Controllers\ProjectContractAmountController;
use App\Http\Controllers\ProjectCustomerController;
use App\Http\Controllers\ProjectCustomerProfilePreviewController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\RelationOptionsController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\ShopFloorController;
use App\Http\Controllers\TenderConversionController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/feishu/events', FeishuEventController::class)
    ->middleware('throttle:feishu-webhook')
    ->name('webhooks.feishu.events');

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
    Route::get('/settings', [AccountSettingsController::class, 'index'])->name('settings.index');
    Route::put('/settings/email', [AccountSettingsController::class, 'updateEmail'])->middleware('throttle:6,1')->name('settings.email');
    Route::put('/settings/password', [AccountSettingsController::class, 'updatePassword'])->middleware('throttle:6,1')->name('settings.password');

    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::patch('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');
    Route::patch('/tender-notifications/{notification}/read', [NotificationController::class, 'markTenderRead'])
        ->name('tender-notifications.read');
    Route::middleware('permission:ai.harness.view')->group(function () {
        Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
        Route::get('/ai/contracts', [AiContractIntakeController::class, 'index'])->name('ai.contracts.index');
        Route::get('/ai/contracts/projects', [AiContractIntakeController::class, 'projects'])->name('ai.contracts.projects');
        Route::get('/ai/contracts/projects/{project}', [AiContractIntakeController::class, 'project'])->name('ai.contracts.project');
        Route::post('/ai/contracts', [AiContractIntakeController::class, 'store'])->middleware('throttle:ai-post')->name('ai.contracts.store');
        Route::get('/ai/contracts/{intake}', [AiContractIntakeController::class, 'show'])->name('ai.contracts.show');
        Route::post('/ai/contracts/{intake}/retry', [AiContractIntakeController::class, 'retry'])->middleware('throttle:ai-post')->name('ai.contracts.retry');
        Route::post('/ai/contracts/{intake}/preview', [AiContractIntakeController::class, 'preview'])->middleware('throttle:ai-post')->name('ai.contracts.preview');
        Route::post('/ai/contracts/{intake}/confirm', [AiContractIntakeController::class, 'confirm'])->middleware('throttle:ai-post')->name('ai.contracts.confirm');
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
    Route::get('/attachment-previews/{record}/{field}/{index?}', [AttachmentPreviewController::class, 'show'])
        ->whereNumber('index')->name('attachments.preview');
    Route::get('/attachment-content/{record}/{field}/{index?}', [AttachmentPreviewController::class, 'content'])
        ->whereNumber('index')->name('attachments.content');
    Route::get('/attachments/{record}/{field}/{index?}', AttachmentController::class)
        ->whereNumber('index')
        ->name('attachments.download');

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
    Route::post('/projects/{project}/contract-amount/sync', ProjectContractAmountController::class)
        ->name('projects.contract-amount.sync');
    Route::get('/project-customers/{customer}', [ProjectCustomerController::class, 'show'])->name('project-customers.show');
    Route::post('/project-customers', [ProjectCustomerController::class, 'store'])->name('project-customers.store');
    Route::post('/project-customer-profile/preview', ProjectCustomerProfilePreviewController::class)
        ->name('project-customer-profile.preview');
    Route::put('/project-customers/{customer}', [ProjectCustomerController::class, 'update'])->name('project-customers.update');
    Route::post('/project-customers/{customer}/contacts', [ProjectCustomerController::class, 'storeContact'])->name('project-customers.contacts.store');
    Route::put('/project-customers/{customer}/contacts/{contact}', [ProjectCustomerController::class, 'updateContact'])->name('project-customers.contacts.update');
    Route::get('/objects/{object}/export.csv', [OntologyController::class, 'exportCsv'])->name('objects.export');
    Route::get('/objects/{object?}', [OntologyController::class, 'index'])->name('objects.index');
    Route::post('/objects/{object}', [OntologyController::class, 'store'])->name('objects.store');
    Route::put('/records/{record}', [OntologyController::class, 'update'])->name('records.update');
    Route::delete('/records/{record}', [OntologyController::class, 'destroy'])->name('records.destroy');
    Route::post('/records/{record}/convert-to-project', TenderConversionController::class)
        ->name('tenders.convert');

    Route::middleware('permission:rbac.manage')->group(function () {
        Route::put('/admin/users/{user}/password', [RbacController::class, 'resetPassword'])->middleware('throttle:6,1')->name('rbac.users.password');
        Route::get('/admin/rbac', [RbacController::class, 'index'])->name('rbac.index');
        Route::put('/admin/users/{user}/roles', [RbacController::class, 'updateUserRoles'])->name('rbac.users.roles');
        Route::delete('/admin/users/{user}', [RbacController::class, 'destroyUser'])->name('rbac.users.destroy');
        Route::put('/admin/roles/{role}/permissions', [RbacController::class, 'updateRolePermissions'])->name('rbac.roles.permissions');
    });
});

require __DIR__.'/procurement_hub.php';
