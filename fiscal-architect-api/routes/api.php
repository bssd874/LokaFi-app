<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransactionDatasetController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BankProviderController;
use App\Http\Controllers\Api\BankConnectionController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\WatchlistController;
use App\Http\Controllers\Api\InvestmentOrderController;
use App\Http\Controllers\Api\PortfolioController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'API jalan bro'
    ]);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/bank-connections/callback', [BankConnectionController::class, 'callback'])
    ->name('bank-connections.callback');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('/wallets', WalletController::class);
    Route::apiResource('/categories', CategoryController::class);
    Route::get('/transactions/unclassified', [TransactionController::class, 'unclassified']);
    Route::patch('/transactions/{transaction}/category', [TransactionController::class, 'updateCategory']);
    Route::post('/transactions/bulk-category', [TransactionController::class, 'bulkCategory']);
    Route::apiResource('/transactions', TransactionController::class);

    Route::get('/transaction-dataset/summary', [TransactionDatasetController::class, 'summary']);
    Route::get('/transaction-dataset/export', [TransactionDatasetController::class, 'export']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/budgets/progress', [BudgetController::class, 'progress']);
    Route::apiResource('/budgets', BudgetController::class);

    Route::get('/bank-providers', [BankProviderController::class, 'index']);

    Route::get('/bank-connections', [BankConnectionController::class, 'index']);
    Route::post('/bank-connections/start', [BankConnectionController::class, 'start']);
    Route::post('/bank-connections/connect', [BankConnectionController::class, 'connect']);
    Route::post('/bank-connections/{bankConnection}/sync', [BankConnectionController::class, 'sync']);
    Route::delete('/bank-connections/{bankConnection}', [BankConnectionController::class, 'destroy']);
    Route::apiResource('/assets', AssetController::class);
    Route::apiResource('/watchlists', WatchlistController::class)->only(['index', 'store', 'destroy']);

    Route::get('/investment-orders', [InvestmentOrderController::class, 'index']);
    Route::post('/investment-orders', [InvestmentOrderController::class, 'store']);

    Route::get('/portfolio', [PortfolioController::class, 'index']);
    Route::get('/portfolio/summary', [PortfolioController::class, 'summary']);
});
