<?php

namespace App\Http\Controllers\Api;

use App\Models\Budget;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;
use App\Http\Requests\Budget\StoreBudgetRequest;
use App\Http\Requests\Budget\UpdateBudgetRequest;

class BudgetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('Y-m'));

        $budgets = $request->user()
            ->budgets()
            ->with('category')
            ->where('month', $month)
            ->latest()
            ->get();

        return response()->json([
            'message' => 'Data budget berhasil diambil',
            'data' => $budgets,
        ]);
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {
        $data = $request->validated();

        $budget = $request->user()->budgets()->updateOrCreate(
            [
                'category_id' => $data['category_id'],
                'month' => $data['month'],
            ],
            [
                'amount' => $data['amount'],
            ]
        );

        return response()->json([
            'message' => 'Budget berhasil disimpan',
            'data' => $budget->load('category'),
        ], 201);
    }

    public function show(Budget $budget): JsonResponse
    {
        $this->ensureBudgetBelongsToUser($budget);

        return response()->json([
            'message' => 'Detail budget berhasil diambil',
            'data' => $budget->load('category'),
        ]);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $this->ensureBudgetBelongsToUser($budget);

        $data = $request->validated();

        $budget->update($data);

        return response()->json([
            'message' => 'Budget berhasil diupdate',
            'data' => $budget->fresh()->load('category'),
        ]);
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $this->ensureBudgetBelongsToUser($budget);

        $budget->delete();

        return response()->json([
            'message' => 'Budget berhasil dihapus',
        ]);
    }

    public function progress(Request $request): JsonResponse
    {
        $month = $request->query('month', now()->format('Y-m'));

        $startDate = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $endDate = Carbon::createFromFormat('Y-m', $month)->endOfMonth();

        $budgets = $request->user()
            ->budgets()
            ->with('category')
            ->where('month', $month)
            ->get();

        $spentByCategory = $request->user()
            ->transactions()
            ->select(
                'category_id',
                DB::raw('SUM(amount + fee) as spent_amount')
            )
            ->where('type', 'expense')
            ->whereBetween('happened_at', [$startDate, $endDate])
            ->groupBy('category_id')
            ->pluck('spent_amount', 'category_id');

        $progress = $budgets->map(function ($budget) use ($spentByCategory) {
            $spentAmount = (float) ($spentByCategory[$budget->category_id] ?? 0);
            $budgetAmount = (float) $budget->amount;
            $remainingAmount = $budgetAmount - $spentAmount;

            $percentage = $budgetAmount > 0
                ? round(($spentAmount / $budgetAmount) * 100, 2)
                : 0;

            $status = 'safe';

            if ($percentage >= 100) {
                $status = 'over_budget';
            } elseif ($percentage >= 80) {
                $status = 'warning';
            }

            return [
                'budget_id' => $budget->id,
                'category_id' => $budget->category_id,
                'category_name' => $budget->category?->name,
                'category_color' => $budget->category?->color,
                'month' => $budget->month,
                'budget_amount' => $budgetAmount,
                'spent_amount' => $spentAmount,
                'remaining_amount' => $remainingAmount,
                'percentage' => $percentage,
                'status' => $status,
            ];
        });

        return response()->json([
            'message' => 'Progress budget berhasil diambil',
            'data' => [
                'month' => $month,
                'total_budget' => round($progress->sum('budget_amount'), 2),
                'total_spent' => round($progress->sum('spent_amount'), 2),
                'total_remaining' => round($progress->sum('remaining_amount'), 2),
                'items' => $progress->values(),
            ],
        ]);
    }

    private function ensureBudgetBelongsToUser(Budget $budget): void
    {
        if ($budget->user_id !== request()->user()->id) {
            abort(403, 'Kamu tidak punya akses ke budget ini');
        }
    }
}