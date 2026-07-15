import type { Wallet } from "./wallet";
import type { Category } from "./category";

export type TransactionType = "income" | "expense" | "transfer";
export type TransactionSource =
    | "manual"
    | "brankas"
    | "bank_csv"
    | "ewallet_csv"
    | "stellar"
    | "open_banking_simulator"
    | "open_banking_provider"
    | "portfolio_simulator";
export type CategorizationStatus =
    | "unclassified"
    | "categorized"
    | "review_required";
export type CategorySource =
    | "user"
    | "imported"
    | "system"
    | "unclassified"
    | "verified_mapping"
    | "user_rule"
    | "default_rule"
    | "historical_mapping"
    | "review_required"
    | "ai_suggestion"
    | "ai_error";
export type CategorizationConfidence = "high" | "medium" | "low" | "none";

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
    import_batch_id?: number | null;
    import_row_id?: number | null;
    from_wallet_id?: number | null;
    to_wallet_id?: number | null;
    category_id?: number | null;
    suggested_category_id?: number | null;
    amount: string;
    fee: string;
    currency: string;
    merchant?: string | null;
    normalized_merchant?: string | null;
    description?: string | null;
    note?: string | null;
    reference_code?: string | null;
    source?: TransactionSource | null;
    external_transaction_id?: string | null;
    dedupe_fingerprint?: string | null;
    sanitized_description?: string | null;
    normalized_description?: string | null;
    categorization_status?: CategorizationStatus | null;
    category_source?: CategorySource | null;
    categorization_confidence?: CategorizationConfidence | null;
    categorization_confidence_score?: number | null;
    categorization_explanation?: string | null;
    categorized_at?: string | null;
    imported_at?: string | null;
    happened_at: string;
    created_at: string;
    updated_at: string;
    wallet?: Wallet | null;
    from_wallet?: Wallet | null;
    to_wallet?: Wallet | null;
    category?: Category | null;
    suggested_category?: Category | null;
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

export type TransactionCategorySuggestion = {
    transaction_id: number;
    category: Category | null;
    source: CategorySource;
    confidence: CategorizationConfidence;
    confidence_score: number;
    explanation: string;
    review_required: boolean;
    auto_assign: boolean;
};

export type TransactionCategorySuggestionResponse = {
    message: string;
    data: TransactionCategorySuggestion;
};

export type AiCategorySuggestion = {
    id?: number;
    category: Category | null;
    confidence: number | null;
    confidence_label: CategorizationConfidence;
    needs_review: boolean;
    reason?: string | null;
    validation_status: string;
    error_code?: string | null;
    cached: boolean;
    provider?: string | null;
    model?: string | null;
};

export type AiCategorySuggestionResult = {
    transaction: Transaction;
    suggestion: AiCategorySuggestion | TransactionCategorySuggestion | null;
    skipped_ai: boolean;
    source: CategorySource | string;
    validation_status: string;
    error_code?: string | null;
    user_message: string;
};

export type AiCategorySuggestionResponse = {
    message: string;
    data: AiCategorySuggestionResult;
};

export type AiCategorizePendingResponse = {
    message: string;
    data: {
        processed_count: number;
        suggested_count: number;
        skipped_count: number;
        failed_count: number;
        limit: number;
    };
};

export type ReprocessCategorizationResponse = {
    message: string;
    data: {
        updated_count: number;
        review_required_count: number;
        skipped_count: number;
    };
};

export type BulkCategoryResponse = {
    message: string;
    data: {
        updated_count: number;
        skipped_count: number;
    };
};
