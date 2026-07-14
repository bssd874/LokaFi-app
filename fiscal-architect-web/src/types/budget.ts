import type { Category } from "./category";

export type Budget = {
    id: number;
    user_id: number;
    category_id: number;
    month: string;
    amount: string;
    created_at: string;
    updated_at: string;
    category?: Category;
};

export type CreateBudgetPayload = {
    category_id: number;
    month: string;
    amount: number;
};

export type BudgetListResponse = {
    message: string;
    data: Budget[];
};

export type BudgetResponse = {
    message: string;
    data: Budget;
};

export type BudgetProgressItem = {
    budget_id: number;
    category_id: number;
    category_name: string;
    category_color?: string | null;
    month: string;
    budget_amount: number;
    spent_amount: number;
    remaining_amount: number;
    percentage: number;
    status: "safe" | "warning" | "over_budget";
};

export type BudgetProgressResponse = {
    message: string;
    data: {
        month: string;
        total_budget: number;
        total_spent: number;
        total_remaining: number;
        items: BudgetProgressItem[];
    };
};