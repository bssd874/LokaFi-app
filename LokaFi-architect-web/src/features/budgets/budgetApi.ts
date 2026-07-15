import { apiClient } from "../../api/client";
import type {
    BudgetListResponse,
    BudgetProgressResponse,
    BudgetResponse,
    CreateBudgetPayload,
} from "../../types/budget";

export async function getBudgets(month: string) {
    const response = await apiClient.get<BudgetListResponse>("/budgets", {
        params: { month },
    });

    return response.data.data;
}

export async function createOrUpdateBudget(payload: CreateBudgetPayload) {
    const response = await apiClient.post<BudgetResponse>("/budgets", payload);
    return response.data.data;
}

export async function getBudgetProgress(month: string) {
    const response = await apiClient.get<BudgetProgressResponse>(
        "/budgets/progress",
        {
            params: { month },
        }
    );

    return response.data.data;
}

export async function deleteBudget(id: number) {
    const response = await apiClient.delete<{ message: string }>(`/budgets/${id}`);
    return response.data;
}