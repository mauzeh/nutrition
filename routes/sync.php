<?php

use App\Sync\Controllers\AuthController;
use App\Sync\Controllers\BlueprintController;
use App\Sync\Controllers\ChangesController;
use App\Sync\Controllers\LogController;
use App\Sync\Controllers\PreferencesController;
use App\Sync\Controllers\RestoreController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:2,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:2,1');
Route::get('/auth/google/redirect', [AuthController::class, 'googleRedirect']);
Route::get('/auth/google/callback', [AuthController::class, 'googleCallback']);
Route::post('/auth/apple', [AuthController::class, 'appleAuth']);
Route::post('/auth/check', [AuthController::class, 'checkEmail']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [AuthController::class, 'resetPassword']);

Route::middleware(['auth:sanctum', 'throttle:sync-per-user', 'throttle:sync-global', 'device-id', 'log-sync-request'])->group(function () {
    Route::post('/logs', [LogController::class, 'store']);
    Route::delete('/logs/{liftLog}', [LogController::class, 'destroy']);
    Route::post('/blueprint', [BlueprintController::class, 'store']);
    Route::post('/preferences', [PreferencesController::class, 'store']);
    Route::get('/restore', [RestoreController::class, 'index']);
    Route::get('/changes', [ChangesController::class, 'index']);
    Route::post('/exercises', [\App\Sync\Controllers\ExerciseController::class, 'store']);
});

Route::middleware(['auth:sanctum', 'throttle:sync-batch', 'device-id', 'log-sync-request'])->group(function () {
    Route::post('/logs/batch', [LogController::class, 'batch']);
});
