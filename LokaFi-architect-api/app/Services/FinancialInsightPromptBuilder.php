<?php

namespace App\Services;

use App\Models\User;

class FinancialInsightPromptBuilder
{
    public function __construct(
        private readonly FinancialIntelligenceService $financialIntelligence,
    ) {
    }

    public function build(User $user, array $filters): array
    {
        $summary = $this->financialIntelligence->summary($user, $filters);
        $trends = $this->financialIntelligence->trends($user, $filters);
        $budgetAlerts = $this->financialIntelligence->budgetAlerts($user, $filters);
        $anomalies = $this->financialIntelligence->anomalies($user, array_merge($filters, [
            'page' => 1,
            'per_page' => 10,
        ]));
        $supportingMetrics = $this->supportingMetrics($summary, $trends, $budgetAlerts, $anomalies);

        $input = [
            'task' => 'financial_insight',
            'prompt_version' => $this->promptVersion(),
            'language' => 'id-ID',
            'tone' => 'praktis, jelas, dan non-menggurui',
            'calculation_version' => $summary['calculation_version'],
            'period' => [
                'start' => $summary['period']['start_date'],
                'end' => $summary['period']['end_date'],
                'days' => $summary['period']['days'],
            ],
            'comparison_period' => [
                'start' => $summary['comparison_period']['start_date'],
                'end' => $summary['comparison_period']['end_date'],
            ],
            'financial_summary' => [
                'income' => $summary['summary']['total_income'],
                'expense' => $summary['summary']['total_expense'],
                'net_cashflow' => $summary['summary']['net_cashflow'],
                'savings_rate' => $summary['summary']['savings_rate'],
                'average_daily_expense' => $summary['summary']['average_daily_expense'],
                'transaction_count' => $summary['summary']['transaction_count'],
            ],
            'comparison' => [
                'income_change_percentage' => $summary['comparison']['income']['percentage_change'],
                'expense_change_percentage' => $summary['comparison']['expense']['percentage_change'],
                'net_cashflow_change_percentage' => $summary['comparison']['net_cashflow']['percentage_change'],
                'income_status' => $summary['comparison']['income']['status'],
                'expense_status' => $summary['comparison']['expense']['status'],
                'net_cashflow_status' => $summary['comparison']['net_cashflow']['status'],
            ],
            'largest_expense_category' => $summary['largest_expense_category'] ? [
                'category_id' => $summary['largest_expense_category']['category_id'],
                'name' => $summary['largest_expense_category']['category_name'],
                'amount' => $summary['largest_expense_category']['amount'],
                'share_percentage' => $summary['largest_expense_category']['share'],
            ] : null,
            'category_trends' => collect($trends['category_trends'])->take(6)->values()->all(),
            'budget_alerts' => [
                'summary' => $budgetAlerts['summary'],
                'items' => collect($budgetAlerts['items'])
                    ->whereIn('severity', ['notice', 'warning', 'critical', 'exceeded'])
                    ->take(8)
                    ->values()
                    ->all(),
            ],
            'anomalies' => [
                'items' => collect($anomalies['items'])->take(8)->values()->all(),
                'insufficient_history' => $anomalies['insufficient_history'],
            ],
            'source_distribution' => $summary['source_distribution'],
            'data_quality' => [
                'has_sufficient_history' => count($anomalies['insufficient_history']) === 0,
                'insufficient_history_count' => count($anomalies['insufficient_history']),
            ],
            'allowed_evidence_keys' => array_keys($supportingMetrics),
            'evidence_catalog' => collect($supportingMetrics)
                ->map(fn (array $metric, string $key) => [
                    'key' => $key,
                    'label' => $metric['label'],
                    'value_type' => $metric['value_type'],
                ])
                ->values()
                ->all(),
            'output_rules' => [
                'write_all_text_in_indonesian',
                'make_actions_practical_and_direct',
                'do_not_include_numbers_in_text',
                'use_evidence_keys_for_metrics',
                'use_supporting_metrics_for_targets_such_as_twenty_percent_reduction',
                'do_not_recommend_financial_products',
                'informational_only',
            ],
            'provider_options' => [
                'model' => $this->modelName(),
                'timeout_seconds' => (int) config('services.ai.financial_insights.timeout_seconds', 30),
            ],
        ];

        return [
            'input' => $input,
            'input_hash' => hash('sha256', json_encode($input, JSON_THROW_ON_ERROR)),
            'supporting_metrics' => $supportingMetrics,
            'analytics' => [
                'summary' => $summary,
                'trends' => $trends,
                'budget_alerts' => $budgetAlerts,
                'anomalies' => $anomalies,
            ],
        ];
    }

    public function promptVersion(): string
    {
        return (string) config('services.ai.financial_insights.prompt_version', 'financial_insight_id_v2');
    }

    public function modelName(): ?string
    {
        $model = config('services.ai.financial_insights.model') ?: config('services.ai.model');

        return is_string($model) && $model !== '' ? $model : null;
    }

    private function supportingMetrics(array $summary, array $trends, array $budgetAlerts, array $anomalies): array
    {
        $metrics = [
            'summary.total_income' => $this->metric('Total pemasukan', $summary['summary']['total_income'], 'currency'),
            'summary.total_expense' => $this->metric('Total pengeluaran', $summary['summary']['total_expense'], 'currency'),
            'summary.net_cashflow' => $this->metric('Arus kas bersih', $summary['summary']['net_cashflow'], 'currency'),
            'summary.savings_rate' => $this->metric('Rasio tabungan', $summary['summary']['savings_rate'], 'percentage'),
            'summary.average_daily_expense' => $this->metric('Rata-rata pengeluaran harian', $summary['summary']['average_daily_expense'], 'currency'),
            'comparison.income' => $this->metric('Perubahan pemasukan', $summary['comparison']['income']['percentage_change'], 'percentage', $summary['comparison']['income']['status']),
            'comparison.expense' => $this->metric('Perubahan pengeluaran', $summary['comparison']['expense']['percentage_change'], 'percentage', $summary['comparison']['expense']['status']),
            'comparison.net_cashflow' => $this->metric('Perubahan arus kas bersih', $summary['comparison']['net_cashflow']['percentage_change'], 'percentage', $summary['comparison']['net_cashflow']['status']),
            'budget_alerts.alerts_count' => $this->metric('Jumlah peringatan budget', $budgetAlerts['summary']['alerts_count'], 'count'),
            'budget_alerts.total_remaining' => $this->metric('Sisa budget', $budgetAlerts['summary']['total_remaining'], 'currency'),
            'anomalies.count' => $this->metric('Jumlah aktivitas tidak biasa', $anomalies['pagination']['total'], 'count'),
            'data_quality.insufficient_history_count' => $this->metric('Sinyal data belum cukup', count($anomalies['insufficient_history']), 'count'),
        ];

        if ((float) $summary['summary']['net_cashflow'] > 0) {
            $metrics['action.safe_savings_allocation'] = $this->metric(
                'Target alokasi aman ke tabungan dari surplus',
                round((float) $summary['summary']['net_cashflow'] * 0.25),
                'currency',
                '25% dari arus kas bersih',
            );
        }

        if ($summary['largest_expense_category']) {
            $metrics['largest_expense_category.amount'] = $this->metric(
                'Nominal kategori pengeluaran terbesar',
                $summary['largest_expense_category']['amount'],
                'currency',
                $summary['largest_expense_category']['category_name'],
            );
            $metrics['largest_expense_category.share'] = $this->metric(
                'Porsi kategori pengeluaran terbesar',
                $summary['largest_expense_category']['share'],
                'percentage',
                $summary['largest_expense_category']['category_name'],
            );
            $metrics['action.largest_expense_reduction_20pct'] = $this->metric(
                'Target hemat 20% dari kategori terbesar',
                round((float) $summary['largest_expense_category']['amount'] * 0.2),
                'currency',
                $summary['largest_expense_category']['category_name'],
            );
        }

        foreach (collect($trends['category_trends'])->take(3)->values() as $index => $trend) {
            $key = 'category_trends.top_' . ($index + 1);
            $metrics[$key] = $this->metric(
                'Tren kategori: ' . $trend['category_name'],
                $trend['current_amount'],
                'currency',
                $trend['change_status'],
            );
        }

        foreach (collect($summary['source_distribution'])->where('count', '>', 0)->take(4)->values() as $source) {
            $metrics['source_distribution.' . $source['source']] = $this->metric(
                'Sumber transaksi: ' . $source['label'],
                $source['amount'],
                'currency',
                'count=' . $source['count'],
            );
        }

        return $metrics;
    }

    private function metric(string $label, mixed $value, string $valueType, ?string $status = null): array
    {
        return [
            'label' => $label,
            'value' => $value,
            'value_type' => $valueType,
            'status' => $status,
        ];
    }
}
