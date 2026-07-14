import { apiClient } from "../../api/client";
import type { DashboardSummaryResponse } from "../../types/dashboard";

type DashboardParams = {
    from?: string;
    to?: string;
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