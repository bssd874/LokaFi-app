<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Transaction\AiCategorizePendingRequest;
use App\Models\Transaction;
use App\Services\Ai\AiCategorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiTransactionCategorizationController extends Controller
{
    public function __construct(private readonly AiCategorizationService $aiCategorizationService)
    {
    }

    public function suggest(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($request, $transaction);

        return response()->json([
            'message' => 'AI category suggestion processed',
            'data' => $this->aiCategorizationService->suggest($transaction),
        ]);
    }

    public function accept(Request $request, Transaction $transaction): JsonResponse
    {
        $this->ensureTransactionBelongsToUser($request, $transaction);

        return response()->json([
            'message' => 'AI category suggestion accepted',
            'data' => $this->aiCategorizationService->accept($transaction),
        ]);
    }

    public function pending(AiCategorizePendingRequest $request): JsonResponse
    {
        $result = $this->aiCategorizationService->categorizePending(
            $request->user(),
            (int) ($request->validated('limit') ?? AiCategorizationService::BATCH_LIMIT),
        );

        return response()->json([
            'message' => 'AI pending categorization processed',
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
