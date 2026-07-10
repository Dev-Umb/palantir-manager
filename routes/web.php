<?php

use App\Http\Controllers\AiController;
use App\Http\Controllers\AiRunController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\OntologyController;
use App\Http\Controllers\RbacController;
use App\Http\Controllers\RequisitionController;
use App\Http\Controllers\ShopFloorController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'login'])->name('login');
    Route::post('/login', [AuthController::class, 'authenticate'])->name('login.store');
    Route::get('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/register', [AuthController::class, 'store'])->name('register.store');
});

Route::get('/purchase-request', [RequisitionController::class, 'publicCreate'])->name('requisitions.public.create');
Route::post('/purchase-request', [RequisitionController::class, 'publicStore'])->name('requisitions.public.store');
Route::get('/material-request', [ShopFloorController::class, 'materialRequestCreate'])->name('material-requests.public.create');
Route::post('/material-request', [ShopFloorController::class, 'materialRequestStore'])->name('material-requests.public.store');
Route::get('/team-log', [ShopFloorController::class, 'teamLogCreate'])->name('team-logs.public.create');
Route::post('/team-log', [ShopFloorController::class, 'teamLogStore'])->name('team-logs.public.store');

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/ai', [AiController::class, 'index'])->name('ai.index');
    Route::post('/ai/runs', [AiRunController::class, 'store'])->name('ai.runs.store');
    Route::get('/ai/runs/{run}', [AiRunController::class, 'show'])->name('ai.runs.show');
    Route::get('/ai/runs/{run}/events', [AiRunController::class, 'events'])->name('ai.runs.events');
    Route::post('/ai/runs/{run}/cancel', [AiRunController::class, 'cancel'])->name('ai.runs.cancel');
    Route::post('/ai/messages', [AiController::class, 'messages'])->name('ai.messages');
    Route::get('/ai/conversations/{conversation}', [AiController::class, 'show'])->name('ai.conversations.show');

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
    Route::get('/warehouse/material-requests', [ShopFloorController::class, 'materialRequestApprovals'])
        ->middleware('permission:object.material_request.update')
        ->name('material-requests.approvals');
    Route::post('/material-requests/{record}/approve', [ShopFloorController::class, 'materialRequestApprove'])
        ->middleware('permission:object.material_request.update')
        ->name('material-requests.approve');
    Route::post('/material-requests/{record}/reject', [ShopFloorController::class, 'materialRequestReject'])
        ->middleware('permission:object.material_request.update')
        ->name('material-requests.reject');

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
