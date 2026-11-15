<?php

use App\Http\Controllers\Api\V1\AuthCheckController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailTestController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\OtpPasswordResetController;
use App\Http\Controllers\Api\V1\RolesAndPermissionsController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Support\Facades\Route;

// The foundation copied from GiftNest (workspace WORK-PLAN Phase 0). Each Phase 1 module (shops,
// products, storefront, orders, payments, payouts) adds its own group, with a `can:` per route.

Route::prefix('v1')->name('api.')->group(function () {

    // ── Sign in, confirm an email, reset a password ─────────────────────────
    // Vendor sign-up (`auth/register`) arrives with the vendors module.
    Route::middleware('throttle:20,1')->group(function () {
        Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');
        Route::post('auth/email/verify', [EmailVerificationController::class, 'verify'])->name('auth.email.verify');
        Route::post('auth/otp/request', [OtpPasswordResetController::class, 'requestOtp'])->name('auth.otp.request');
        Route::post('auth/otp/validate', [OtpPasswordResetController::class, 'validateOtp'])->name('auth.otp.validate');
        Route::post('auth/otp/reset-password', [OtpPasswordResetController::class, 'resetPassword'])->name('auth.otp.reset-password');
    });
    // A new code at most every 20 seconds or so per address, so the inbox is not flooded.
    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1')->name('auth.email.resend');

    // ── Signed in ───────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:240,1'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/profile', [AuthCheckController::class, 'profile'])->name('auth.profile');
        Route::post('auth/profile', [AuthCheckController::class, 'updateProfile'])->name('auth.profile.update');
        Route::get('auth/check-app-status', [AuthCheckController::class, 'appStatus'])->name('auth.check-app-status');

        // Email testing
        Route::post('email-test/send', [EmailTestController::class, 'testEmail'])->middleware('can:manage settings')->name('email-test.send');

        // Roles & Permissions
        Route::prefix('roles-permissions')->name('roles-permissions.')->group(function () {
            Route::get('assignable', [RolesAndPermissionsController::class, 'assignable'])->name('assignable');
            Route::get('names', [RolesAndPermissionsController::class, 'names'])->name('names');

            Route::middleware('can:manage roles')->group(function () {
                Route::get('/', [RolesAndPermissionsController::class, 'index'])->name('index');
                Route::get('with-permissions', [RolesAndPermissionsController::class, 'withPermissions'])->name('with-permissions');
                Route::post('sync-permissions', [RolesAndPermissionsController::class, 'syncPermissions'])->name('sync-permissions');
            });
        });

        // Users
        Route::apiResource('users', UserController::class)->only(['index', 'show'])->middleware('can:view users');
        Route::middleware('can:manage users')->group(function () {
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            // POST with files (multipart cannot PUT in PHP); PUT for plain JSON.
            Route::match(['put', 'post'], 'users/{user}/update', [UserController::class, 'update'])->name('users.update-multipart');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/bulk/delete', [UserController::class, 'bulkDestroy'])->name('users.bulk-destroy');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
            Route::post('users/{user}/verification-code', [UserController::class, 'resendVerification'])->name('users.verification-code');
        });
    });
});
