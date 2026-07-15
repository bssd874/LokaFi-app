export type InvoiceStatus = "pending" | "paid" | "expired" | "cancelled";

export type InvoiceMerchant = {
    id: number;
    name: string;
};

export type StellarPayment = {
    id: number;
    invoice_id: number;
    transaction_id?: number | null;
    sender_public_key: string;
    receiver_public_key: string;
    asset_code: "XLM";
    amount: string;
    transaction_hash: string;
    ledger?: number | null;
    memo: string;
    network: "testnet";
    status: "confirmed";
    confirmed_at?: string | null;
    created_at: string;
    updated_at: string;
    invoice?: StellarPaymentInvoice;
    transaction?: InvoiceFinanceTransaction | null;
};

export type StellarPaymentInvoice = {
    id: number;
    uuid: string;
    user_id: number;
    description: string;
    fiat_currency: "IDR";
    fiat_amount: string;
    stellar_amount: string;
    payment_memo: string;
    status: InvoiceStatus;
    paid_at?: string | null;
};

export type InvoiceFinanceTransaction = {
    id: number;
    user_id: number;
    type: "income" | "expense" | "transfer";
    amount: string;
    currency: string;
    source?: string | null;
    reference_code?: string | null;
    external_transaction_id?: string | null;
    invoice_id?: number | null;
    stellar_payment_id?: number | null;
    happened_at: string;
};

export type Invoice = {
    id: number;
    uuid: string;
    user_id: number;
    customer_name?: string | null;
    customer_email?: string | null;
    description: string;
    fiat_currency: "IDR";
    fiat_amount: string;
    demo_exchange_rate: string;
    stellar_asset_code: "XLM";
    stellar_amount: string;
    recipient_public_key: string;
    payment_memo: string;
    status: InvoiceStatus;
    expires_at: string;
    paid_at?: string | null;
    created_at: string;
    updated_at: string;
    user?: InvoiceMerchant;
    latest_stellar_payment?: StellarPayment | null;
};

export type CreateInvoicePayload = {
    customer_name?: string;
    customer_email?: string;
    description: string;
    fiat_amount: number;
    recipient_public_key: string;
    expires_at: string;
};

export type UpdateInvoicePayload = Partial<CreateInvoicePayload>;

export type InvoiceListResponse = {
    message: string;
    data: Invoice[];
};

export type InvoiceResponse = {
    message: string;
    data: Invoice;
};

export type VerifyInvoicePaymentPayload = {
    transaction_hash: string;
};

export type InvoicePaymentVerification = {
    invoice: Invoice;
    payment: StellarPayment;
    finance_transaction: InvoiceFinanceTransaction | null;
};

export type InvoicePaymentVerificationResponse = {
    message: string;
    data: InvoicePaymentVerification;
};

export type StellarPaymentListResponse = {
    message: string;
    data: StellarPayment[];
};
