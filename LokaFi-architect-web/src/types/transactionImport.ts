import type { Wallet } from "./wallet";

export type TransactionImportSourceType = "bank_csv" | "ewallet_csv";

export type TransactionImportRowStatus =
    | "pending"
    | "imported"
    | "duplicate"
    | "invalid"
    | "failed";

export type TransactionImportMapping = {
    happened_at?: string;
    amount?: string;
    debit_amount?: string;
    credit_amount?: string;
    type?: string;
    description?: string;
    merchant?: string;
    reference_code?: string;
    external_transaction_id?: string;
    fee?: string;
    currency?: string;
};

export type TransactionImportBatch = {
    id: number;
    wallet_id: number;
    wallet?: Wallet | null;
    source_type: TransactionImportSourceType;
    provider_code?: string | null;
    original_filename?: string | null;
    file_hash: string;
    file_size_bytes: number;
    detected_columns: string[];
    column_mapping: TransactionImportMapping;
    status: "previewed" | "imported";
    total_rows: number;
    imported_count: number;
    duplicate_count: number;
    invalid_count: number;
    failed_count: number;
    processed_at?: string | null;
    created_at: string;
    updated_at: string;
};

export type TransactionImportRow = {
    id: number;
    row_number: number;
    status: TransactionImportRowStatus;
    error_message?: string | null;
    transaction_id?: number | null;
    external_transaction_id?: string | null;
    dedupe_fingerprint?: string | null;
    raw_payload: Record<string, string>;
    normalized_payload?: Record<string, string | number | null> | null;
};

export type TransactionImportResult = {
    batch: TransactionImportBatch;
    summary: {
        total_rows: number;
        imported_count: number;
        duplicate_count: number;
        invalid_count: number;
        failed_count: number;
    };
    preview_rows: TransactionImportRow[];
    rows: TransactionImportRow[];
    duplicate_file: boolean;
    idempotent: boolean;
};

export type TransactionImportResultResponse = {
    message: string;
    data: TransactionImportResult;
};

export type TransactionImportListResponse = {
    message: string;
    data: {
        current_page: number;
        data: TransactionImportBatch[];
        total: number;
        per_page: number;
    };
};
