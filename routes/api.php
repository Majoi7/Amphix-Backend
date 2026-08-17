<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CourseController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

// --- Auth ---
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    // --- Payments (require auth) ---
    Route::post('/payments/create', [PaymentController::class, 'create']);
    Route::get('/payments/{payment}', [PaymentController::class, 'show']);
});

// --- Courses (public) ---
Route::get('/courses', [CourseController::class, 'index']);
Route::get('/courses/{course}', [CourseController::class, 'show']);

// --- Webhook (public, secured via HMAC signature instead of Sanctum) ---
Route::post('/webhooks/moneroo', [WebhookController::class, 'moneroo']);
