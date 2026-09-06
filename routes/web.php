<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CatalogPageController;
use App\Http\Controllers\CatalogCustomerAuthController;
use App\Http\Controllers\API\PublicCatalogController;
use App\Http\Controllers\LandingPageController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| contains the "web" middleware group. Now create something great!
|
*/

Route::get('/', LandingPageController::class)->name('landing');
Route::view('/sistema', 'welcome')->name('app');

Route::get('/catalogo/{store:slug}', CatalogPageController::class)->name('catalog.show');
Route::prefix('/catalogo/{store:slug}/cuenta')->group(function () {
    Route::get('/sesion', [CatalogCustomerAuthController::class, 'session'])->name('catalog.account.session');
    Route::post('/registro', [CatalogCustomerAuthController::class, 'register'])
        ->middleware('throttle:5,1')->name('catalog.account.register');
    Route::post('/iniciar-sesion', [CatalogCustomerAuthController::class, 'login'])
        ->middleware('throttle:8,1')->name('catalog.account.login');
    Route::post('/recuperar-contrasena', [CatalogCustomerAuthController::class, 'requestPasswordReset'])
        ->middleware('throttle:3,1')->name('catalog.account.password.email');
    Route::post('/restablecer-contrasena', [CatalogCustomerAuthController::class, 'resetPassword'])
        ->middleware('throttle:5,1')->name('catalog.account.password.reset');
    Route::post('/cerrar-sesion', [CatalogCustomerAuthController::class, 'logout'])
        ->middleware('throttle:20,1')->name('catalog.account.logout');
    Route::get('/pedidos', [CatalogCustomerAuthController::class, 'orders'])
        ->middleware('throttle:60,1')->name('catalog.account.orders');
    Route::get('/pedidos/{catalogOrder}', [CatalogCustomerAuthController::class, 'order'])
        ->middleware('throttle:60,1')->name('catalog.account.orders.show');
});
Route::post('/catalogo/{store:slug}/pedidos', [PublicCatalogController::class, 'storeOrder'])
    ->middleware(['auth:catalog_customer', 'throttle:15,1'])->name('catalog.orders.store');

include 'upgrade.php';
