<?php

use Illuminate\Support\Facades\Route;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Controllers\Admin\UserManagement\AdminUserController;
use App\Http\Controllers\Admin\UserManagement\AdminUserAccessController;

Route::prefix('admin')->middleware(AuthenticateAdmin::class)->group(function () {
    Route::get('users', [AdminUserController::class, 'index']);
    Route::post('users', [AdminUserController::class, 'store']);
    Route::get('admins-list', [AdminUserController::class, 'getAdmins']);
    Route::get('user', [AdminUserController::class, 'index']);
    Route::get('user/{id}', [AdminUserController::class, 'show']);
    Route::match(['patch', 'post'], '/user/{id}', [AdminUserController::class, 'update']);
    Route::post('users/{id}/add-credit', [AdminUserController::class, 'addCredit']);
    Route::patch('users/{id}/toggle-active', [AdminUserController::class, 'toggleActive']);
    Route::patch('users/{id}/toggle-block', [AdminUserController::class, 'toggleBlock']);
    Route::patch('users/{id}/reset-password', [AdminUserController::class, 'resetPassword']);
    Route::patch('users/{id}/verify-email', [AdminUserController::class, 'verifyEmail']);
    Route::patch('users/{id}/update-notes', [AdminUserController::class, 'updateNotes']);
    Route::post('users/bulk-action', [AdminUserController::class, 'bulkAction']);
    Route::delete('users/{id}', [AdminUserController::class, 'destroy']);

    // Dedicated User Access & 2FA Management
    Route::post('users/{id}/impersonate', [AdminUserAccessController::class, 'impersonate']);
    Route::get('users/{id}/2fa', [AdminUserAccessController::class, 'getTwoFactor']);
    Route::post('users/{id}/2fa/generate', [AdminUserAccessController::class, 'generateTwoFactor']);
    Route::post('users/{id}/2fa/disable', [AdminUserAccessController::class, 'disableTwoFactor']);
});
