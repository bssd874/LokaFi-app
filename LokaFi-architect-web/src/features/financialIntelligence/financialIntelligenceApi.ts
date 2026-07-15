import { apiClient } from "../../api/client";
import type {
    BudgetAlerts,
    FinancialAnomalies,
    FinancialIntelligenceParams,
    FinancialInsightHistory,
    FinancialInsightResult,
    FinancialResponse,
    FinancialSummary,
    FinancialTrends,
} from "../../types/financialIntelligence";

export async function getFinancialSummary(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<FinancialSummary>>(
        "/financial-intelligence/summary",
        { params },
    );

    return response.data.data;
}

export async function getFinancialTrends(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<FinancialTrends>>(
        "/financial-intelligence/trends",
        { params },
    );

    return response.data.data;
}

export async function getBudgetAlerts(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<BudgetAlerts>>(
        "/financial-intelligence/budget-alerts",
        { params },
    );

    return response.data.data;
}

export async function getFinancialAnomalies(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<FinancialAnomalies>>(
        "/financial-intelligence/anomalies",
        { params },
    );

    return response.data.data;
}

export async function getFinancialInsight(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<FinancialInsightResult>>(
        "/financial-intelligence/insight",
        { params },
    );

    return response.data.data;
}

export async function generateFinancialInsight(params: FinancialIntelligenceParams) {
    const response = await apiClient.post<FinancialResponse<FinancialInsightResult>>(
        "/financial-intelligence/insight",
        null,
        { params },
    );

    return response.data.data;
}

export async function regenerateFinancialInsight(params: FinancialIntelligenceParams) {
    const response = await apiClient.post<FinancialResponse<FinancialInsightResult>>(
        "/financial-intelligence/insight/regenerate",
        null,
        { params },
    );

    return response.data.data;
}

export async function getFinancialInsightHistory(params: FinancialIntelligenceParams) {
    const response = await apiClient.get<FinancialResponse<FinancialInsightHistory>>(
        "/financial-intelligence/insight/history",
        { params },
    );

    return response.data.data;
}
