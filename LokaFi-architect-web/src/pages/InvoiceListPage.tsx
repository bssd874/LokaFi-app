import { useEffect, useMemo, useState } from "react";
import { Link } from "react-router-dom";
import {
    AlertTriangle,
    ExternalLink,
    FileText,
    Loader2,
    Plus,
    RefreshCw,
    XCircle,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import { cancelInvoice, getInvoices } from "../features/invoices/invoiceApi";
import { getApiErrorMessage } from "../utils/apiError";
import {
    formatDateTime,
    formatIdr,
    formatXlm,
    getInvoiceStatusMeta,
    getPublicInvoiceUrl,
    getTestnetTransactionExplorerUrl,
    shortenHash,
    shortenPublicKey,
} from "../utils/invoiceFormat";
import type { Invoice, InvoiceStatus } from "../types/invoice";

type InvoiceFilter = "all" | InvoiceStatus;

const INVOICE_FILTERS: InvoiceFilter[] = [
    "all",
    "pending",
    "paid",
    "expired",
    "cancelled",
];

function getFilterLabel(filter: InvoiceFilter) {
    if (filter === "all") return "All";

    return filter.charAt(0).toUpperCase() + filter.slice(1);
}

export function InvoiceListPage() {
    const [invoices, setInvoices] = useState<Invoice[]>([]);
    const [statusFilter, setStatusFilter] = useState<InvoiceFilter>("all");
    const [loading, setLoading] = useState(true);
    const [cancellingId, setCancellingId] = useState<number | null>(null);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const summary = useMemo(() => {
        return {
            total: invoices.length,
            pending: invoices.filter((invoice) => invoice.status === "pending").length,
            paid: invoices.filter((invoice) => invoice.status === "paid").length,
            expired: invoices.filter((invoice) => invoice.status === "expired").length,
            cancelled: invoices.filter((invoice) => invoice.status === "cancelled").length,
        };
    }, [invoices]);

    const filteredInvoices = useMemo(() => {
        if (statusFilter === "all") return invoices;

        return invoices.filter((invoice) => invoice.status === statusFilter);
    }, [invoices, statusFilter]);

    async function fetchInvoices() {
        try {
            setLoading(true);
            setError("");

            const data = await getInvoices();
            setInvoices(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data invoice"));
        } finally {
            setLoading(false);
        }
    }

    async function handleCancelInvoice(invoice: Invoice) {
        const confirmed = window.confirm(
            `Batalkan invoice "${invoice.description}"?`,
        );

        if (!confirmed) return;

        try {
            setCancellingId(invoice.id);
            setError("");
            setSuccessMessage("");

            await cancelInvoice(invoice.id);
            setSuccessMessage("Invoice berhasil dibatalkan.");
            await fetchInvoices();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membatalkan invoice"));
        } finally {
            setCancellingId(null);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchInvoices();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Invoices & Payment Requests
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Buat payment request merchant dengan metode Stellar Testnet.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <button
                        onClick={fetchInvoices}
                        className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                    >
                        <RefreshCw size={16} />
                        Refresh
                    </button>

                    <Link
                        to="/invoices/create"
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700"
                    >
                        <Plus size={16} />
                        Create Payment Request
                    </Link>
                </div>
            </div>

            <TestnetNotice />

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            {successMessage && (
                <div className="rounded-2xl border border-emerald-100 bg-emerald-50 px-5 py-4 text-sm text-emerald-700">
                    {successMessage}
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-5">
                <SummaryCard label="Total" value={summary.total} className="text-slate-950" />
                <SummaryCard label="Pending" value={summary.pending} className="text-blue-600" />
                <SummaryCard label="Paid" value={summary.paid} className="text-emerald-600" />
                <SummaryCard label="Expired" value={summary.expired} className="text-amber-600" />
                <SummaryCard label="Cancelled" value={summary.cancelled} className="text-slate-600" />
            </div>

            <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <div className="mb-5 flex flex-col justify-between gap-4 lg:flex-row lg:items-end">
                    <div>
                        <h2 className="text-xl font-bold text-slate-950">
                            Payment Request List
                        </h2>
                        <p className="text-sm text-slate-500">
                            Demo conversion rate — no real money.
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {INVOICE_FILTERS.map((filter) => {
                            const active = statusFilter === filter;

                            return (
                                <button
                                    key={filter}
                                    type="button"
                                    onClick={() => setStatusFilter(filter)}
                                    className={[
                                        "rounded-xl px-3 py-2 text-sm font-semibold",
                                        active
                                            ? "bg-blue-600 text-white"
                                            : "border border-slate-200 bg-white text-slate-600 hover:bg-slate-50",
                                    ].join(" ")}
                                >
                                    {getFilterLabel(filter)}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {loading ? (
                    <LoadingState text="Loading invoices..." />
                ) : invoices.length === 0 ? (
                    <EmptyState />
                ) : filteredInvoices.length === 0 ? (
                    <EmptyState filterLabel={getFilterLabel(statusFilter)} />
                ) : (
                    <div className="space-y-4">
                        {filteredInvoices.map((invoice) => {
                            const statusMeta = getInvoiceStatusMeta(invoice.status);
                            const canCancel = invoice.status === "pending";
                            const publicLink = getPublicInvoiceUrl(invoice.uuid);
                            const transactionHash =
                                invoice.latest_stellar_payment?.transaction_hash;

                            return (
                                <div
                                    key={invoice.id}
                                    className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                >
                                    <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-start">
                                        <div className="flex items-start gap-4">
                                            <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                                <FileText size={22} />
                                            </div>

                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <Link
                                                        to={`/invoices/${invoice.id}`}
                                                        className="font-bold text-slate-950 hover:text-blue-700"
                                                    >
                                                        {invoice.description}
                                                    </Link>
                                                    <span
                                                        className={`rounded-full px-3 py-1 text-xs font-bold ${statusMeta.className}`}
                                                    >
                                                        {statusMeta.label}
                                                    </span>
                                                </div>

                                                <p className="mt-1 text-sm text-slate-500">
                                                    Customer:{" "}
                                                    {invoice.customer_name || "Public customer"}
                                                    {invoice.customer_email
                                                        ? ` / ${invoice.customer_email}`
                                                        : ""}
                                                </p>

                                                <p className="mt-1 text-sm text-slate-500">
                                                    Expires {formatDateTime(invoice.expires_at)}
                                                </p>

                                                <div className="mt-3 grid gap-2 text-xs text-slate-500 sm:grid-cols-2">
                                                    <MetaItem
                                                        label="Invoice ID"
                                                        value={shortenHash(invoice.uuid)}
                                                        mono
                                                    />
                                                    <MetaItem
                                                        label="Payment memo"
                                                        value={invoice.payment_memo}
                                                        mono
                                                    />
                                                    <MetaItem
                                                        label="Recipient"
                                                        value={shortenPublicKey(
                                                            invoice.recipient_public_key,
                                                        )}
                                                        mono
                                                    />
                                                    <MetaItem
                                                        label="Payment method"
                                                        value="Stellar Testnet"
                                                    />
                                                </div>

                                                {transactionHash && (
                                                    <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-emerald-700">
                                                        <span className="font-semibold">
                                                            Paid hash
                                                        </span>
                                                        <span className="font-mono">
                                                            {shortenHash(transactionHash)}
                                                        </span>
                                                    </div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="flex flex-col gap-3 sm:flex-row xl:items-center">
                                            <div className="rounded-2xl bg-white px-4 py-3 text-sm">
                                                <p className="text-slate-500">IDR amount</p>
                                                <p className="mt-1 font-bold text-slate-950">
                                                    {formatIdr(invoice.fiat_amount)}
                                                </p>
                                                <p className="mt-1 text-xs font-semibold text-blue-600">
                                                    Stellar Testnet amount:{" "}
                                                    {formatXlm(invoice.stellar_amount)} XLM
                                                </p>
                                                <p className="mt-1 text-[11px] font-semibold text-slate-400">
                                                    Demo conversion rate — no real money.
                                                </p>
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                <CopyButton
                                                    value={publicLink}
                                                    label="Copy Link"
                                                    copiedLabel="Link Copied"
                                                />

                                                <CopyButton
                                                    value={invoice.payment_memo}
                                                    label="Copy Memo"
                                                    copiedLabel="Memo Copied"
                                                />

                                                <CopyButton
                                                    value={invoice.recipient_public_key}
                                                    label="Copy Public Key"
                                                    copiedLabel="Public Key Copied"
                                                />

                                                <Link
                                                    to={`/pay/${invoice.uuid}`}
                                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                                                >
                                                    <ExternalLink size={16} />
                                                    Public Page
                                                </Link>

                                                {transactionHash && (
                                                    <>
                                                        <CopyButton
                                                            value={transactionHash}
                                                            label="Copy Hash"
                                                            copiedLabel="Hash Copied"
                                                        />

                                                        <a
                                                            href={getTestnetTransactionExplorerUrl(
                                                                transactionHash,
                                                            )}
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            className="inline-flex items-center gap-2 rounded-xl border border-emerald-100 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                                                        >
                                                            <ExternalLink size={16} />
                                                            Explorer
                                                        </a>
                                                    </>
                                                )}

                                                <button
                                                    onClick={() => handleCancelInvoice(invoice)}
                                                    disabled={!canCancel || cancellingId === invoice.id}
                                                    className="inline-flex items-center gap-2 rounded-xl border border-red-100 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    {cancellingId === invoice.id ? (
                                                        <Loader2 className="animate-spin" size={16} />
                                                    ) : (
                                                        <XCircle size={16} />
                                                    )}
                                                    Cancel
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </section>
        </div>
    );
}

function SummaryCard({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className: string;
}) {
    return (
        <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <p className="text-sm font-medium text-slate-500">{label}</p>
            <p className={`mt-4 text-3xl font-bold ${className}`}>{value}</p>
        </div>
    );
}

function LoadingState({ text }: { text: string }) {
    return (
        <div className="flex min-h-60 items-center justify-center">
            <div className="flex items-center gap-3 text-slate-500">
                <Loader2 className="animate-spin" size={20} />
                {text}
            </div>
        </div>
    );
}

function EmptyState({ filterLabel }: { filterLabel?: string } = {}) {
    return (
        <div className="flex min-h-60 flex-col items-center justify-center rounded-2xl bg-slate-50 p-6 text-center">
            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                <AlertTriangle size={28} />
            </div>
            <h3 className="font-semibold text-slate-900">
                {filterLabel ? `Tidak ada invoice ${filterLabel}` : "Belum ada invoice"}
            </h3>
            <p className="mt-1 max-w-sm text-sm text-slate-500">
                Buat payment request pertama setelah akun Stellar Testnet merchant
                terhubung di Accounts.
            </p>
        </div>
    );
}

function MetaItem({
    label,
    value,
    mono = false,
}: {
    label: string;
    value: string;
    mono?: boolean;
}) {
    return (
        <div>
            <span className="font-semibold text-slate-400">{label}: </span>
            <span className={mono ? "font-mono text-slate-600" : "text-slate-600"}>
                {value}
            </span>
        </div>
    );
}
