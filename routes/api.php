<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/scan-receipt', [TransactionController::class, 'scanReceipt']);
    Route::get('/scan-receipt/{scan_id}', [TransactionController::class, 'checkStatus']);
    Route::post('/transactions', [TransactionController::class, 'store']);
});
