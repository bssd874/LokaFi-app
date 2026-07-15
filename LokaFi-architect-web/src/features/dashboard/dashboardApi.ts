import { apiClient } from "../../api/client";
import type { DashboardSummaryResponse } from "../../types/dashboard";

type DashboardParams = {
    start_date?: string;
    end_date?: string;
    wallet_id?: number | null;
    source?: string | null;
};

export async function getDashboardSummary(params?: DashboardParams) {
    const response = await apiClient.get<DashboardSummaryResponse>(
        "/dashboard/summary",
        {
            params,
        }
    );

    return response.data.data;
}
