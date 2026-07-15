<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialIntelligenceRequest;
use App\Services\FinancialInsightService;
use Illuminate\Http\JsonResponse;

class FinancialInsightController extends Controller
{
    public function __construct(
        private readonly FinancialInsightService $service,
    ) {
    }

    public function show(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'AI financial insight cache berhasil diambil',
            'data' => $this->service->show($request->user(), $request->analyticsFilters()),
        ]);
    }

    public function store(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'AI financial insight selesai diproses',
            'data' => $this->service->generate($request->user(), $request->analyticsFilters()),
        ]);
    }

    public function regenerate(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'AI financial insight selesai diregenerasi',
            'data' => $this->service->generate($request->user(), $request->analyticsFilters(), force: true),
        ]);
    }

    public function history(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'History AI financial insight berhasil diambil',
            'data' => $this->service->history($request->user(), $request->analyticsFilters()),
        ]);
    }
}
