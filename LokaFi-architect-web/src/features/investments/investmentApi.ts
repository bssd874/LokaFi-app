import { apiClient } from "../../api/client";
import type {
    AssetListResponse,
    AssetType,
    CreateInvestmentOrderPayload,
    InvestmentOrderListResponse,
    InvestmentOrderResponse,
    PortfolioApiResponse,
    WatchlistListResponse,
    WatchlistResponse,
} from "../../types/investment";

type AssetParams = {
    asset_type?: AssetType | "";
    search?: string;
};

export async function getAssets(params?: AssetParams) {
    const response = await apiClient.get<AssetListResponse>("/assets", {
        params,
    });

    return response.data.data;
}

export async function getWatchlists() {
    const response = await apiClient.get<WatchlistListResponse>("/watchlists");
    return response.data.data;
}

export async function addWatchlist(assetId: number) {
    const response = await apiClient.post<WatchlistResponse>("/watchlists", {
        asset_id: assetId,
    });

    return response.data.data;
}

export async function removeWatchlist(watchlistId: number) {
    const response = await apiClient.delete<{ message: string }>(
        `/watchlists/${watchlistId}`
    );

    return response.data;
}

export async function getInvestmentOrders() {
    const response = await apiClient.get<InvestmentOrderListResponse>(
        "/investment-orders"
    );

    return response.data.data;
}

export async function createInvestmentOrder(
    payload: CreateInvestmentOrderPayload
) {
    const response = await apiClient.post<InvestmentOrderResponse>(
        "/investment-orders",
        payload
    );

    return response.data.data;
}

export async function getPortfolio() {
    const response = await apiClient.get<PortfolioApiResponse>("/portfolio");
    return response.data.data;
}