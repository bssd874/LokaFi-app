import { apiClient } from "../../api/client";
import type {
    CreateTransactionPayload,
    BulkCategoryResponse,
    CategorizationStatus,
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
        `/transactions/${id}/category`,
        { category_id: categoryId },
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
