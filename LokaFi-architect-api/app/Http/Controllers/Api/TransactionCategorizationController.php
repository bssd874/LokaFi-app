<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\CorrectTransactionCategoryRequest;
use App\Http\Requests\Transaction\ReprocessTransactionCategorizationRequest;
use App\Models\Category;
use App\Models\Transaction;
use App\Services\TransactionCategorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionCategorizationController extends Controller
{
    public function __construct(private readonly TransactionCategorizationService $categorizationService)
    {
    }

    public function reviewRequired(Request $request): JsonResponse
    {
        $transactions = $request->user()
            ->transactions()
            ->with(['wallet', 'fromWallet', 'toWallet', 'category', 'suggestedCategory', 'categoryLabel'])
            ->where('categorization_status', TransactionCategorizationService::STATUS_REVIEW_REQUIRED)
            ->latest('happened_at')
            ->paginate((int) $request->query('per_page', 10));

        return response()->json([
            'message' => 'Transaksi yang perlu review berhasil diambil',
            'data' => $transactions,
        ]);
    }

    public function suggest(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($request, $transaction);

        return response()->json([
            'message' => 'Suggestion kategori deterministic berhasil dibuat',
            'data' => $this->categorizationService->suggest($transaction, persist: true),
        ]);
    }

    public function accept(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($request, $transaction);

        $updated = $this->categorizationService->acceptSuggestion($transaction);

        return response()->json([
            'message' => 'Suggestion kategori diterima',
            'data' => $updated,
        ]);
    }

    public function correct(
        CorrectTransactionCategoryRequest $request,
        Transaction $transaction,
    ): JsonResponse {
        $this->ensureTransactionBelongsToUser($request, $transaction);

        $category = Category::where('id', $request->validated('category_id'))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $updated = $this->categorizationService->correctCategory($transaction, $category);

        return response()->json([
            'message' => 'Kategori transaksi dikoreksi dan mapping diperbarui',
            'data' => $updated,
        ]);
    }

    public function reprocess(ReprocessTransactionCategorizationRequest $request): JsonResponse
    {
        $result = $this->categorizationService->reprocess(
            $request->user(),
            $request->validated('transaction_ids'),
        );

        return response()->json([
            'message' => 'Reprocess kategorisasi selesai',
            'data' => $result,
        ]);
    }

    private function ensureTransactionBelongsToUser(Request $request, Transaction $transaction): void
    {
        if ($transaction->user_id !== $request->user()->id) {
            abort(403, 'Kamu tidak punya akses ke transaksi ini');
        }
    }
}
