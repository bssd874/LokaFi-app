import type { Wallet } from "./wallet";

export type AssetType =
    | "us_stock"
    | "idx_stock"
    | "crypto"
    | "forex"
    | "gold"
    | "mutual_fund";

export type Asset = {
    id: number;
    symbol: string;
    name: string;
    asset_type: AssetType;
    currency: string;
    exchange?: string | null;
    current_price: string;
    price_change_percentage: string;
    is_active: boolean;
    metadata?: Record<string, unknown> | null;
    created_at: string;
    updated_at: string;
};

export type Watchlist = {
    id: number;
    user_id: number;
    asset_id: number;
    asset: Asset;
    created_at: string;
    updated_at: string;
};

export type InvestmentOrderType = "buy" | "sell";

export type InvestmentOrder = {
    id: number;
    user_id: number;
    asset_id: number;
    wallet_id: number;
    type: InvestmentOrderType;
    mode: "simulation" | "paper" | "real";
    status: "executed" | "cancelled" | "failed";
    quantity: string;
    price: string;
    fee: string;
    gross_amount: string;
    net_amount: string;
    currency: string;
    note?: string | null;
    ordered_at: string;
    asset: Asset;
    wallet: Wallet;
};

export type CreateInvestmentOrderPayload = {
    type: InvestmentOrderType;
    asset_id: number;
    wallet_id: number;
    quantity: number;
    price?: number;
    fee?: number;
    note?: string;
    ordered_at?: string;
};

export type PortfolioHolding = {
    asset_id: number;
    symbol: string;
    name: string;
    asset_type: AssetType;
    currency: string;
    exchange?: string | null;
    quantity: number;
    average_price: number;
    current_price: number;
    current_value: number;
    cost_basis: number;
    unrealized_profit_loss: number;
    unrealized_profit_loss_percentage: number;
    price_change_percentage: number;
    buy_quantity: number;
    sell_quantity: number;
    buy_cost: number;
    sell_proceeds: number;
};

export type PortfolioSummary = {
    investment_cash_balance: number;
    total_portfolio_value: number;
    total_cost_basis: number;
    total_unrealized_profit_loss: number;
    total_unrealized_profit_loss_percentage: number;
    total_assets: number;
    total_equity: number;
};

export type PortfolioResponse = {
    summary: PortfolioSummary;
    holdings: PortfolioHolding[];
};

export type AssetListResponse = {
    message: string;
    data: Asset[];
};

export type WatchlistListResponse = {
    message: string;
    data: Watchlist[];
};

export type WatchlistResponse = {
    message: string;
    data: Watchlist;
};

export type InvestmentOrderListResponse = {
    message: string;
    data: {
        current_page: number;
        data: InvestmentOrder[];
        total: number;
        per_page: number;
    };
};

export type InvestmentOrderResponse = {
    message: string;
    data: InvestmentOrder;
};

export type PortfolioApiResponse = {
    message: string;
    data: PortfolioResponse;
};