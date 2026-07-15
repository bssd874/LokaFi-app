<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TransactionImport\CommitCsvImportRequest;
use App\Http\Requests\TransactionImport\PreviewCsvImportRequest;
use App\Models\TransactionImportBatch;
use App\Models\Wallet;
use App\Services\TransactionImport\TransactionImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TransactionImportController extends Controller
{
    public function __construct(private readonly TransactionImportService $importService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $batches = $request->user()
            ->transactionImportBatches()
            ->with('wallet:id,user_id,name,type,currency')
            ->latest()
            ->paginate(10);

        return response()->json([
            'message' => 'Data batch import transaksi berhasil diambil',
            'data' => $batches,
        ]);
    }

    public function show(Request $request, TransactionImportBatch $transactionImport): JsonResponse
    {
        $this->ensureBatchBelongsToUser($request, $transactionImport);

        return response()->json([
            'message' => 'Detail batch import transaksi berhasil diambil',
            'data' => $this->importService->formatResult($transactionImport),
        ]);
    }

    public function preview(PreviewCsvImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $wallet = Wallet::where('id', $data['wallet_id'])
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $result = $this->importService->preview(
            user: $request->user(),
            wallet: $wallet,
            file: $data['file'],
            data: $data,
        );

        return response()->json([
            'message' => $result['duplicate_file']
                ? 'File CSV ini sudah pernah dipreview atau diimport'
                : 'Preview CSV berhasil dibuat',
            'data' => $result,
        ], $result['duplicate_file'] ? 200 : 201);
    }

    public function commit(CommitCsvImportRequest $request): JsonResponse
    {
        $data = $request->validated();
        $batch = TransactionImportBatch::findOrFail($data['batch_id']);

        $this->ensureBatchBelongsToUser($request, $batch);

        $result = $this->importService->commit(
            user: $request->user(),
            batch: $batch,
            mapping: $data['mapping'],
        );

        return response()->json([
            'message' => $result['idempotent']
                ? 'Batch CSV sudah pernah diimport'
                : 'Import CSV selesai diproses',
            'data' => $result,
        ]);
    }

    private function ensureBatchBelongsToUser(Request $request, TransactionImportBatch $batch): void
    {
        if ($batch->user_id !== $request->user()->id) {
            abort(403, 'Batch import tidak valid atau bukan milik kamu.');
        }
    }
}
