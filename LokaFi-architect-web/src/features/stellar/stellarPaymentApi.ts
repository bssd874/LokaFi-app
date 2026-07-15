import { apiClient } from "../../api/client";
import type { StellarPaymentListResponse } from "../../types/invoice";

export async function getStellarPayments() {
    const response =
        await apiClient.get<StellarPaymentListResponse>("/stellar/payments");

    return response.data.data;
}
