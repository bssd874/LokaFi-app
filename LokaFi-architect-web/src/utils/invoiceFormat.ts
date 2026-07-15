import type { InvoiceStatus } from "../types/invoice";

export function formatIdr(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

export function formatXlm(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        maximumFractionDigits: 7,
    }).format(Number(value ?? 0));
}

export function formatDateTime(date?: string | null) {
    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(date));
}

export function shortenPublicKey(publicKey?: string | null) {
    if (!publicKey) return "-";

    return `${publicKey.slice(0, 8)}...${publicKey.slice(-8)}`;
}

export function shortenHash(hash?: string | null) {
    if (!hash) return "-";

    return `${hash.slice(0, 10)}...${hash.slice(-10)}`;
}

export function getTestnetAccountExplorerUrl(publicKey: string) {
    return `https://stellar.expert/explorer/testnet/account/${publicKey}`;
}

export function getTestnetTransactionExplorerUrl(transactionHash: string) {
    return `https://stellar.expert/explorer/testnet/tx/${transactionHash}`;
}

export function getInvoiceStatusMeta(status: InvoiceStatus) {
    if (status === "pending") {
        return {
            label: "pending",
            className: "bg-blue-50 text-blue-700",
        };
    }

    if (status === "paid") {
        return {
            label: "paid",
            className: "bg-emerald-50 text-emerald-700",
        };
    }

    if (status === "expired") {
        return {
            label: "expired",
            className: "bg-amber-50 text-amber-700",
        };
    }

    return {
        label: "cancelled",
        className: "bg-slate-100 text-slate-700",
    };
}

export function getPublicInvoiceUrl(uuid: string) {
    const url = new URL(`/pay/${uuid}`, window.location.origin);

    if (url.hostname === "localhost" || url.hostname === "127.0.0.1") {
        url.protocol = "https:";
    }

    return url.toString();
}
