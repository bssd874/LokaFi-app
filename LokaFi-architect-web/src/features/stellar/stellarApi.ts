import { apiClient } from "../../api/client";
import type {
    CreateStellarWalletPayload,
    NullableStellarWalletResponse,
    StellarWalletResponse,
} from "../../types/stellar";

type ApiErrorShape = {
    response?: {
        status?: number;
        data?: {
            message?: string;
            errors?: Record<string, string[]>;
        };
    };
};

function logBackendStatus(action: string, status?: number) {
    if (!import.meta.env.DEV) return;

    console.debug("[stellar] backend status", {
        action,
        status,
    });
}

function logBackendValidation(action: string, error: unknown) {
    if (!import.meta.env.DEV) return;

    const apiError = error as ApiErrorShape;

    console.debug("[stellar] backend validation response", {
        action,
        status: apiError.response?.status,
        message: apiError.response?.data?.message,
        errors: apiError.response?.data?.errors,
    });
}

export async function getStoredStellarWallet() {
    try {
        const response = await apiClient.get<NullableStellarWalletResponse>(
            "/stellar/wallet",
        );

        logBackendStatus("getStoredStellarWallet", response.status);

        return response.data.data;
    } catch (error: unknown) {
        logBackendValidation("getStoredStellarWallet", error);
        throw error;
    }
}

export async function storeStellarWallet(payload: CreateStellarWalletPayload) {
    try {
        const response = await apiClient.post<StellarWalletResponse>(
            "/stellar/wallet",
            payload,
        );

        logBackendStatus("storeStellarWallet", response.status);

        return response.data.data;
    } catch (error: unknown) {
        logBackendValidation("storeStellarWallet", error);
        throw error;
    }
}

export async function disconnectStoredStellarWallet() {
    try {
        const response = await apiClient.delete<{ message: string }>(
            "/stellar/wallet",
        );

        logBackendStatus("disconnectStoredStellarWallet", response.status);

        return response.data;
    } catch (error: unknown) {
        logBackendValidation("disconnectStoredStellarWallet", error);
        throw error;
    }
}
