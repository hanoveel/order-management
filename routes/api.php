<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Order\GatewayController;
use App\Http\Controllers\Order\OrderController;
use App\Http\Controllers\Order\PaymentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Public routes (no authentication required)
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('login', [AuthController::class, 'login']);
});

// Public webhook (usually no auth; protect with gateway signature verification)
Route::post('payments/webhook/{gateway}', [PaymentController::class, 'webhook']);

// Protected routes (authentication required)
Route::prefix('auth')->middleware('auth:api')->group(function () {
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::get('me', [AuthController::class, 'me']);
});

// Other protected API routes
Route::middleware('auth:api')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Orders (Order + OrderItems via 4 aggregate endpoints)
    Route::prefix('orders')->group(function () {
        Route::get('/', [OrderController::class, 'index']);
        Route::post('/', [OrderController::class, 'store']);
        Route::put('/{order}', [OrderController::class, 'update']);
        Route::delete('/{order}', [OrderController::class, 'destroy']);

        // Status transitions
        Route::post('/{order}/confirm', [OrderController::class, 'confirm']);
        Route::post('/{order}/cancel', [OrderController::class, 'cancel']);

        // Payments
        Route::post('/{order}/payments/process', [PaymentController::class, 'process']);
    });

    // Payments
    Route::get('/payments', [PaymentController::class, 'index']);

    // Payment Gateways
    Route::prefix('gateways')->group(function () {
        Route::get('/', [GatewayController::class, 'index']);          // list (no pagination)
        Route::post('/', [GatewayController::class, 'store']);         // create
        Route::put('/{gateway}', [GatewayController::class, 'update']); // modify
        Route::delete('/{gateway}', [GatewayController::class, 'destroy']); // delete if no payments
    });
});
