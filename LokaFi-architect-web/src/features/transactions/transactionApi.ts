import { apiClient } from "../../api/client";
import type {
    CreateTransactionPayload,
    AiCategorizePendingResponse,
    AiCategorySuggestionResponse,
    BulkCategoryResponse,
    CategorizationStatus,
    ReprocessCategorizationResponse,
    TransactionCategorySuggestionResponse,
    TransactionListResponse,
    TransactionResponse,
    TransactionType,
} from "../../types/transaction";

type TransactionParams = {
    type?: TransactionType | "";
    wallet_id?: number | "";
    category_id?: number | "";
    categorization_status?: CategorizationStatus | "";
    search?: string;
    from?: string;
    to?: string;
};

export async function getTransactions(params?: TransactionParams) {
    const response = await apiClient.get<TransactionListResponse>("/transactions", {
        params,
    });

    return response.data.data;
}

export async function createTransaction(payload: CreateTransactionPayload) {
    const response = await apiClient.post<TransactionResponse>(
        "/transactions",
        payload
    );

    return response.data.data;
}

export async function deleteTransaction(id: number) {
    const response = await apiClient.delete<{ message: string }>(
        `/transactions/${id}`
    );

    return response.data;
}

export async function updateTransactionCategory(id: number, categoryId: number) {
    const response = await apiClient.patch<TransactionResponse>(
        `/transactions/${id}/category/correct`,
        { category_id: categoryId },
    );

    return response.data.data;
}

export async function suggestTransactionCategory(id: number) {
    const response = await apiClient.get<TransactionCategorySuggestionResponse>(
        `/transactions/${id}/category-suggestion`,
    );

    return response.data.data;
}

export async function acceptTransactionCategorySuggestion(id: number) {
    const response = await apiClient.post<TransactionResponse>(
        `/transactions/${id}/category-suggestion/accept`,
    );

    return response.data.data;
}

export async function askAiTransactionCategory(id: number) {
    const response = await apiClient.post<AiCategorySuggestionResponse>(
        `/transactions/${id}/ai-category-suggestion`,
    );

    return response.data.data;
}

export async function acceptAiTransactionCategory(id: number) {
    const response = await apiClient.post<TransactionResponse>(
        `/transactions/${id}/accept-ai-category`,
    );

    return response.data.data;
}

export async function aiCategorizePending(limit?: number) {
    const response = await apiClient.post<AiCategorizePendingResponse>(
        "/transactions/ai-categorize-pending",
        limit ? { limit } : {},
    );

    return response.data.data;
}

export async function getReviewRequiredTransactions() {
    const response = await apiClient.get<TransactionListResponse>(
        "/transactions/review-required",
    );

    return response.data.data;
}

export async function reprocessTransactionCategorization(transactionIds: number[]) {
    const response = await apiClient.post<ReprocessCategorizationResponse>(
        "/transactions/reprocess-categorization",
        {
            transaction_ids: transactionIds,
        },
    );

    return response.data.data;
}

export async function bulkCategorizeTransactions(transactionIds: number[], categoryId: number) {
    const response = await apiClient.post<BulkCategoryResponse>(
        "/transactions/bulk-category",
        {
            transaction_ids: transactionIds,
            category_id: categoryId,
        },
    );

    return response.data.data;
}
