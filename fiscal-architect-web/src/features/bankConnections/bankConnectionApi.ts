import { apiClient } from "../../api/client";
import type {
    BankConnectionActionResponse,
    BankConnectionListResponse,
    BankProviderListResponse,
    ConnectBankPayload,
} from "../../types/bankConnection";

export async function getBankProviders() {
    const response = await apiClient.get<BankProviderListResponse>(
        "/bank-providers"
    );

    return response.data.data;
}

export async function getBankConnections() {
    const response = await apiClient.get<BankConnectionListResponse>(
        "/bank-connections"
    );

    return response.data.data;
}

export async function connectBank(payload: ConnectBankPayload) {
    const response = await apiClient.post<BankConnectionActionResponse>(
        "/bank-connections/start",
        payload
    );

    return response.data.data;
}

export async function syncBankConnection(id: number) {
    const response = await apiClient.post<BankConnectionActionResponse>(
        `/bank-connections/${id}/sync`
    );

    return response.data.data;
}

export async function revokeBankConnection(id: number) {
    const response = await apiClient.delete<{ message: string }>(
        `/bank-connections/${id}`
    );

    return response.data;
}
