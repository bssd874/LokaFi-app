import { apiClient } from "../../api/client";
import type {
    CreateWalletPayload,
    WalletListResponse,
    WalletResponse,
} from "../../types/wallet";

export async function getWallets() {
    const response = await apiClient.get<WalletListResponse>("/wallets");
    return response.data.data;
}

export async function createWallet(payload: CreateWalletPayload) {
    const response = await apiClient.post<WalletResponse>("/wallets", payload);
    return response.data.data;
}

export async function deleteWallet(id: number) {
    const response = await apiClient.delete<{ message: string }>(`/wallets/${id}`);
    return response.data;
}