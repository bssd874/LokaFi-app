<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class FinancialIntelligenceService
{
    public const VERSION = 'financial_analytics_v1';

    public function dashboard(User $user, array $filters): array
    {
        $period = $this->period($filters);
        $transactions = $this->transactionsFor($user, $period['start'], $period['end'], $filters);
        $previousTransactions = $this->transactionsFor(
            $user,
            $period['previous_start'],
            $period['previous_end'],
            $filters,
        );
        $metrics = $this->metrics($transactions, $period['days']);
        $previousMetrics = $this->metrics($previousTransactions, $period['days']);
        $comparison = $this->comparison($metrics, $previousMetrics);
        $expenseDistribution = $this->expenseDistribution($transactions);
        $sourceDistribution = $this->sourceDistribution($transactions);
        $budgetItems = $this->budgetItems($user, $period, $filters);
        $anomalyData = $this->anomalyData(
            $user,
            $transactions,
            $previousTransactions,
            $period,
            $filters,
            $budgetItems,
        );

        return [
            'period' => [
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'timezone' => $filters['timezone'] ?? 'Asia/Jakarta',
            ],
            'filters' => [
                'wallet_id' => $filters['wallet_id'] ?? null,
                'source' => $filters['source'] ?? null,
            ],
            'summary' => [
                'total_balance' => $this->totalBalance($user, $filters),
                'total_income' => $metrics['total_income'],
                'total_expense' => $metrics['total_expense'],
                'net_cashflow' => $metrics['net_cashflow'],
                'transactions_count' => $metrics['transaction_count'],
            ],
            'comparison' => $this->dashboardComparison($comparison),
            'daily_cashflow' => $this->dailyCashflow($transactions, $period),
            'expense_distribution' => $expenseDistribution,
            'source_distribution' => $this->dashboardSourceDistribution($sourceDistribution),
            'recent_transactions' => $this->recentTransactions($transactions),
            'budget_alerts' => $budgetItems
                ->whereIn('severity', ['notice', 'warning', 'critical', 'exceeded'])
                ->take(5)
                ->values()
                ->all(),
            'anomalies' => $anomalyData['items']->take(5)->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    public function summary(User $user, array $filters): array
    {
        $period = $this->period($filters);
        $currentTransactions = $this->transactionsFor($user, $period['start'], $period['end'], $filters);
        $previousTransactions = $this->transactionsFor($user, $period['previous_start'], $period['previous_end'], $filters);
        $metrics = $this->metrics($currentTransactions, $period['days']);
        $previousMetrics = $this->metrics($previousTransactions, $period['days']);

        return $this->envelope($period, $filters, [
            'summary' => $metrics,
            'comparison' => $this->comparison($metrics, $previousMetrics),
            'expense_distribution_by_category' => $this->expenseDistribution($currentTransactions),
            'largest_expense_category' => $this->largestExpenseCategory($currentTransactions),
            'largest_transactions' => $this->largestTransactions($currentTransactions),
            'source_distribution' => $this->sourceDistribution($currentTransactions),
        ]);
    }

    public function trends(User $user, array $filters): array
    {
        $period = $this->period($filters);
        $currentTransactions = $this->transactionsFor($user, $period['start'], $period['end'], $filters);
        $previousTransactions = $this->transactionsFor($user, $period['previous_start'], $period['previous_end'], $filters);

        return $this->envelope($period, $filters, [
            'category_trends' => $this->categoryTrends($currentTransactions, $previousTransactions),
        ]);
    }

    public function budgetAlerts(User $user, array $filters): array
    {
        $period = $this->period($filters);
        $items = $this->budgetItems($user, $period, $filters);

        return $this->envelope($period, $filters, [
            'thresholds' => config('financial_intelligence.budget_thresholds'),
            'items' => $items,
            'summary' => [
                'total_budget' => round($items->sum('budget_amount'), 2),
                'total_spent' => round($items->sum('amount_spent'), 2),
                'total_remaining' => round($items->sum('remaining_amount'), 2),
                'alerts_count' => $items
                    ->whereIn('severity', ['notice', 'warning', 'critical', 'exceeded'])
                    ->count(),
            ],
        ]);
    }

    public function anomalies(User $user, array $filters): array
    {
        $period = $this->period($filters);
        $currentTransactions = $this->transactionsFor($user, $period['start'], $period['end'], $filters);
        $previousTransactions = $this->transactionsFor($user, $period['previous_start'], $period['previous_end'], $filters);
        $anomalyData = $this->anomalyData(
            $user,
            $currentTransactions,
            $previousTransactions,
            $period,
            $filters,
        );
        $anomalies = $anomalyData['items'];

        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min((int) ($filters['per_page'] ?? 50), 100));
        $total = $anomalies->count();

        return $this->envelope($period, $filters, [
            'items' => $anomalies
                ->slice(($page - 1) * $perPage, $perPage)
                ->values(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
            'insufficient_history' => $anomalyData['insufficient_history']
                ->unique(fn (array $item) => $item['type'] . ':' . ($item['category_id'] ?? 'all'))
                ->values(),
        ]);
    }

    private function anomalyData(
        User $user,
        Collection $currentTransactions,
        Collection $previousTransactions,
        array $period,
        array $filters,
        ?Collection $budgetItems = null,
    ): array {
        $metrics = $this->metrics($currentTransactions, $period['days']);
        $previousMetrics = $this->metrics($previousTransactions, $period['days']);
        $insufficientHistory = collect();

        $anomalies = collect()
            ->merge($this->unusualAmountAnomalies($user, $currentTransactions, $period, $filters, $insufficientHistory))
            ->merge($this->categorySpikeAnomalies($currentTransactions, $previousTransactions, $period))
            ->merge($this->frequencyAnomalies($user, $currentTransactions, $period, $filters, $insufficientHistory))
            ->merge($this->budgetOverspendingAnomalies($user, $period, $filters, $budgetItems))
            ->merge($this->duplicateLikeAnomalies($currentTransactions, $period))
            ->merge($this->negativeCashflowAnomalies($metrics, $previousMetrics, $period))
            ->sortByDesc(fn (array $item) => $this->severityRank($item['severity']))
            ->values();

        return [
            'items' => $anomalies,
            'insufficient_history' => $insufficientHistory,
        ];
    }

    private function period(array $filters): array
    {
        $timezone = $filters['timezone'] ?? config('app.timezone', 'UTC');
        $start = !empty($filters['start_date'])
            ? CarbonImmutable::parse($filters['start_date'], $timezone)->startOfDay()
            : CarbonImmutable::now($timezone)->startOfMonth();
        $end = !empty($filters['end_date'])
            ? CarbonImmutable::parse($filters['end_date'], $timezone)->endOfDay()
            : CarbonImmutable::now($timezone)->endOfMonth();

        $days = (int) $start->diffInDays($end->startOfDay()) + 1;
        $previousEnd = $start->subDay()->endOfDay();
        $previousStart = $previousEnd->subDays($days - 1)->startOfDay();

        return [
            'start' => $start,
            'end' => $end,
            'previous_start' => $previousStart,
            'previous_end' => $previousEnd,
            'days' => $days,
        ];
    }

    private function totalBalance(User $user, array $filters): float
    {
        $query = $user->wallets()->where('is_active', true);

        if (!empty($filters['wallet_id'])) {
            $query->where('id', $filters['wallet_id']);
        }

        return round((float) $query->sum('current_balance'), 2);
    }

    private function dashboardComparison(array $comparison): array
    {
        $income = $comparison['income']['percentage_change'];
        $expense = $comparison['expense']['percentage_change'];
        $netCashflow = $comparison['net_cashflow']['percentage_change'];
        $status = collect([$comparison['income'], $comparison['expense'], $comparison['net_cashflow']])
            ->contains(fn (array $item) => $item['status'] === 'comparable')
            ? 'available'
            : 'unavailable';

        return [
            'previous_income' => $comparison['previous_income'],
            'previous_expense' => $comparison['previous_expense'],
            'previous_net_cashflow' => $comparison['previous_net_cashflow'],
            'income_change_percentage' => $income !== null && is_finite((float) $income) ? $income : null,
            'expense_change_percentage' => $expense !== null && is_finite((float) $expense) ? $expense : null,
            'net_cashflow_change_percentage' => $netCashflow !== null && is_finite((float) $netCashflow) ? $netCashflow : null,
            'status' => $status,
        ];
    }

    private function dailyCashflow(Collection $transactions, array $period): array
    {
        $grouped = $transactions
            ->groupBy(fn (Transaction $transaction) => $transaction->happened_at?->toDateString())
            ->map(fn (Collection $items) => [
                'income' => round($items
                    ->where('type', 'income')
                    ->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction)), 2),
                'expense' => round($items
                    ->where('type', 'expense')
                    ->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction)), 2),
            ]);

        $items = [];
        $cursor = $period['start']->startOfDay();

        while ($cursor->lte($period['end'])) {
            $date = $cursor->toDateString();
            $daily = $grouped->get($date, ['income' => 0.0, 'expense' => 0.0]);

            $items[] = [
                'date' => $date,
                'income' => (float) $daily['income'],
                'expense' => (float) $daily['expense'],
            ];

            $cursor = $cursor->addDay();
        }

        return $items;
    }

    private function dashboardSourceDistribution(array $sourceDistribution): array
    {
        $sources = ['manual', 'bank_csv', 'ewallet_csv', 'stellar'];

        return collect($sources)
            ->map(function (string $source) use ($sourceDistribution) {
                $item = collect($sourceDistribution)->firstWhere('source', $source);

                return $item ?? [
                    'source' => $source,
                    'label' => match ($source) {
                        'bank_csv' => 'Bank CSV',
                        'ewallet_csv' => 'E-Wallet CSV',
                        'stellar' => 'Stellar',
                        default => 'Manual',
                    },
                    'count' => 0,
                    'amount' => 0.0,
                    'share' => 0,
                ];
            })
            ->values()
            ->all();
    }

    private function recentTransactions(Collection $transactions): array
    {
        return $transactions
            ->sortByDesc(fn (Transaction $transaction) => $transaction->happened_at?->timestamp ?? 0)
            ->take(8)
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'source' => $transaction->source ?? 'manual',
                'wallet' => $transaction->wallet ? [
                    'id' => $transaction->wallet->id,
                    'name' => $transaction->wallet->name,
                    'type' => $transaction->wallet->type,
                    'currency' => $transaction->wallet->currency,
                ] : null,
                'category' => $transaction->category ? [
                    'id' => $transaction->category->id,
                    'name' => $transaction->category->name,
                    'color' => $transaction->category->color,
                ] : null,
                'amount' => (float) $transaction->amount,
                'fee' => (float) $transaction->fee,
                'effective_amount' => round($this->effectiveAmount($transaction), 2),
                'currency' => $transaction->currency,
                'merchant' => $transaction->merchant,
                'description' => $transaction->description ?? $transaction->note ?? $transaction->merchant,
                'happened_at' => $transaction->happened_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function envelope(array $period, array $filters, array $data): array
    {
        return array_merge([
            'calculation_version' => config('financial_intelligence.calculation_version', self::VERSION),
            'generated_at' => now()->toIso8601String(),
            'period' => [
                'start_date' => $period['start']->toDateString(),
                'end_date' => $period['end']->toDateString(),
                'days' => $period['days'],
            ],
            'comparison_period' => [
                'start_date' => $period['previous_start']->toDateString(),
                'end_date' => $period['previous_end']->toDateString(),
                'days' => $period['days'],
            ],
            'filters' => [
                'wallet_id' => $filters['wallet_id'] ?? null,
                'source' => $filters['source'] ?? null,
                'category_id' => $filters['category_id'] ?? null,
            ],
        ], $data);
    }

    private function transactionsFor(User $user, CarbonImmutable $start, CarbonImmutable $end, array $filters): EloquentCollection
    {
        return $user->transactions()
            ->with(['category', 'wallet'])
            ->whereIn('type', ['income', 'expense'])
            ->whereBetween('happened_at', [$start, $end])
            ->when(!empty($filters['wallet_id']), fn ($query) => $query->where('wallet_id', $filters['wallet_id']))
            ->when(!empty($filters['source']), fn ($query) => $query->where('source', $filters['source']))
            ->when(!empty($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->orderBy('happened_at')
            ->get();
    }

    private function historicalExpenses(
        User $user,
        CarbonImmutable $before,
        int $categoryId,
        array $filters,
    ): EloquentCollection {
        return $user->transactions()
            ->where('type', 'expense')
            ->where('category_id', $categoryId)
            ->where('happened_at', '<', $before)
            ->when(!empty($filters['wallet_id']), fn ($query) => $query->where('wallet_id', $filters['wallet_id']))
            ->when(!empty($filters['source']), fn ($query) => $query->where('source', $filters['source']))
            ->orderBy('happened_at')
            ->get();
    }

    private function metrics(Collection $transactions, int $days): array
    {
        $income = $transactions
            ->where('type', 'income')
            ->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction));
        $expense = $transactions
            ->where('type', 'expense')
            ->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction));
        $net = $income - $expense;
        $savingsRate = $income > 0 ? ($net / $income) * 100 : null;
        $incomeToExpenseRatio = $expense > 0 ? $income / $expense : null;

        return [
            'total_income' => round($income, 2),
            'total_expense' => round($expense, 2),
            'net_cashflow' => round($net, 2),
            'savings_amount' => round($net, 2),
            'savings_rate' => $savingsRate !== null ? round($savingsRate, 2) : null,
            'savings_rate_status' => $income > 0 ? 'available' : 'zero_income',
            'average_daily_expense' => $days > 0 ? round($expense / $days, 2) : 0,
            'transaction_count' => $transactions->count(),
            'income_to_expense_ratio' => $incomeToExpenseRatio !== null ? round($incomeToExpenseRatio, 4) : null,
            'income_to_expense_ratio_status' => match (true) {
                $expense > 0 => 'available',
                $income > 0 => 'zero_expense',
                default => 'no_activity',
            },
        ];
    }

    private function comparison(array $current, array $previous): array
    {
        return [
            'previous_income' => $previous['total_income'],
            'previous_expense' => $previous['total_expense'],
            'previous_net_cashflow' => $previous['net_cashflow'],
            'income' => $this->change($current['total_income'], $previous['total_income'], $current['transaction_count'], $previous['transaction_count']),
            'expense' => $this->change($current['total_expense'], $previous['total_expense'], $current['transaction_count'], $previous['transaction_count']),
            'net_cashflow' => $this->change($current['net_cashflow'], $previous['net_cashflow'], $current['transaction_count'], $previous['transaction_count']),
        ];
    }

    private function change(float $current, float $previous, int $currentCount, int $previousCount): array
    {
        $absolute = round($current - $previous, 2);

        if ($currentCount === 0 && $previousCount === 0) {
            return [
                'absolute_change' => 0,
                'percentage_change' => null,
                'direction' => 'unavailable',
                'status' => 'unavailable',
            ];
        }

        if ((float) $previous === 0.0) {
            return [
                'absolute_change' => $absolute,
                'percentage_change' => null,
                'direction' => $absolute > 0 ? 'increased' : ($absolute < 0 ? 'decreased' : 'unchanged'),
                'status' => 'zero_baseline',
            ];
        }

        $percentage = ($absolute / abs($previous)) * 100;

        return [
            'absolute_change' => $absolute,
            'percentage_change' => round($percentage, 2),
            'direction' => $absolute > 0 ? 'increased' : ($absolute < 0 ? 'decreased' : 'unchanged'),
            'status' => 'comparable',
        ];
    }

    private function categoryTrends(Collection $currentTransactions, Collection $previousTransactions): array
    {
        $current = $this->expenseByCategory($currentTransactions);
        $previous = $this->expenseByCategory($previousTransactions);
        $categoryIds = $current->keys()->merge($previous->keys())->unique()->values();
        $currentTotal = max(0.0, (float) $current->sum('amount'));

        return $categoryIds
            ->map(function ($categoryId) use ($current, $previous, $currentTotal) {
                $currentItem = $current->get($categoryId);
                $previousItem = $previous->get($categoryId);
                $currentAmount = (float) ($currentItem['amount'] ?? 0);
                $previousAmount = (float) ($previousItem['amount'] ?? 0);
                $change = $currentAmount - $previousAmount;
                $status = 'comparable';
                $percentage = null;

                if ($previousAmount == 0.0 && $currentAmount > 0) {
                    $status = 'new_activity';
                } elseif ($previousAmount > 0 && $currentAmount == 0.0) {
                    $status = 'stopped_activity';
                    $percentage = -100.0;
                } elseif ($previousAmount == 0.0 && $currentAmount == 0.0) {
                    $status = 'no_activity';
                } else {
                    $percentage = round(($change / $previousAmount) * 100, 2);
                }

                return [
                    'category_id' => $categoryId === 'uncategorized' ? null : (int) $categoryId,
                    'category_name' => $currentItem['category_name'] ?? $previousItem['category_name'] ?? 'Uncategorized',
                    'category_color' => $currentItem['category_color'] ?? $previousItem['category_color'] ?? null,
                    'current_amount' => round($currentAmount, 2),
                    'previous_amount' => round($previousAmount, 2),
                    'absolute_change' => round($change, 2),
                    'percentage_change' => $percentage,
                    'change_status' => $status,
                    'trend_direction' => $change > 0 ? 'increased' : ($change < 0 ? 'decreased' : 'unchanged'),
                    'share_of_current_expense' => $currentTotal > 0 ? round(($currentAmount / $currentTotal) * 100, 2) : 0,
                    'transaction_frequency' => (int) ($currentItem['count'] ?? 0),
                ];
            })
            ->sortByDesc('current_amount')
            ->values()
            ->all();
    }

    private function expenseDistribution(Collection $transactions): array
    {
        $items = $this->expenseByCategory($transactions);
        $total = max(0.0, (float) $items->sum('amount'));

        return $items
            ->sortByDesc('amount')
            ->map(function (array $item, string|int $categoryId) use ($total) {
                return [
                    'category_id' => $categoryId === 'uncategorized' ? null : (int) $categoryId,
                    'category_name' => $item['category_name'],
                    'category_color' => $item['category_color'],
                    'amount' => round($item['amount'], 2),
                    'share' => $total > 0 ? round(($item['amount'] / $total) * 100, 2) : 0,
                    'transaction_count' => $item['count'],
                ];
            })
            ->values()
            ->all();
    }

    private function expenseByCategory(Collection $transactions): Collection
    {
        return $transactions
            ->where('type', 'expense')
            ->groupBy(fn (Transaction $transaction) => $transaction->category_id ?: 'uncategorized')
            ->map(function (Collection $group) {
                /** @var Transaction $first */
                $first = $group->first();

                return [
                    'category_name' => $first->category?->name ?? 'Uncategorized',
                    'category_color' => $first->category?->color,
                    'amount' => $group->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction)),
                    'count' => $group->count(),
                ];
            });
    }

    private function largestExpenseCategory(Collection $transactions): ?array
    {
        return collect($this->expenseDistribution($transactions))->first();
    }

    private function largestTransactions(Collection $transactions): array
    {
        return $transactions
            ->sortByDesc(fn (Transaction $transaction) => $this->effectiveAmount($transaction))
            ->take(5)
            ->map(fn (Transaction $transaction) => [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'category_id' => $transaction->category_id,
                'category_name' => $transaction->category?->name,
                'source' => $transaction->source ?? 'manual',
                'amount' => (float) $transaction->amount,
                'fee' => (float) $transaction->fee,
                'effective_amount' => round($this->effectiveAmount($transaction), 2),
                'description' => $transaction->description ?? $transaction->note ?? $transaction->merchant,
                'happened_at' => $transaction->happened_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function sourceDistribution(Collection $transactions): array
    {
        $groups = [
            'manual' => ['label' => 'Manual', 'count' => 0, 'amount' => 0.0],
            'bank_csv' => ['label' => 'Bank CSV', 'count' => 0, 'amount' => 0.0],
            'ewallet_csv' => ['label' => 'E-Wallet CSV', 'count' => 0, 'amount' => 0.0],
            'stellar' => ['label' => 'Stellar', 'count' => 0, 'amount' => 0.0],
            'other' => ['label' => 'Other', 'count' => 0, 'amount' => 0.0],
        ];

        foreach ($transactions as $transaction) {
            $source = $transaction->source ?: 'manual';
            $key = array_key_exists($source, $groups) ? $source : 'other';
            $groups[$key]['count']++;
            $groups[$key]['amount'] += $this->effectiveAmount($transaction);
        }

        $total = max(0.0, array_sum(array_column($groups, 'amount')));

        return collect($groups)
            ->map(fn (array $item, string $source) => [
                'source' => $source,
                'label' => $item['label'],
                'count' => $item['count'],
                'amount' => round($item['amount'], 2),
                'share' => $total > 0 ? round(($item['amount'] / $total) * 100, 2) : 0,
            ])
            ->values()
            ->all();
    }

    private function budgetItems(User $user, array $period, array $filters): Collection
    {
        $months = $this->monthsInPeriod($period['start'], $period['end']);

        $budgets = Budget::with('category')
            ->where('user_id', $user->id)
            ->whereIn('month', $months)
            ->when(!empty($filters['category_id']), fn ($query) => $query->where('category_id', $filters['category_id']))
            ->orderBy('month')
            ->get();

        if ($budgets->isEmpty()) {
            return collect();
        }

        $categoryIds = $budgets->pluck('category_id')->filter()->unique()->values();
        $historyStart = CarbonImmutable::createFromFormat('Y-m', (string) $budgets->min('month'))->startOfMonth();
        $spentTransactions = $user->transactions()
            ->where('type', 'expense')
            ->whereIn('category_id', $categoryIds)
            ->whereBetween('happened_at', [$historyStart, $period['end']])
            ->when(!empty($filters['wallet_id']), fn ($query) => $query->where('wallet_id', $filters['wallet_id']))
            ->when(!empty($filters['source']), fn ($query) => $query->where('source', $filters['source']))
            ->orderBy('happened_at')
            ->get();

        return $budgets
            ->map(fn (Budget $budget) => $this->budgetItem($budget, $period, $spentTransactions));
    }

    private function budgetItem(Budget $budget, array $period, Collection $candidateTransactions): array
    {
        $monthStart = CarbonImmutable::createFromFormat('Y-m', $budget->month)->startOfMonth();
        $monthEnd = $monthStart->endOfMonth();
        $analysisDate = $period['end']->lt($monthEnd) ? $period['end'] : $monthEnd;
        $analysisDate = $analysisDate->lt($monthStart) ? $monthStart : $analysisDate;
        $analysisEnd = $analysisDate->endOfDay();
        $daysElapsed = (int) $monthStart->diffInDays($analysisDate->startOfDay()) + 1;
        $daysInMonth = $monthStart->daysInMonth;
        $daysRemaining = max(0, $daysInMonth - $daysElapsed);

        $spentTransactions = $candidateTransactions
            ->where('category_id', $budget->category_id)
            ->filter(fn (Transaction $transaction) => $transaction->happened_at !== null
                && $transaction->happened_at->gte($monthStart)
                && $transaction->happened_at->lte($analysisEnd))
            ->sortBy('happened_at')
            ->values();

        $spent = $spentTransactions->sum(fn (Transaction $transaction) => $this->effectiveAmount($transaction));
        $budgetAmount = (float) $budget->amount;
        $usage = $budgetAmount > 0 ? ($spent / $budgetAmount) * 100 : 0.0;
        $dailySpend = $daysElapsed > 0 ? $spent / $daysElapsed : 0.0;
        $expectedEnd = $dailySpend * $daysInMonth;
        $expectedUsage = $budgetAmount > 0 ? ($expectedEnd / $budgetAmount) * 100 : 0.0;

        return [
            'budget_id' => $budget->id,
            'category_id' => $budget->category_id,
            'category_name' => $budget->category?->name,
            'category_color' => $budget->category?->color,
            'month' => $budget->month,
            'period_start' => $monthStart->toDateString(),
            'period_end' => $monthEnd->toDateString(),
            'as_of_date' => $analysisDate->toDateString(),
            'budget_amount' => round($budgetAmount, 2),
            'amount_spent' => round($spent, 2),
            'remaining_amount' => round($budgetAmount - $spent, 2),
            'usage_percentage' => round($usage, 2),
            'days_elapsed' => $daysElapsed,
            'days_remaining' => $daysRemaining,
            'expected_spending_at_period_end' => round($expectedEnd, 2),
            'expected_usage_percentage' => round($expectedUsage, 2),
            'estimated_budget_exhaustion_date' => $this->estimatedExhaustionDate($spentTransactions, $budgetAmount, $dailySpend, $monthStart, $monthEnd),
            'severity' => $this->budgetSeverity($usage, $expectedUsage),
        ];
    }

    private function estimatedExhaustionDate(
        Collection $spentTransactions,
        float $budgetAmount,
        float $dailySpend,
        CarbonImmutable $monthStart,
        CarbonImmutable $monthEnd,
    ): ?string {
        if ($budgetAmount <= 0) {
            return null;
        }

        $running = 0.0;

        foreach ($spentTransactions as $transaction) {
            $running += $this->effectiveAmount($transaction);

            if ($running >= $budgetAmount) {
                return $transaction->happened_at?->toDateString();
            }
        }

        if ($dailySpend <= 0) {
            return null;
        }

        $daysUntilExhaustion = (int) ceil($budgetAmount / $dailySpend);
        $estimated = $monthStart->addDays(max(0, $daysUntilExhaustion - 1));

        return $estimated->lte($monthEnd) ? $estimated->toDateString() : null;
    }

    private function budgetSeverity(float $usagePercentage, float $expectedUsagePercentage): string
    {
        $thresholds = config('financial_intelligence.budget_thresholds');

        if ($usagePercentage >= (float) $thresholds['exceeded']) {
            return 'exceeded';
        }

        if ($usagePercentage >= (float) $thresholds['critical'] || $expectedUsagePercentage >= (float) $thresholds['exceeded']) {
            return 'critical';
        }

        if ($usagePercentage >= (float) $thresholds['warning']) {
            return 'warning';
        }

        if ($usagePercentage >= (float) $thresholds['notice']) {
            return 'notice';
        }

        return 'normal';
    }

    private function unusualAmountAnomalies(
        User $user,
        Collection $currentTransactions,
        array $period,
        array $filters,
        Collection $insufficientHistory,
    ): Collection {
        $minimum = (int) config('financial_intelligence.anomalies.minimum_category_history', 5);
        $reportedInsufficient = [];

        return $currentTransactions
            ->where('type', 'expense')
            ->filter(fn (Transaction $transaction) => $transaction->category_id !== null)
            ->flatMap(function (Transaction $transaction) use ($user, $period, $filters, $minimum, $insufficientHistory, &$reportedInsufficient) {
                $history = $this->historicalExpenses($user, $period['start'], (int) $transaction->category_id, $filters);

                if ($history->count() < $minimum) {
                    if (!isset($reportedInsufficient[$transaction->category_id])) {
                        $insufficientHistory->push([
                            'type' => 'unusual_amount',
                            'category_id' => $transaction->category_id,
                            'available_sample_size' => $history->count(),
                            'required_sample_size' => $minimum,
                            'status' => 'insufficient_history',
                        ]);
                        $reportedInsufficient[$transaction->category_id] = true;
                    }

                    return [];
                }

                $values = $history
                    ->map(fn (Transaction $item) => $this->effectiveAmount($item))
                    ->values()
                    ->all();
                $stats = $this->robustStats($values);
                $threshold = max(
                    $stats['q3'] + ((float) config('financial_intelligence.anomalies.iqr_multiplier', 1.5) * $stats['iqr']),
                    $stats['median'] + ((float) config('financial_intelligence.anomalies.mad_multiplier', 3) * $stats['mad']),
                    $stats['median'] * (float) config('financial_intelligence.anomalies.large_transaction_multiplier', 2.5),
                );
                $observed = $this->effectiveAmount($transaction);

                if ($observed <= $threshold) {
                    return [];
                }

                return [[
                    'type' => 'unusual_amount',
                    'severity' => $observed >= $threshold * 1.5 ? 'critical' : 'warning',
                    'transaction_id' => $transaction->id,
                    'category_id' => $transaction->category_id,
                    'metric' => 'amount',
                    'observed_value' => round($observed, 2),
                    'baseline_value' => round($stats['median'], 2),
                    'threshold_value' => round($threshold, 2),
                    'comparison_period_start' => null,
                    'comparison_period_end' => $period['start']->subDay()->toDateString(),
                    'explanation_code' => 'amount_above_category_baseline',
                ]];
            })
            ->values();
    }

    private function categorySpikeAnomalies(Collection $currentTransactions, Collection $previousTransactions, array $period): Collection
    {
        $minimumChange = (float) config('financial_intelligence.anomalies.category_spike_minimum_change', 10000);
        $multiplier = (float) config('financial_intelligence.anomalies.category_spike_multiplier', 2);

        return collect($this->categoryTrends($currentTransactions, $previousTransactions))
            ->filter(fn (array $trend) => $trend['previous_amount'] > 0
                && $trend['current_amount'] >= $trend['previous_amount'] * $multiplier
                && $trend['absolute_change'] >= $minimumChange)
            ->map(fn (array $trend) => [
                'type' => 'category_spending_increase',
                'severity' => $trend['current_amount'] >= $trend['previous_amount'] * 3 ? 'critical' : 'warning',
                'transaction_id' => null,
                'category_id' => $trend['category_id'],
                'metric' => 'category_expense',
                'observed_value' => $trend['current_amount'],
                'baseline_value' => $trend['previous_amount'],
                'threshold_value' => round($trend['previous_amount'] * $multiplier, 2),
                'comparison_period_start' => $period['previous_start']->toDateString(),
                'comparison_period_end' => $period['previous_end']->toDateString(),
                'explanation_code' => 'category_spending_above_previous_period',
            ])
            ->values();
    }

    private function frequencyAnomalies(
        User $user,
        Collection $currentTransactions,
        array $period,
        array $filters,
        Collection $insufficientHistory,
    ): Collection {
        $historyPeriods = (int) config('financial_intelligence.anomalies.frequency_history_periods', 4);
        $minimumPeriods = (int) config('financial_intelligence.anomalies.minimum_frequency_periods', 3);
        $days = $period['days'];
        $currentCounts = $currentTransactions
            ->where('type', 'expense')
            ->filter(fn (Transaction $transaction) => $transaction->category_id !== null)
            ->groupBy('category_id')
            ->map->count();

        return $currentCounts
            ->flatMap(function (int $currentCount, int $categoryId) use ($user, $period, $filters, $historyPeriods, $minimumPeriods, $days, $insufficientHistory) {
                $counts = collect();

                for ($i = 1; $i <= $historyPeriods; $i++) {
                    $end = $period['start']->subDays(($i - 1) * $days + 1)->endOfDay();
                    $start = $end->subDays($days - 1)->startOfDay();
                    $count = $user->transactions()
                        ->where('type', 'expense')
                        ->where('category_id', $categoryId)
                        ->whereBetween('happened_at', [$start, $end])
                        ->when(!empty($filters['wallet_id']), fn ($query) => $query->where('wallet_id', $filters['wallet_id']))
                        ->when(!empty($filters['source']), fn ($query) => $query->where('source', $filters['source']))
                        ->count();
                    $counts->push($count);
                }

                if ($counts->sum() < $minimumPeriods) {
                    $insufficientHistory->push([
                        'type' => 'unusual_frequency',
                        'category_id' => $categoryId,
                        'available_sample_size' => (int) $counts->sum(),
                        'required_sample_size' => $minimumPeriods,
                        'status' => 'insufficient_history',
                    ]);

                    return [];
                }

                $stats = $this->robustStats($counts->all());
                $threshold = max(
                    $stats['q3'] + ((float) config('financial_intelligence.anomalies.iqr_multiplier', 1.5) * $stats['iqr']),
                    $stats['median'] + ((float) config('financial_intelligence.anomalies.mad_multiplier', 3) * $stats['mad']),
                    $stats['median'] + 2,
                );

                if ($currentCount <= $threshold) {
                    return [];
                }

                return [[
                    'type' => 'unusual_frequency',
                    'severity' => $currentCount >= $threshold * 1.5 ? 'critical' : 'notice',
                    'transaction_id' => null,
                    'category_id' => $categoryId,
                    'metric' => 'transaction_frequency',
                    'observed_value' => $currentCount,
                    'baseline_value' => round($stats['median'], 2),
                    'threshold_value' => round($threshold, 2),
                    'comparison_period_start' => $period['start']->subDays($days * $historyPeriods)->toDateString(),
                    'comparison_period_end' => $period['start']->subDay()->toDateString(),
                    'explanation_code' => 'frequency_above_historical_baseline',
                ]];
            })
            ->values();
    }

    private function budgetOverspendingAnomalies(
        User $user,
        array $period,
        array $filters,
        ?Collection $budgetItems = null,
    ): Collection
    {
        return ($budgetItems ?? $this->budgetItems($user, $period, $filters))
            ->whereIn('severity', ['exceeded'])
            ->map(fn (array $budget) => [
                'type' => 'budget_overspending',
                'severity' => 'critical',
                'transaction_id' => null,
                'category_id' => $budget['category_id'],
                'metric' => 'budget_usage_percentage',
                'observed_value' => $budget['usage_percentage'],
                'baseline_value' => 100,
                'threshold_value' => 100,
                'comparison_period_start' => $budget['period_start'],
                'comparison_period_end' => $budget['period_end'],
                'explanation_code' => 'budget_exceeded',
            ])
            ->values();
    }

    private function duplicateLikeAnomalies(Collection $transactions, array $period): Collection
    {
        $window = (int) config('financial_intelligence.anomalies.duplicate_window_minutes', 30);

        return $transactions
            ->groupBy(fn (Transaction $transaction) => implode('|', [
                $transaction->type,
                $transaction->source ?? 'manual',
                (string) round($this->effectiveAmount($transaction), 2),
                $transaction->normalized_merchant ?: $transaction->normalized_description ?: $transaction->merchant ?: $transaction->description ?: '',
            ]))
            ->filter(fn (Collection $group, string $key) => $group->count() > 1 && !str_ends_with($key, '|'))
            ->flatMap(function (Collection $group) use ($window, $period) {
                $sorted = $group->sortBy('happened_at')->values();
                $items = [];

                for ($i = 1; $i < $sorted->count(); $i++) {
                    $previous = $sorted[$i - 1];
                    $current = $sorted[$i];
                    $minutes = abs($previous->happened_at->diffInMinutes($current->happened_at));

                    if ($minutes <= $window) {
                        $items[] = [
                            'type' => 'duplicate_like_transaction',
                            'severity' => 'notice',
                            'transaction_id' => $current->id,
                            'related_transaction_id' => $previous->id,
                            'category_id' => $current->category_id,
                            'metric' => 'duplicate_window_minutes',
                            'observed_value' => $minutes,
                            'baseline_value' => 0,
                            'threshold_value' => $window,
                            'comparison_period_start' => $period['start']->toDateString(),
                            'comparison_period_end' => $period['end']->toDateString(),
                            'explanation_code' => 'similar_transaction_close_together',
                        ];
                    }
                }

                return $items;
            })
            ->values();
    }

    private function negativeCashflowAnomalies(array $metrics, array $previousMetrics, array $period): Collection
    {
        if ($metrics['net_cashflow'] >= 0) {
            return collect();
        }

        $threshold = (float) config('financial_intelligence.anomalies.negative_cashflow_rate_threshold', -20);
        $severity = $metrics['savings_rate'] === null || $metrics['savings_rate'] <= $threshold
            ? 'critical'
            : 'warning';

        return collect([[
            'type' => 'negative_cashflow_risk',
            'severity' => $severity,
            'transaction_id' => null,
            'category_id' => null,
            'metric' => 'net_cashflow',
            'observed_value' => $metrics['net_cashflow'],
            'baseline_value' => $previousMetrics['net_cashflow'],
            'threshold_value' => 0,
            'comparison_period_start' => $period['previous_start']->toDateString(),
            'comparison_period_end' => $period['previous_end']->toDateString(),
            'explanation_code' => 'period_expense_exceeds_income',
        ]]);
    }

    private function monthsInPeriod(CarbonImmutable $start, CarbonImmutable $end): array
    {
        $months = [];
        $cursor = $start->startOfMonth();

        while ($cursor->lte($end)) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonthNoOverflow();
        }

        return $months;
    }

    private function robustStats(array $values): array
    {
        sort($values);
        $median = $this->percentile($values, 50);
        $q1 = $this->percentile($values, 25);
        $q3 = $this->percentile($values, 75);
        $deviations = array_map(fn ($value) => abs($value - $median), $values);
        sort($deviations);

        return [
            'median' => $median,
            'q1' => $q1,
            'q3' => $q3,
            'iqr' => $q3 - $q1,
            'mad' => $this->percentile($deviations, 50),
        ];
    }

    private function percentile(array $values, int $percentile): float
    {
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $index = ($percentile / 100) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        $weight = $index - $lower;

        return ((float) $values[$lower] * (1 - $weight)) + ((float) $values[$upper] * $weight);
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'exceeded', 'critical' => 5,
            'warning' => 4,
            'notice' => 3,
            'normal' => 1,
            default => 0,
        };
    }

    private function effectiveAmount(Transaction $transaction): float
    {
        $amount = (float) $transaction->amount;

        if ($transaction->type === 'expense') {
            return $amount + (float) $transaction->fee;
        }

        return $amount;
    }
}
