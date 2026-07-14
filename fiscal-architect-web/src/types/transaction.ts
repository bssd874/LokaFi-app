import type { Wallet } from "./wallet";
import type { Category } from "./category";

export type TransactionType = "income" | "expense" | "transfer";
export type TransactionSource =
    | "manual"
    | "brankas"
    | "open_banking_simulator"
    | "open_banking_provider"
    | "portfolio_simulator";
export type CategorizationStatus = "unclassified" | "categorized";
export type CategorySource = "user" | "imported" | "unclassified";

export type TransactionCategoryLabel = {
    id: number;
    user_id: number;
    transaction_id: number;
    category_id: number;
    sanitized_description: string;
    transaction_type: TransactionType;
    amount: string;
    source: TransactionSource | string;
    labeled_by: string;
    is_verified: boolean;
    created_at: string;
    updated_at: string;
};

export type Transaction = {
    id: number;
    user_id: number;
    type: TransactionType;
    wallet_id?: number | null;
    bank_connection_id?: number | null;
    from_wallet_id?: number | null;
    to_wallet_id?: number | null;
    category_id?: number | null;
    amount: string;
    fee: string;
    currency: string;
    merchant?: string | null;
    description?: string | null;
    note?: string | null;
    reference_code?: string | null;
    source?: TransactionSource | null;
    external_transaction_id?: string | null;
    sanitized_description?: string | null;
    categorization_status?: CategorizationStatus | null;
    category_source?: CategorySource | null;
    categorized_at?: string | null;
    imported_at?: string | null;
    happened_at: string;
    created_at: string;
    updated_at: string;
    wallet?: Wallet | null;
    from_wallet?: Wallet | null;
    to_wallet?: Wallet | null;
    category?: Category | null;
    category_label?: TransactionCategoryLabel | null;
};

export type CreateTransactionPayload = {
    type: TransactionType;
    wallet_id?: number | null;
    from_wallet_id?: number | null;
    to_wallet_id?: number | null;
    category_id?: number | null;
    amount: number;
    fee?: number;
    currency?: string;
    merchant?: string;
    description?: string;
    note?: string;
    reference_code?: string;
    happened_at: string;
};

export type TransactionListResponse = {
    message: string;
    data: {
        current_page: number;
        data: Transaction[];
        total: number;
        per_page: number;
    };
};

export type TransactionResponse = {
    message: string;
    data: Transaction;
};

export type BulkCategoryResponse = {
    message: string;
    data: {
        updated_count: number;
        skipped_count: number;
    };
};
