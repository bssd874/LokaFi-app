import type { Wallet } from "./wallet";

export type BankProvider = {
    code: "bri" | "mandiri" | "bca";
    brankas_code?: string;
    name: string;
    mode: "mock" | "sandbox" | "production" | "simulator" | "real";
    status: "available" | "disabled" | "unavailable";
    description?: string;
};

export type BankConnectionStatus =
    | "pending"
    | "connected"
    | "expired"
    | "failed"
    | "revoked";

export type BankConnection = {
    id: number;
    user_id: number;
    provider_code: "bri" | "mandiri" | "bca";
    provider_name: string;
    account_holder_name?: string | null;
    account_number_masked?: string | null;
    status: BankConnectionStatus;
    mode?: "mock" | "brankas" | "simulator" | "real" | null;
    consent_session_id?: string | null;
    external_connection_id?: string | null;
    external_account_id?: string | null;
    error_message?: string | null;
    last_synced_at?: string | null;
    created_at: string;
    updated_at: string;
    wallets?: Wallet[];
};

export type ConnectBankPayload = {
    provider_code: "bri" | "mandiri" | "bca";
    redirect_to?: string;
};

export type BankProviderListResponse = {
    message: string;
    data: BankProvider[];
};

export type BankConnectionListResponse = {
    message: string;
    data: BankConnection[];
};

export type BankConnectionActionResponse = {
    message: string;
    data: {
        connection: BankConnection;
        wallet?: Wallet;
        imported_transactions_count?: number;
        redirect_url?: string;
        mode?: string;
    };
};
