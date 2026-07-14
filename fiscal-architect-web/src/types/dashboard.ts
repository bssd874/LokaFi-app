export type Wallet = {
    id: number;
    name: string;
    type: string;
    currency: string;
    opening_balance: string;
    current_balance: string;
};

export type Category = {
    id: number;
    name: string;
    type: "income" | "expense";
    icon?: string | null;
    color?: string | null;
};

export type Transaction = {
    id: number;
    type: "income" | "expense" | "transfer";
    wallet_id?: number | null;
    from_wallet_id?: number | null;
    to_wallet_id?: number | null;
    category_id?: number | null;
    amount: string;
    fee: string;
    currency: string;
    merchant?: string | null;
    note?: string | null;
    reference_code?: string | null;
    happened_at: string;
    wallet?: Wallet | null;
    from_wallet?: Wallet | null;
    to_wallet?: Wallet | null;
    category?: Category | null;
};

export type ExpenseByCategory = {
    category_id: number;
    category_name: string;
    category_color?: string | null;
    total: string;
};

export type DailyCashflow = {
    date: string;
    income: number;
    expense: number;
};

export type DashboardSummary = {
    period: {
        from: string;
        to: string;
    };
    summary: {
        total_balance: number;
        total_income: number;
        total_expense: number;
        net_cashflow: number;
        wallets_count: number;
        transactions_count: number;
    };
    recent_transactions: Transaction[];
    expense_by_category: ExpenseByCategory[];
    daily_cashflow: DailyCashflow[];
};

export type DashboardSummaryResponse = {
    message: string;
    data: DashboardSummary;
};