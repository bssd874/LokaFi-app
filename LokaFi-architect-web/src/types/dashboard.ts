export type DashboardWallet = {
    id: number;
    name: string;
    type: string;
    currency: string;
};

export type DashboardCategory = {
    id: number;
    name: string;
    color?: string | null;
};

export type DashboardTransaction = {
    id: number;
    type: "income" | "expense";
    source: "manual" | "bank_csv" | "ewallet_csv" | "stellar" | string;
    wallet?: DashboardWallet | null;
    category?: DashboardCategory | null;
    amount: number;
    fee: number;
    effective_amount: number;
    currency: string;
    merchant?: string | null;
    description?: string | null;
    happened_at: string;
};

export type DashboardDailyCashflow = {
    date: string;
    income: number;
    expense: number;
};

export type DashboardExpenseDistribution = {
    category_id: number | null;
    category_name: string;
    category_color?: string | null;
    amount: number;
    share: number;
    transaction_count: number;
};

export type DashboardSourceDistribution = {
    source: "manual" | "bank_csv" | "ewallet_csv" | "stellar" | string;
    label: string;
    count: number;
    amount: number;
    share: number;
};

export type DashboardBudgetAlert = {
    budget_id: number;
    category_id: number;
    category_name?: string | null;
    category_color?: string | null;
    budget_amount: number;
    amount_spent: number;
    remaining_amount: number;
    usage_percentage: number;
    severity: string;
    estimated_budget_exhaustion_date?: string | null;
};

export type DashboardAnomaly = {
    type: string;
    severity: string;
    transaction_id?: number | null;
    category_id?: number | null;
    metric: string;
    observed_value: number;
    baseline_value: number;
    threshold_value: number;
    explanation_code: string;
};

export type DashboardSummary = {
    period: {
        start_date: string;
        end_date: string;
        timezone: string;
    };
    filters: {
        wallet_id: number | null;
        source: string | null;
    };
    summary: {
        total_balance: number;
        total_income: number;
        total_expense: number;
        net_cashflow: number;
        transactions_count: number;
    };
    comparison: {
        previous_income: number;
        previous_expense: number;
        previous_net_cashflow: number;
        income_change_percentage: number | null;
        expense_change_percentage: number | null;
        net_cashflow_change_percentage: number | null;
        status: "available" | "unavailable";
    };
    daily_cashflow: DashboardDailyCashflow[];
    expense_distribution: DashboardExpenseDistribution[];
    source_distribution: DashboardSourceDistribution[];
    recent_transactions: DashboardTransaction[];
    budget_alerts: DashboardBudgetAlert[];
    anomalies: DashboardAnomaly[];
    generated_at: string;
};

export type DashboardSummaryResponse = {
    message: string;
    data: DashboardSummary;
};
