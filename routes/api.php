<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\RiderController;
use Illuminate\Support\Facades\Route;

/*
| API v1 for the e-commerce website/app (API keys from Settings → API keys)
| and the rider app (staff token). See docs/api-v1.md.
*/

Route::prefix('v1')->group(function () {
    Route::post('/auth/token', [AuthController::class, 'token'])->middleware('throttle:api-auth');

    Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/catalog/categories', [CatalogController::class, 'categories']);
        Route::get('/catalog/products', [CatalogController::class, 'products']);
        Route::get('/catalog/products/{variant}', [CatalogController::class, 'product']);
        Route::get('/stock', [CatalogController::class, 'stock']);

        Route::post('/customers', [OrderController::class, 'customer']);
        Route::post('/orders', [OrderController::class, 'store']);
        Route::get('/orders/{number}', [OrderController::class, 'show']);
        Route::post('/orders/{number}/cancel', [OrderController::class, 'cancel']);

        Route::get('/rider/deliveries', [RiderController::class, 'deliveries']);
        Route::post('/rider/deliveries/{delivery}', [RiderController::class, 'update']);
        Route::get('/rider/cash', [RiderController::class, 'cash']);
    });
});
