<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FinancialIntelligenceRequest;
use App\Services\FinancialIntelligenceService;
use Illuminate\Http\JsonResponse;

class FinancialIntelligenceController extends Controller
{
    public function __construct(
        private readonly FinancialIntelligenceService $service,
    ) {
    }

    public function summary(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Ringkasan financial intelligence berhasil dihitung',
            'data' => $this->service->summary($request->user(), $request->analyticsFilters()),
        ]);
    }

    public function trends(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Tren financial intelligence berhasil dihitung',
            'data' => $this->service->trends($request->user(), $request->analyticsFilters()),
        ]);
    }

    public function budgetAlerts(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Budget alert berhasil dihitung',
            'data' => $this->service->budgetAlerts($request->user(), $request->analyticsFilters()),
        ]);
    }

    public function anomalies(FinancialIntelligenceRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Anomali financial intelligence berhasil dihitung',
            'data' => $this->service->anomalies($request->user(), $request->analyticsFilters()),
        ]);
    }
}
