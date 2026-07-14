<?php

namespace App\Http\Controllers\Api;

use App\Models\Wallet;
use App\Models\Category;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\BulkCategorizeTransactionsRequest;
use App\Http\Requests\Transaction\StoreTransactionRequest;
use App\Http\Requests\Transaction\UpdateTransactionCategoryRequest;
use App\Http\Requests\Transaction\UpdateTransactionRequest;
use App\Services\TransactionCategoryLabelService;
use App\Services\TransactionSanitizationService;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionCategoryLabelService $labelService,
        private readonly TransactionSanitizationService $sanitizer,
    ) {
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()
            ->transactions()
            ->with(['wallet', 'fromWallet', 'toWallet', 'category', 'categoryLabel'])
            ->latest('happened_at');

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('wallet_id')) {
            $walletId = $request->query('wallet_id');

            $query->where(function ($q) use ($walletId) {
                $q->where('wallet_id', $walletId)
                    ->orWhere('from_wallet_id', $walletId)
                    ->orWhere('to_wallet_id', $walletId);
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        if ($request->filled('categorization_status')) {
            $query->where('categorization_status', $request->query('categorization_status'));
        }

        if ($request->filled('from')) {
            $query->whereDate('happened_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('happened_at', '<=', $request->query('to'));
        }

        if ($request->filled('search')) {
            $search = $request->query('search');

            $query->where(function ($q) use ($search) {
                $q->where('merchant', 'ILIKE', "%{$search}%")
                    ->orWhere('description', 'ILIKE', "%{$search}%")
                    ->orWhere('sanitized_description', 'ILIKE', "%{$search}%")
                    ->orWhere('note', 'ILIKE', "%{$search}%")
                    ->orWhere('reference_code', 'ILIKE', "%{$search}%");
            });
        }

        $transactions = $query->paginate(10);

        return response()->json([
            'message' => 'Data transaksi berhasil diambil',
            'data' => $transactions,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreTransactionRequest $request): JsonResponse
    {
        $data = $request->validated();

        $transaction = DB::transaction(function () use ($request, $data) {
            $this->ensureOwnership($request, $data);
            $hasCategory = !empty($data['category_id']);

            $transaction = $request->user()->transactions()->create([
                'type' => $data['type'],
                'wallet_id' => $data['wallet_id'] ?? null,
                'from_wallet_id' => $data['from_wallet_id'] ?? null,
                'to_wallet_id' => $data['to_wallet_id'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'amount' => $data['amount'],
                'fee' => $data['fee'] ?? 0,
                'currency' => $data['currency'] ?? 'IDR',
                'merchant' => $data['merchant'] ?? null,
                'description' => $data['description'] ?? $data['note'] ?? $data['merchant'] ?? null,
                'note' => $data['note'] ?? null,
                'reference_code' => $data['reference_code'] ?? null,
                'happened_at' => $data['happened_at'],
                'source' => 'manual',
                'sanitized_description' => $this->sanitizer->sanitizeText(
                    $data['description'] ?? $data['note'] ?? $data['merchant'] ?? '',
                    $request->user(),
                ),
                'categorization_status' => $hasCategory ? 'categorized' : 'unclassified',
                'category_source' => $hasCategory ? 'user' : 'unclassified',
                'categorized_at' => $hasCategory ? now() : null,
            ]);

            if ($transaction->category_id && $transaction->type !== 'transfer') {
                $transaction = $this->labelService->categorize(
                    $transaction,
                    Category::findOrFail($transaction->category_id),
                );
            }

            $this->applyTransactionToWalletBalance($transaction);

            return $transaction->load(['wallet', 'fromWallet', 'toWallet', 'category', 'categoryLabel']);
        });

        return response()->json([
            'message' => 'Transaksi berhasil dibuat',
            'data' => $transaction,
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($transaction);

            return response()->json([
            'message' => 'Detail transaksi berhasil diambil',
            'data' => $transaction->load(['wallet', 'fromWallet', 'toWallet', 'category', 'categoryLabel']),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateTransactionRequest $request, Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($transaction);

        $data = $request->validated();

        $updatedTransaction = DB::transaction(function () use ($request, $transaction, $data) {
            // 1. Balikkan efek transaksi lama
            $this->reverseTransactionFromWalletBalance($transaction);

            // 2. Merge data lama + data baru
            $mergedData = array_merge($transaction->toArray(), $data);

            // 3. Pastikan data baru tetap milik user login
            $this->ensureOwnership($request, $mergedData);

            // 4. Update transaksi
            $transaction->update([
                'type' => $mergedData['type'],
                'wallet_id' => $mergedData['wallet_id'] ?? null,
                'from_wallet_id' => $mergedData['from_wallet_id'] ?? null,
                'to_wallet_id' => $mergedData['to_wallet_id'] ?? null,
                'category_id' => $mergedData['category_id'] ?? null,
                'amount' => $mergedData['amount'],
                'fee' => $mergedData['fee'] ?? 0,
                'currency' => $mergedData['currency'] ?? 'IDR',
                'merchant' => $mergedData['merchant'] ?? null,
                'description' => $mergedData['description'] ?? $mergedData['note'] ?? $mergedData['merchant'] ?? null,
                'note' => $mergedData['note'] ?? null,
                'reference_code' => $mergedData['reference_code'] ?? null,
                'happened_at' => $mergedData['happened_at'],
                'sanitized_description' => $this->sanitizer->sanitizeText(
                    $mergedData['description'] ?? $mergedData['note'] ?? $mergedData['merchant'] ?? '',
                    $request->user(),
                ),
            ]);

            // 5. Terapkan efek transaksi baru
            $freshTransaction = $transaction->fresh();
            $this->applyTransactionToWalletBalance($freshTransaction);

            if ($freshTransaction->category_id && $freshTransaction->type !== 'transfer') {
                return $this->labelService->categorize(
                    $freshTransaction,
                    Category::findOrFail($freshTransaction->category_id),
                );
            }

            $freshTransaction->update([
                'categorization_status' => 'unclassified',
                'category_source' => 'unclassified',
                'categorized_at' => null,
            ]);

            return $freshTransaction->fresh()->load(['wallet', 'fromWallet', 'toWallet', 'category', 'categoryLabel']);
        });

        return response()->json([
            'message' => 'Transaksi berhasil diupdate',
            'data' => $updatedTransaction,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($transaction);

        DB::transaction(function () use ($transaction) {
            $this->reverseTransactionFromWalletBalance($transaction);
            $transaction->delete();
        });

        return response()->json([
            'message' => 'Transaksi berhasil dihapus',
        ]);
    }

    public function unclassified(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'wallet_id' => ['nullable', 'integer'],
            'type' => ['nullable', 'string', 'in:income,expense,transfer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'keyword' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()
            ->transactions()
            ->with(['wallet', 'category', 'categoryLabel'])
            ->where(function ($query) {
                $query->whereNull('category_id')
                    ->orWhere('categorization_status', 'unclassified');
            })
            ->latest('happened_at');

        if (!empty($filters['wallet_id'])) {
            $query->where('wallet_id', $filters['wallet_id']);
        }

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (!empty($filters['from'])) {
            $query->whereDate('happened_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->whereDate('happened_at', '<=', $filters['to']);
        }

        if (!empty($filters['keyword'])) {
            $keyword = $filters['keyword'];

            $query->where(function ($query) use ($keyword) {
                $query->where('merchant', 'ILIKE', "%{$keyword}%")
                    ->orWhere('description', 'ILIKE', "%{$keyword}%")
                    ->orWhere('sanitized_description', 'ILIKE', "%{$keyword}%")
                    ->orWhere('note', 'ILIKE', "%{$keyword}%");
            });
        }

        return response()->json([
            'message' => 'Data transaksi belum dikategorikan berhasil diambil',
            'data' => $query->paginate((int) ($filters['per_page'] ?? 10)),
        ]);
    }

    public function updateCategory(
        UpdateTransactionCategoryRequest $request,
        Transaction $transaction,
    ): JsonResponse {
        $this->ensureTransactionBelongsToUser($transaction);

        $category = Category::where('id', $request->validated('category_id'))
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $updatedTransaction = $this->labelService->categorize($transaction, $category);

        return response()->json([
            'message' => 'Kategori transaksi berhasil diperbarui',
            'data' => $updatedTransaction,
        ]);
    }

    public function bulkCategory(BulkCategorizeTransactionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $userId = $request->user()->id;

        $category = Category::where('id', $data['category_id'])
            ->where('user_id', $userId)
            ->firstOrFail();

        $transactions = Transaction::where('user_id', $userId)
            ->whereIn('id', $data['transaction_ids'])
            ->get();

        if ($transactions->count() !== count(array_unique($data['transaction_ids']))) {
            abort(403, 'Sebagian transaksi bukan milik kamu');
        }

        $result = $this->labelService->bulkCategorize($transactions, $category);

        return response()->json([
            'message' => 'Bulk kategori transaksi selesai',
            'data' => $result,
        ]);
    }

    private function applyTransactionToWalletBalance(Transaction $transaction): void
    {
        if ($transaction->type === 'income') {
            $wallet = Wallet::lockForUpdate()->findOrFail($transaction->wallet_id);
            $wallet->increment('current_balance', $transaction->amount);
        }

        if ($transaction->type === 'expense') {
            $wallet = Wallet::lockForUpdate()->findOrFail($transaction->wallet_id);
            $wallet->decrement('current_balance', $transaction->amount + $transaction->fee);
        }

        if ($transaction->type === 'transfer') {
            $fromWallet = Wallet::lockForUpdate()->findOrFail($transaction->from_wallet_id);
            $toWallet = Wallet::lockForUpdate()->findOrFail($transaction->to_wallet_id);

            $fromWallet->decrement('current_balance', $transaction->amount + $transaction->fee);
            $toWallet->increment('current_balance', $transaction->amount);
        }
    }

    private function reverseTransactionFromWalletBalance(Transaction $transaction): void
    {
        if ($transaction->type === 'income') {
            $wallet = Wallet::lockForUpdate()->findOrFail($transaction->wallet_id);
            $wallet->decrement('current_balance', $transaction->amount);
        }

        if ($transaction->type === 'expense') {
            $wallet = Wallet::lockForUpdate()->findOrFail($transaction->wallet_id);
            $wallet->increment('current_balance', $transaction->amount + $transaction->fee);
        }

        if ($transaction->type === 'transfer') {
            $fromWallet = Wallet::lockForUpdate()->findOrFail($transaction->from_wallet_id);
            $toWallet = Wallet::lockForUpdate()->findOrFail($transaction->to_wallet_id);

            $fromWallet->increment('current_balance', $transaction->amount + $transaction->fee);
            $toWallet->decrement('current_balance', $transaction->amount);
        }
    }

    private function ensureTransactionBelongsToUser(Transaction $transaction): void
    {
        if ($transaction->user_id !== request()->user()->id) {
            abort(403, 'Kamu tidak punya akses ke transaksi ini');
        }
    }

    private function ensureOwnership(Request $request, array $data): void
    {
        $userId = $request->user()->id;

        if (!empty($data['wallet_id'])) {
            $exists = Wallet::where('id', $data['wallet_id'])
                ->where('user_id', $userId)
                ->exists();

            if (!$exists) {
                abort(403, 'Wallet tidak valid atau bukan milik kamu');
            }
        }

        if (!empty($data['from_wallet_id'])) {
            $exists = Wallet::where('id', $data['from_wallet_id'])
                ->where('user_id', $userId)
                ->exists();

            if (!$exists) {
                abort(403, 'Wallet asal tidak valid atau bukan milik kamu');
            }
        }

        if (!empty($data['to_wallet_id'])) {
            $exists = Wallet::where('id', $data['to_wallet_id'])
                ->where('user_id', $userId)
                ->exists();

            if (!$exists) {
                abort(403, 'Wallet tujuan tidak valid atau bukan milik kamu');
            }
        }

        if (!empty($data['category_id'])) {
            $category = Category::where('id', $data['category_id'])
                ->where('user_id', $userId)
                ->first();

            if (!$category) {
                abort(403, 'Kategori tidak valid atau bukan milik kamu');
            }

            if (($data['type'] ?? null) === 'income' && $category->type !== 'income') {
                abort(422, 'Transaksi income harus menggunakan kategori income.');
            }

            if (($data['type'] ?? null) === 'expense' && $category->type !== 'expense') {
                abort(422, 'Transaksi expense harus menggunakan kategori expense.');
            }

            if (($data['type'] ?? null) === 'transfer') {
                abort(422, 'Transaksi transfer tidak boleh menggunakan kategori.');
            }
        }
    }
}
