import { apiClient } from "../../api/client";
import type { DatasetSummaryResponse } from "../../types/dataset";

export async function getDatasetSummary() {
    const response = await apiClient.get<DatasetSummaryResponse>(
        "/transaction-dataset/summary",
    );

    return response.data.data;
}

export async function exportDatasetCsv() {
    const response = await apiClient.get<Blob>("/transaction-dataset/export", {
        responseType: "blob",
    });

    return response.data;
}
