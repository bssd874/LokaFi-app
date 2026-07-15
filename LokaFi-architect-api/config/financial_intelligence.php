<?php

return [
    'calculation_version' => 'financial_analytics_v1',
    'max_period_days' => 366,

    'budget_thresholds' => [
        'notice' => 70,
        'warning' => 85,
        'critical' => 95,
        'exceeded' => 100,
    ],

    'anomalies' => [
        'minimum_category_history' => 5,
        'minimum_frequency_periods' => 3,
        'frequency_history_periods' => 4,
        'iqr_multiplier' => 1.5,
        'mad_multiplier' => 3,
        'large_transaction_multiplier' => 2.5,
        'category_spike_multiplier' => 2,
        'category_spike_minimum_change' => 10000,
        'duplicate_window_minutes' => 30,
        'negative_cashflow_rate_threshold' => -20,
    ],
];
