<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\TransactionCategoryLabel;
use App\Services\TransactionDatasetExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionDatasetController extends Controller
{
    public function __construct(private readonly TransactionDatasetExportService $exportService)
    {
    }

    public function summary(Request $request): JsonResponse
    {
        $userId = $request->user()->id;
        $totalTransactions = Transaction::where('user_id', $userId)->count();
        $totalLabeled = TransactionCategoryLabel::where('user_id', $userId)->count();
        $totalVerified = TransactionCategoryLabel::where('user_id', $userId)
            ->where('is_verified', true)
            ->count();
        $totalUnclassified = Transaction::where('user_id', $userId)
            ->where(function ($query) {
                $query->whereNull('category_id')
                    ->orWhere('categorization_status', 'unclassified');
            })
            ->count();

        $perCategory = TransactionCategoryLabel::query()
            ->selectRaw('category_id, COUNT(*) as total')
            ->with('category:id,name,type')
            ->where('user_id', $userId)
            ->groupBy('category_id')
            ->get()
            ->map(fn (TransactionCategoryLabel $label) => [
                'category_id' => $label->category_id,
                'category_name' => $label->category?->name ?? 'Uncategorized',
                'category_type' => $label->category?->type,
                'total' => (int) $label->total,
            ])
            ->values();

        $bySource = TransactionCategoryLabel::query()
            ->selectRaw('source, COUNT(*) as total')
            ->where('user_id', $userId)
            ->groupBy('source')
            ->orderBy('source')
            ->get()
            ->map(fn (TransactionCategoryLabel $label) => [
                'source' => $label->source,
                'total' => (int) $label->total,
            ])
            ->values();

        return response()->json([
            'message' => 'Ringkasan dataset transaksi berhasil diambil',
            'data' => [
                'total_transactions' => $totalTransactions,
                'total_labeled' => $totalLabeled,
                'total_unclassified' => $totalUnclassified,
                'total_verified' => $totalVerified,
                'per_category' => $perCategory,
                'by_source' => $bySource,
                'label_completion_percentage' => $totalTransactions > 0
                    ? round(($totalVerified / $totalTransactions) * 100, 2)
                    : 0,
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $rows = $this->exportService->rows($request->user()->id, verifiedOnly: true);
        $filename = 'transaction_dataset_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'wb');
            fputcsv($handle, ['description', 'label', 'type', 'amount', 'source']);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row['description'],
                    $row['label'],
                    $row['type'],
                    $row['amount'],
                    $row['source'],
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
