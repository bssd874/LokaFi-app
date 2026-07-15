<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AiTransactionCategorizationController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\TransactionCategorizationController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\TransactionImportController;
use App\Http\Controllers\Api\TransactionDatasetController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\FinancialInsightController;
use App\Http\Controllers\Api\FinancialIntelligenceController;
use App\Http\Controllers\Api\BudgetController;
use App\Http\Controllers\Api\BankProviderController;
use App\Http\Controllers\Api\BankConnectionController;
use App\Http\Controllers\Api\AssetController;
use App\Http\Controllers\Api\WatchlistController;
use App\Http\Controllers\Api\InvestmentOrderController;
use App\Http\Controllers\Api\PortfolioController;
use App\Http\Controllers\Api\StellarWalletController;
use App\Http\Controllers\Api\StellarPaymentController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\PublicInvoiceController;

Route::get('/test', function () {
    return response()->json([
        'message' => 'API jalan bro'
    ]);
});

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/public/invoices/{uuid}', [PublicInvoiceController::class, 'show']);
Route::post('/public/invoices/{uuid}/verify-payment', [PublicInvoiceController::class, 'verifyPayment'])
    ->middleware('throttle:20,1');
Route::get('/bank-connections/callback', [BankConnectionController::class, 'callback'])
    ->name('bank-connections.callback');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::apiResource('/wallets', WalletController::class);
    Route::get('/stellar/wallet', [StellarWalletController::class, 'show']);
    Route::post('/stellar/wallet', [StellarWalletController::class, 'store']);
    Route::delete('/stellar/wallet', [StellarWalletController::class, 'destroy']);
    Route::get('/stellar/payments', [StellarPaymentController::class, 'index']);
    Route::post('/invoices/{invoice}/verify-payment', [InvoiceController::class, 'verifyPayment']);
    Route::apiResource('/invoices', InvoiceController::class);

    Route::apiResource('/categories', CategoryController::class);
    Route::get('/transactions/unclassified', [TransactionController::class, 'unclassified']);
    Route::get('/transactions/review-required', [TransactionCategorizationController::class, 'reviewRequired']);
    Route::post('/transactions/reprocess-categorization', [TransactionCategorizationController::class, 'reprocess']);
    Route::post('/transactions/ai-categorize-pending', [AiTransactionCategorizationController::class, 'pending']);
    Route::post('/transactions/{transaction}/ai-category-suggestion', [AiTransactionCategorizationController::class, 'suggest']);
    Route::post('/transactions/{transaction}/accept-ai-category', [AiTransactionCategorizationController::class, 'accept']);
    Route::get('/transactions/{transaction}/category-suggestion', [TransactionCategorizationController::class, 'suggest']);
    Route::post('/transactions/{transaction}/category-suggestion/accept', [TransactionCategorizationController::class, 'accept']);
    Route::patch('/transactions/{transaction}/category/correct', [TransactionCategorizationController::class, 'correct']);
    Route::patch('/transactions/{transaction}/category', [TransactionController::class, 'updateCategory']);
    Route::post('/transactions/bulk-category', [TransactionController::class, 'bulkCategory']);
    Route::apiResource('/transactions', TransactionController::class);

    Route::get('/transaction-imports', [TransactionImportController::class, 'index']);
    Route::get('/transaction-imports/{transactionImport}', [TransactionImportController::class, 'show']);
    Route::post('/transaction-imports/preview', [TransactionImportController::class, 'preview']);
    Route::post('/transaction-imports/commit', [TransactionImportController::class, 'commit']);

    Route::get('/transaction-dataset/summary', [TransactionDatasetController::class, 'summary']);
    Route::get('/transaction-dataset/export', [TransactionDatasetController::class, 'export']);

    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    Route::get('/financial-intelligence/summary', [FinancialIntelligenceController::class, 'summary']);
    Route::get('/financial-intelligence/trends', [FinancialIntelligenceController::class, 'trends']);
    Route::get('/financial-intelligence/budget-alerts', [FinancialIntelligenceController::class, 'budgetAlerts']);
    Route::get('/financial-intelligence/anomalies', [FinancialIntelligenceController::class, 'anomalies']);
    Route::get('/financial-intelligence/insight', [FinancialInsightController::class, 'show']);
    Route::post('/financial-intelligence/insight', [FinancialInsightController::class, 'store'])
        ->middleware('throttle:10,1');
    Route::post('/financial-intelligence/insight/regenerate', [FinancialInsightController::class, 'regenerate'])
        ->middleware('throttle:5,1');
    Route::get('/financial-intelligence/insight/history', [FinancialInsightController::class, 'history']);

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
