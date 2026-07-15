import { apiClient } from "../../api/client";
import type {
    TransactionImportListResponse,
    TransactionImportMapping,
    TransactionImportResultResponse,
    TransactionImportSourceType,
} from "../../types/transactionImport";

export async function previewTransactionImport(payload: {
    source_type: TransactionImportSourceType;
    wallet_id: number;
    provider_code?: string;
    file: File;
}) {
    const formData = new FormData();
    formData.append("source_type", payload.source_type);
    formData.append("wallet_id", String(payload.wallet_id));
    formData.append("file", payload.file);

    if (payload.provider_code) {
        formData.append("provider_code", payload.provider_code);
    }

    const response = await apiClient.post<TransactionImportResultResponse>(
        "/transaction-imports/preview",
        formData,
    );

    return response.data.data;
}

export async function commitTransactionImport(payload: {
    batch_id: number;
    mapping: TransactionImportMapping;
}) {
    const response = await apiClient.post<TransactionImportResultResponse>(
        "/transaction-imports/commit",
        payload,
    );

    return response.data.data;
}

export async function getTransactionImports() {
    const response = await apiClient.get<TransactionImportListResponse>(
        "/transaction-imports",
    );

    return response.data.data;
}
