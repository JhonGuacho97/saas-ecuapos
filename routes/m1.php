<?php

use App\Http\Controllers\API\M1\AuthController;
use App\Http\Controllers\API\M1\BrandAPIController;
use App\Http\Controllers\API\M1\CustomerAPIController;
use App\Http\Controllers\API\M1\DashboardAPIController;
use App\Http\Controllers\API\M1\HoldAPIController;
use App\Http\Controllers\API\M1\ProductAPIController;
use App\Http\Controllers\API\M1\ProductCategoryAPIController;
use App\Http\Controllers\API\M1\ReportAPIController;
use App\Http\Controllers\API\M1\SaleAPIController;
use App\Http\Controllers\API\M1\UserAPIController;
use App\Http\Controllers\API\M1\WarehouseAPIController;
use Illuminate\Support\Facades\Route;

Route::prefix('m1')->as('m1.')->group(function () {
    // login
    Route::post('login', [AuthController::class, 'login'])->middleware('throttle:5,1');
    Route::post(
        '/forgot-password',
        [AuthController::class, 'sendPasswordResetLinkEmail']
    )->middleware('throttle:5,1');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

    Route::middleware(['auth:sanctum', 'store.context', 'subscription.active'])->group(function () {
        // dashboard
        Route::middleware('permission:manage_dashboard')->group(function () {
            Route::get('dashboard', [DashboardAPIController::class, 'index']);
            Route::get('week-selling-purchases', [DashboardAPIController::class, 'getWeekSalePurchases']);
            Route::get('top-selling-products', [DashboardAPIController::class, 'getTopSellingProducts']);
            Route::get('yearly-top-selling', [DashboardAPIController::class, 'getYearlyTopSelling']);
            Route::get('top-customers', [DashboardAPIController::class, 'getTopCustomer']);
            Route::get('recent-sales', [DashboardAPIController::class, 'getRecentSales']);
            Route::get('stock-alerts', [DashboardAPIController::class, 'stockAlerts']);
            Route::get('today-sales-overall-report', [ReportAPIController::class, 'getTodaySalesOverallReport']);
        });
        // profile
        Route::get('edit-profile', [UserAPIController::class, 'editProfile']);
        Route::post('update-profile', [UserAPIController::class, 'updateProfile']);
        Route::post('change-password', [UserAPIController::class, 'changePassword']);
        // Language Change
        Route::get('languages', [UserAPIController::class, 'languages']);
        Route::post('change-language', [UserAPIController::class, 'updateLanguage']);
        // POS
        Route::middleware('permission:manage_customers|manage_pos_screen')->group(function () {
            Route::resource('customers', CustomerAPIController::class);
        });
        Route::middleware('permission:manage_pos_screen')->group(function () {
            Route::get('warehouses', [WarehouseAPIController::class, 'index']);
            Route::get('product-categories', [ProductCategoryAPIController::class, 'index']);
            Route::get('brands', [BrandAPIController::class, 'index']);
            Route::resource('holds', HoldAPIController::class);
            Route::resource('products', ProductAPIController::class);
            Route::get('get-product-by-category/{id}', [ProductAPIController::class, 'getProductByCategory']);
            Route::get('get-product-by-brand/{id}', [ProductAPIController::class, 'getProductByBrand']);
            Route::post('get-product-by-brand-and-category', [ProductAPIController::class, 'getProductByBrandAndCategory']);
        });
        // Logout
        Route::post('logout', [AuthController::class, 'logout']);
        // Sales
        Route::resource('sales', SaleAPIController::class)
            ->middleware('permission:manage_sale|manage_pos_screen');
    });
});
