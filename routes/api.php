<?php

use App\Http\Controllers\Api\V1\ActivityLogsController;
use App\Http\Controllers\Api\V1\AuthCheckController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CategoryRequestController;
use App\Http\Controllers\Api\V1\EmailTestController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\LocationController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OtpPasswordResetController;
use App\Http\Controllers\Api\V1\PayoutDestinationController;
use App\Http\Controllers\Api\V1\PlatformSettingsController;
use App\Http\Controllers\Api\V1\ProductAttributeController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductPhotoController;
use App\Http\Controllers\Api\V1\RolesAndPermissionsController;
use App\Http\Controllers\Api\V1\ShopController;
use App\Http\Controllers\Api\V1\ShopReviewController;
use App\Http\Controllers\Api\V1\ShopSectionController;
use App\Http\Controllers\Api\V1\StockController;
use App\Http\Controllers\Api\V1\UnitController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\VendorApplicationController;
use App\Http\Controllers\Api\V1\VendorController;
use App\Http\Controllers\Api\V1\VendorDocumentController;
use App\Http\Controllers\Api\V1\VendorMemberController;
use App\Http\Controllers\Api\V1\VendorReviewController;
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

        // "Sell on Kheengz": a new account and a Pending vendor (docs/vendors-and-shops.md).
        Route::post('vendor-applications', [VendorApplicationController::class, 'store'])->name('vendor-applications.store');
    });
    // A new code at most every 20 seconds or so per address, so the inbox is not flooded.
    Route::post('auth/email/resend', [EmailVerificationController::class, 'resend'])->middleware('throttle:3,1')->name('auth.email.resend');

    // ── Signed in ───────────────────────────────────────────────────────────
    Route::middleware(['auth:sanctum', 'throttle:240,1'])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('auth/profile', [AuthCheckController::class, 'profile'])->name('auth.profile');
        Route::post('auth/profile', [AuthCheckController::class, 'updateProfile'])->name('auth.profile.update');
        Route::get('auth/check-app-status', [AuthCheckController::class, 'appStatus'])->name('auth.check-app-status');

        // The bell: always the caller's own notices, so no permission to hold.
        Route::prefix('notifications')->name('notifications.')->group(function () {
            Route::get('/', [NotificationController::class, 'index'])->name('index');
            Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
            Route::post('read-all', [NotificationController::class, 'markAllRead'])->name('read-all');
            Route::post('{id}/read', [NotificationController::class, 'markRead'])->name('read');
            Route::delete('{id}', [NotificationController::class, 'destroy'])->name('destroy');
        });

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

        // Queue Jobs
        Route::prefix('jobs')->name('jobs.')->group(function () {
            Route::middleware('can:view queue')->group(function () {
                Route::get('/', [JobController::class, 'getQueuedJobs'])->name('queued');
                Route::get('stats', [JobController::class, 'getJobStats'])->name('stats');
                Route::get('failed', [JobController::class, 'getFailedJobs'])->name('failed');
            });

            Route::middleware('can:manage queue')->group(function () {
                Route::delete('bulk/delete', [JobController::class, 'bulkDestroyJobs'])->name('bulk-destroy');
                Route::delete('failed/bulk/delete', [JobController::class, 'bulkDestroyFailedJobs'])->name('failed.bulk-destroy');
            });
        });

        // ── Vendors and shops (D31, D35; docs/vendors-and-shops.md) ─────────────
        // Reviewers (`view vendors`, `manage vendors`) reach every vendor and shop; a vendor's
        // people (`run shops`) only their own. The `can:` names the policy; the policy decides whose.
        Route::get('vendors', [VendorController::class, 'index'])->middleware('can:viewAny,App\Models\Vendor')->name('vendors.index');
        Route::get('vendors/{vendor}', [VendorController::class, 'show'])->middleware('can:view,vendor')->name('vendors.show');
        Route::put('vendors/{vendor}', [VendorController::class, 'update'])->middleware('can:own,vendor')->name('vendors.update');
        Route::post('vendors/{vendor}/resubmit', [VendorController::class, 'resubmit'])->middleware('can:own,vendor')->name('vendors.resubmit');

        Route::middleware('can:review,vendor')->group(function () {
            Route::post('vendors/{vendor}/approve', [VendorReviewController::class, 'approve'])->name('vendors.approve');
            Route::post('vendors/{vendor}/reject', [VendorReviewController::class, 'reject'])->name('vendors.reject');
            Route::post('vendors/{vendor}/suspend', [VendorReviewController::class, 'suspend'])->name('vendors.suspend');
            Route::post('vendors/{vendor}/reinstate', [VendorReviewController::class, 'reinstate'])->name('vendors.reinstate');
        });

        Route::post('vendors/{vendor}/documents', [VendorDocumentController::class, 'store'])->middleware('can:own,vendor')->name('vendor-documents.store');
        // Who may delete or read a paper depends on its vendor: decided in the controller.
        Route::delete('vendor-documents/{document}', [VendorDocumentController::class, 'destroy'])->middleware('can:run shops')->name('vendor-documents.destroy');
        Route::get('vendor-documents/{document}/file', [VendorDocumentController::class, 'file'])->name('vendor-documents.file');
        Route::post('vendor-documents/{document}/check', [VendorDocumentController::class, 'check'])->middleware('can:manage vendors')->name('vendor-documents.check');

        // Where a vendor's money goes (D12): Owners add and remove, reviewers decide on the ones waiting.
        Route::get('payout-banks', [PayoutDestinationController::class, 'banks'])->middleware('can:run shops')->name('payout-banks.index');
        Route::get('vendors/{vendor}/payout-destinations', [PayoutDestinationController::class, 'index'])->middleware('can:view,vendor')->name('payout-destinations.index');
        Route::post('vendors/{vendor}/payout-destinations', [PayoutDestinationController::class, 'store'])->middleware('can:own,vendor')->name('payout-destinations.store');
        Route::delete('payout-destinations/{destination}', [PayoutDestinationController::class, 'destroy'])->middleware('can:run shops')->name('payout-destinations.destroy');
        Route::middleware('can:manage vendors')->group(function () {
            Route::post('payout-destinations/{destination}/approve', [PayoutDestinationController::class, 'approve'])->name('payout-destinations.approve');
            Route::post('payout-destinations/{destination}/reject', [PayoutDestinationController::class, 'reject'])->name('payout-destinations.reject');
        });

        Route::get('vendors/{vendor}/members', [VendorMemberController::class, 'index'])->middleware('can:view,vendor')->name('vendor-members.index');
        Route::middleware('can:own,vendor')->group(function () {
            Route::post('vendors/{vendor}/members', [VendorMemberController::class, 'store'])->name('vendor-members.store');
            Route::put('vendors/{vendor}/members/{user}', [VendorMemberController::class, 'update'])->name('vendor-members.update');
            Route::delete('vendors/{vendor}/members/{user}', [VendorMemberController::class, 'destroy'])->name('vendor-members.destroy');
        });

        Route::get('shops', [ShopController::class, 'index'])->middleware('can:viewAny,App\Models\Shop')->name('shops.index');
        Route::get('shops/slug-availability', [ShopController::class, 'slugAvailability'])->middleware('can:run shops')->name('shops.slug-availability');
        Route::post('vendors/{vendor}/shops', [ShopController::class, 'store'])->middleware('can:own,vendor')->name('shops.store');
        Route::get('shops/{shop}', [ShopController::class, 'show'])->middleware('can:view,shop')->name('shops.show');
        Route::put('shops/{shop}', [ShopController::class, 'update'])->middleware('can:update,shop')->name('shops.update');
        Route::post('shops/{shop}/submit', [ShopController::class, 'submit'])->middleware('can:own,shop')->name('shops.submit');
        Route::post('shops/{shop}/close', [ShopController::class, 'close'])->middleware('can:own,shop')->name('shops.close');

        Route::middleware('can:review,shop')->group(function () {
            Route::post('shops/{shop}/approve', [ShopReviewController::class, 'approve'])->name('shops.approve');
            Route::post('shops/{shop}/request-changes', [ShopReviewController::class, 'requestChanges'])->name('shops.request-changes');
            Route::post('shops/{shop}/reject', [ShopReviewController::class, 'reject'])->name('shops.reject');
            Route::post('shops/{shop}/suspend', [ShopReviewController::class, 'suspend'])->name('shops.suspend');
            Route::post('shops/{shop}/reinstate', [ShopReviewController::class, 'reinstate'])->name('shops.reinstate');
        });

        Route::get('shops/{shop}/locations', [LocationController::class, 'index'])->middleware('can:view,shop')->name('locations.index');
        Route::middleware('can:update,shop')->group(function () {
            Route::post('shops/{shop}/locations', [LocationController::class, 'store'])->name('locations.store');
            Route::put('shops/{shop}/locations/{location}', [LocationController::class, 'update'])->name('locations.update');
            Route::delete('shops/{shop}/locations/{location}', [LocationController::class, 'destroy'])->name('locations.destroy');
        });

        // ── Catalogue (D32; docs/catalogue.md) ──────────────────────────────────
        // Categories, attributes and units are read by everyone who places products and kept by
        // `manage catalogue`. The tree and the units come whole (not paged): they are short and pickers
        // need all of them.
        Route::middleware('can:viewAny,App\Models\Category')->group(function () {
            Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
            Route::get('categories/{category}', [CategoryController::class, 'show'])->name('categories.show');
            Route::get('product-attributes', [ProductAttributeController::class, 'index'])->name('product-attributes.index');
            Route::get('units', [UnitController::class, 'index'])->name('units.index');
            // The Admin sees every request; a vendor's people their shops' (decided in the controller).
            Route::get('category-requests', [CategoryRequestController::class, 'index'])->name('category-requests.index');
        });
        Route::middleware('can:manage catalogue')->group(function () {
            Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
            Route::put('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
            Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');
            Route::put('categories/{category}/attributes', [CategoryController::class, 'syncAttributes'])->name('categories.attributes');
            Route::post('product-attributes', [ProductAttributeController::class, 'store'])->name('product-attributes.store');
            Route::put('product-attributes/{attribute}', [ProductAttributeController::class, 'update'])->name('product-attributes.update');
            Route::delete('product-attributes/{attribute}', [ProductAttributeController::class, 'destroy'])->name('product-attributes.destroy');
            Route::post('units', [UnitController::class, 'store'])->name('units.store');
            Route::put('units/{unit}', [UnitController::class, 'update'])->name('units.update');
            Route::delete('units/{unit}', [UnitController::class, 'destroy'])->name('units.destroy');
            Route::post('category-requests/{categoryRequest}/add', [CategoryRequestController::class, 'add'])->name('category-requests.add');
            Route::post('category-requests/{categoryRequest}/decline', [CategoryRequestController::class, 'decline'])->name('category-requests.decline');
        });
        Route::post('shops/{shop}/category-requests', [CategoryRequestController::class, 'store'])->middleware('can:manageCatalogue,shop')->name('category-requests.store');

        // ── A shop's sections and products ──────────────────────────────────────
        // Seen by whoever may see the shop (reviewers too); changed by whoever runs it while its
        // status allows (ShopPolicy::manageCatalogue, ProductPolicy).
        Route::get('shops/{shop}/sections', [ShopSectionController::class, 'index'])->middleware('can:view,shop')->name('shop-sections.index');
        Route::middleware('can:manageCatalogue,shop')->scopeBindings()->group(function () {
            Route::post('shops/{shop}/sections', [ShopSectionController::class, 'store'])->name('shop-sections.store');
            Route::put('shops/{shop}/sections/order', [ShopSectionController::class, 'reorder'])->name('shop-sections.reorder');
            Route::put('shops/{shop}/sections/{section}', [ShopSectionController::class, 'update'])->name('shop-sections.update');
            Route::delete('shops/{shop}/sections/{section}', [ShopSectionController::class, 'destroy'])->name('shop-sections.destroy');
            Route::post('shops/{shop}/products', [ProductController::class, 'store'])->name('products.store');
        });
        Route::get('shops/{shop}/products', [ProductController::class, 'index'])->middleware('can:view,shop')->name('products.index');
        Route::get('products/{product}', [ProductController::class, 'show'])->middleware('can:view,product')->name('products.show');
        Route::middleware('can:update,product')->scopeBindings()->group(function () {
            Route::put('products/{product}', [ProductController::class, 'update'])->name('products.update');
            Route::delete('products/{product}', [ProductController::class, 'destroy'])->name('products.destroy');
            Route::post('products/{product}/publish', [ProductController::class, 'publish'])->name('products.publish');
            Route::post('products/{product}/hide', [ProductController::class, 'hide'])->name('products.hide');
            Route::post('products/{product}/photos', [ProductPhotoController::class, 'store'])->name('product-photos.store');
            Route::put('products/{product}/photos/order', [ProductPhotoController::class, 'reorder'])->name('product-photos.reorder');
            Route::put('products/{product}/photos/{photo}', [ProductPhotoController::class, 'update'])->name('product-photos.update');
            Route::delete('products/{product}/photos/{photo}', [ProductPhotoController::class, 'destroy'])->name('product-photos.destroy');
        });
        // ── Stock (D5, D33; docs/stock.md) ──────────────────────────────────────
        Route::get('products/{product}/stock', [StockController::class, 'show'])->middleware('can:view,product')->name('stock.show');
        Route::get('products/{product}/stock/movements', [StockController::class, 'movements'])->middleware('can:view,product')->name('stock.movements');
        Route::post('products/{product}/stock', [StockController::class, 'store'])->middleware('can:update,product')->name('stock.store');
        Route::get('shops/{shop}/stock', [StockController::class, 'shop'])->middleware('can:view,shop')->name('stock.shop');

        // Kheengz takes a product down, or allows it again (D35).
        Route::middleware('can:moderate,product')->group(function () {
            Route::post('products/{product}/block', [ProductController::class, 'block'])->name('products.block');
            Route::post('products/{product}/unblock', [ProductController::class, 'unblock'])->name('products.unblock');
        });

        // Platform settings (D23, D28, D34)
        Route::get('platform-settings', [PlatformSettingsController::class, 'show'])->middleware('can:manage settings')->name('platform-settings.show');
        Route::put('platform-settings', [PlatformSettingsController::class, 'update'])->middleware('can:manage settings')->name('platform-settings.update');

        // Activity Logs
        Route::get('activity-logs', [ActivityLogsController::class, 'index'])->middleware('can:view activity logs')->name('activity-logs.index');
        Route::delete('activity-logs/bulk/delete', [ActivityLogsController::class, 'bulkDestroy'])->middleware('can:manage settings')->name('activity-logs.bulk-destroy');
    });
});
