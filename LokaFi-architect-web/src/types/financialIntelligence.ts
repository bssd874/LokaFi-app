export type TrendDirection = "increased" | "decreased" | "unchanged" | "unavailable";
export type Severity = "normal" | "notice" | "warning" | "critical" | "exceeded";

export type FinancialIntelligenceParams = {
    start_date?: string;
    end_date?: string;
    wallet_id?: number;
    source?: string;
    category_id?: number;
    page?: number;
    per_page?: number;
};

export type FinancialEnvelope = {
    calculation_version: string;
    generated_at: string;
    period: {
        start_date: string;
        end_date: string;
        days: number;
    };
    comparison_period: {
        start_date: string;
        end_date: string;
        days: number;
    };
    filters: {
        wallet_id?: number | null;
        source?: string | null;
        category_id?: number | null;
    };
};

export type FinancialSummaryMetrics = {
    total_income: number;
    total_expense: number;
    net_cashflow: number;
    savings_amount: number;
    savings_rate: number | null;
    savings_rate_status: string;
    average_daily_expense: number;
    transaction_count: number;
    income_to_expense_ratio: number | null;
    income_to_expense_ratio_status: string;
};

export type FinancialChange = {
    absolute_change: number;
    percentage_change: number | null;
    direction: TrendDirection;
    status: string;
};

export type FinancialComparison = {
    previous_income: number;
    previous_expense: number;
    previous_net_cashflow: number;
    income: FinancialChange;
    expense: FinancialChange;
    net_cashflow: FinancialChange;
};

export type ExpenseDistributionItem = {
    category_id: number | null;
    category_name: string;
    category_color?: string | null;
    amount: number;
    share: number;
    transaction_count: number;
};

export type LargestTransactionItem = {
    id: number;
    type: "income" | "expense";
    category_id?: number | null;
    category_name?: string | null;
    source: string;
    amount: number;
    fee: number;
    effective_amount: number;
    description?: string | null;
    happened_at?: string | null;
};

export type SourceDistributionItem = {
    source: string;
    label: string;
    count: number;
    amount: number;
    share: number;
};

export type FinancialSummary = FinancialEnvelope & {
    summary: FinancialSummaryMetrics;
    comparison: FinancialComparison;
    expense_distribution_by_category: ExpenseDistributionItem[];
    largest_expense_category: ExpenseDistributionItem | null;
    largest_transactions: LargestTransactionItem[];
    source_distribution: SourceDistributionItem[];
};

export type CategoryTrendItem = {
    category_id: number | null;
    category_name: string;
    category_color?: string | null;
    current_amount: number;
    previous_amount: number;
    absolute_change: number;
    percentage_change: number | null;
    change_status: string;
    trend_direction: TrendDirection;
    share_of_current_expense: number;
    transaction_frequency: number;
};

export type FinancialTrends = FinancialEnvelope & {
    category_trends: CategoryTrendItem[];
};

export type BudgetAlertItem = {
    budget_id: number;
    category_id: number;
    category_name: string;
    category_color?: string | null;
    month: string;
    period_start: string;
    period_end: string;
    as_of_date: string;
    budget_amount: number;
    amount_spent: number;
    remaining_amount: number;
    usage_percentage: number;
    days_elapsed: number;
    days_remaining: number;
    expected_spending_at_period_end: number;
    expected_usage_percentage: number;
    estimated_budget_exhaustion_date?: string | null;
    severity: Severity;
};

export type BudgetAlerts = FinancialEnvelope & {
    thresholds: Record<string, number>;
    items: BudgetAlertItem[];
    summary: {
        total_budget: number;
        total_spent: number;
        total_remaining: number;
        alerts_count: number;
    };
};

export type AnomalyItem = {
    type: string;
    severity: Exclude<Severity, "normal"> | "normal";
    transaction_id?: number | null;
    related_transaction_id?: number | null;
    category_id?: number | null;
    metric: string;
    observed_value: number;
    baseline_value: number;
    threshold_value: number;
    comparison_period_start?: string | null;
    comparison_period_end?: string | null;
    explanation_code: string;
};

export type InsufficientHistoryItem = {
    type: string;
    category_id?: number | null;
    available_sample_size: number;
    required_sample_size: number;
    status: "insufficient_history";
};

export type FinancialAnomalies = FinancialEnvelope & {
    items: AnomalyItem[];
    pagination: {
        page: number;
        per_page: number;
        total: number;
    };
    insufficient_history: InsufficientHistoryItem[];
};

export type FinancialResponse<T> = {
    message: string;
    data: T;
};

export type FinancialInsightHighlight = {
    type: "positive" | "warning" | "critical" | "neutral";
    title: string;
    description: string;
    evidence_keys: string[];
};

export type FinancialInsightAction = {
    priority: "high" | "medium" | "low";
    title: string;
    description: string;
    related_metric?: string | null;
};

export type FinancialInsight = {
    headline: string;
    summary: string;
    highlights: FinancialInsightHighlight[];
    recommended_actions: FinancialInsightAction[];
    disclaimer: string;
};

export type SupportingMetric = {
    label: string;
    value: number | string | null;
    value_type: "currency" | "percentage" | "count" | string;
    status?: string | null;
};

export type FinancialInsightResult = {
    insight: FinancialInsight | null;
    record: {
        id: number;
        period_start: string;
        period_end: string;
        analytics_version: string;
        provider?: string | null;
        model?: string | null;
        prompt_version: string;
        validation_status: string;
        generated_at?: string | null;
        expires_at?: string | null;
        cached: boolean;
    } | null;
    validation_status: string;
    error_code?: string | null;
    cached: boolean;
    input_hash: string;
    supporting_metrics: Record<string, SupportingMetric>;
    analytics_period: {
        start: string;
        end: string;
        days: number;
    };
    calculation_version: string;
    prompt_version: string;
    user_message: string;
    disclaimer: string;
};

export type FinancialInsightHistory = {
    items: Array<{
        id: number;
        period_start: string;
        period_end: string;
        analytics_version: string;
        provider?: string | null;
        model?: string | null;
        prompt_version: string;
        validation_status: string;
        headline?: string | null;
        generated_at?: string | null;
        expires_at?: string | null;
    }>;
    pagination: {
        current_page: number;
        per_page: number;
        total: number;
    };
};
