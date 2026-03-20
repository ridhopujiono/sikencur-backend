<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DssController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserBudgetController;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/dss/profile', [DssController::class, 'profile']);
    Route::post('/dss/analyze', [DssController::class, 'analyze']);
    Route::get('/user-budgets', [UserBudgetController::class, 'show']);
    Route::put('/user-budgets', [UserBudgetController::class, 'upsert']);
    Route::post('/scan-receipt', [TransactionController::class, 'scanReceipt']);
    Route::get('/scan-receipt/{scan_id}', [TransactionController::class, 'checkStatus']);
    Route::get('/transactions', [TransactionController::class, 'index']);
    Route::get('/transactions/summary', [TransactionController::class, 'summary']);
    Route::get('/transactions/{transaction_id}', [TransactionController::class, 'show']);
    Route::post('/transactions', [TransactionController::class, 'store']);
});
