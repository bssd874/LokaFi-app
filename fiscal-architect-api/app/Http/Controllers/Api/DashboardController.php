<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;


class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        /*
        |--------------------------------------------------------------------------
        | Date Range
        |--------------------------------------------------------------------------
        | Default: bulan berjalan.
        | Bisa custom pakai query:
        | /api/dashboard/summary?from=2026-05-01&to=2026-05-31
        */
        $from = $request->filled('from')
            ? Carbon::parse($request->query('from'))->startOfDay()
            : now()->startOfMonth();

        $to = $request->filled('to')
            ? Carbon::parse($request->query('to'))->endOfDay()
            : now()->endOfMonth();

        /*
        |--------------------------------------------------------------------------
        | Total Balance
        |--------------------------------------------------------------------------
        | Total saldo semua wallet aktif milik user.
        */
        $totalBalance = $user->wallets()
            ->where('is_active', true)
            ->sum('current_balance');

        /*
        |--------------------------------------------------------------------------
        | Total Income
        |--------------------------------------------------------------------------
        */
        $totalIncome = $user->transactions()
            ->where('type', 'income')
            ->whereBetween('happened_at', [$from, $to])
            ->sum('amount');

        /*
        |--------------------------------------------------------------------------
        | Total Expense
        |--------------------------------------------------------------------------
        | Expense dihitung amount + fee.
        */
        $totalExpense = $user->transactions()
            ->where('type', 'expense')
            ->whereBetween('happened_at', [$from, $to])
            ->sum(DB::raw('amount + fee'));

        /*
        |--------------------------------------------------------------------------
        | Net Cashflow
        |--------------------------------------------------------------------------
        */
        $netCashflow = $totalIncome - $totalExpense;

        /*
        |--------------------------------------------------------------------------
        | Recent Transactions
        |--------------------------------------------------------------------------
        */
        $recentTransactions = $user->transactions()
            ->with(['wallet', 'fromWallet', 'toWallet', 'category'])
            ->latest('happened_at')
            ->limit(5)
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Expense By Category
        |--------------------------------------------------------------------------
        | Untuk donut chart.
        */
        $expenseByCategory = $user->transactions()
            ->select(
                'categories.id as category_id',
                'categories.name as category_name',
                'categories.color as category_color',
                DB::raw('SUM(transactions.amount + transactions.fee) as total')
            )
            ->join('categories', 'transactions.category_id', '=', 'categories.id')
            ->where('transactions.type', 'expense')
            ->whereBetween('transactions.happened_at', [$from, $to])
            ->groupBy('categories.id', 'categories.name', 'categories.color')
            ->orderByDesc('total')
            ->get();

        /*
        |--------------------------------------------------------------------------
        | Daily Cashflow
        |--------------------------------------------------------------------------
        | Untuk line chart income vs expense per hari.
        */
        $dailyRaw = $user->transactions()
            ->select(
                DB::raw("DATE(happened_at) as date"),
                DB::raw("SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END) as income"),
                DB::raw("SUM(CASE WHEN type = 'expense' THEN amount + fee ELSE 0 END) as expense")
            )
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('happened_at', [$from, $to])
            ->groupBy(DB::raw("DATE(happened_at)"))
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        /*
        |--------------------------------------------------------------------------
        | Fill Missing Dates
        |--------------------------------------------------------------------------
        | Supaya chart tetap rapi walaupun ada tanggal tanpa transaksi.
        */
        $dailyCashflow = [];

        $currentDate = $from->copy();

        while ($currentDate->lte($to)) {
            $dateKey = $currentDate->toDateString();

            $dailyCashflow[] = [
                'date' => $dateKey,
                'income' => isset($dailyRaw[$dateKey]) ? (float) $dailyRaw[$dateKey]->income : 0,
                'expense' => isset($dailyRaw[$dateKey]) ? (float) $dailyRaw[$dateKey]->expense : 0,
            ];

            $currentDate->addDay();
        }

        return response()->json([
            'message' => 'Ringkasan dashboard berhasil diambil',
            'data' => [
                'period' => [
                    'from' => $from->toDateString(),
                    'to' => $to->toDateString(),
                ],
                'summary' => [
                    'total_balance' => (float) $totalBalance,
                    'total_income' => (float) $totalIncome,
                    'total_expense' => (float) $totalExpense,
                    'net_cashflow' => (float) $netCashflow,
                    'wallets_count' => $user->wallets()->count(),
                    'transactions_count' => $user->transactions()
                        ->whereBetween('happened_at', [$from, $to])
                        ->count(),
                ],
                'recent_transactions' => $recentTransactions,
                'expense_by_category' => $expenseByCategory,
                'daily_cashflow' => $dailyCashflow,
            ],
        ]);
    }
}
