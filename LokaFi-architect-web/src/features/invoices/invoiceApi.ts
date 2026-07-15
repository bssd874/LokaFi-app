import { apiClient } from "../../api/client";
import type {
    CreateInvoicePayload,
    InvoicePaymentVerificationResponse,
    InvoiceListResponse,
    InvoiceResponse,
    UpdateInvoicePayload,
    VerifyInvoicePaymentPayload,
} from "../../types/invoice";

export async function getInvoices() {
    const response = await apiClient.get<InvoiceListResponse>("/invoices");
    return response.data.data;
}

export async function createInvoice(payload: CreateInvoicePayload) {
    const response = await apiClient.post<InvoiceResponse>("/invoices", payload);
    return response.data.data;
}

export async function getInvoice(id: number) {
    const response = await apiClient.get<InvoiceResponse>(`/invoices/${id}`);
    return response.data.data;
}

export async function updateInvoice(id: number, payload: UpdateInvoicePayload) {
    const response = await apiClient.patch<InvoiceResponse>(
        `/invoices/${id}`,
        payload,
    );

    return response.data.data;
}

export async function cancelInvoice(id: number) {
    const response = await apiClient.delete<InvoiceResponse>(`/invoices/${id}`);
    return response.data.data;
}

export async function getPublicInvoice(uuid: string) {
    const response = await apiClient.get<InvoiceResponse>(
        `/public/invoices/${uuid}`,
    );

    return response.data.data;
}

export async function verifyPublicInvoicePayment(
    uuid: string,
    payload: VerifyInvoicePaymentPayload,
) {
    const response = await apiClient.post<InvoicePaymentVerificationResponse>(
        `/public/invoices/${uuid}/verify-payment`,
        payload,
    );

    return response.data.data;
}

export async function verifyInvoicePayment(
    id: number,
    payload: VerifyInvoicePaymentPayload,
) {
    const response = await apiClient.post<InvoicePaymentVerificationResponse>(
        `/invoices/${id}/verify-payment`,
        payload,
    );

    return response.data.data;
}
