import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
    ArrowLeft,
    Ban,
    ExternalLink,
    FileText,
    Loader2,
    RefreshCw,
    ShieldCheck,
    WalletCards,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import { cancelInvoice, getInvoice } from "../features/invoices/invoiceApi";
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
import type { Invoice } from "../types/invoice";

export function InvoiceDetailPage() {
    const { id } = useParams();
    const [invoice, setInvoice] = useState<Invoice | null>(null);
    const [loading, setLoading] = useState(true);
    const [cancelling, setCancelling] = useState(false);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    async function fetchInvoice() {
        if (!id || Number.isNaN(Number(id))) {
            setError("Invoice ID tidak valid.");
            setLoading(false);
            return;
        }

        try {
            setLoading(true);
            setError("");

            const data = await getInvoice(Number(id));
            setInvoice(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil detail invoice"));
        } finally {
            setLoading(false);
        }
    }

    async function handleCancelInvoice() {
        if (!invoice) return;

        const confirmed = window.confirm("Batalkan invoice ini?");
        if (!confirmed) return;

        try {
            setCancelling(true);
            setError("");
            setSuccessMessage("");

            const cancelled = await cancelInvoice(invoice.id);
            setInvoice(cancelled);
            setSuccessMessage("Invoice berhasil dibatalkan.");
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membatalkan invoice"));
        } finally {
            setCancelling(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchInvoice();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [id]);

    const statusMeta = invoice ? getInvoiceStatusMeta(invoice.status) : null;
    const canCancel = invoice?.status === "pending";
    const transactionHash = invoice?.latest_stellar_payment?.transaction_hash;
    const publicInvoiceUrl = invoice ? getPublicInvoiceUrl(invoice.uuid) : "";

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <Link
                        to="/invoices"
                        className="mb-3 inline-flex items-center gap-2 text-sm font-semibold text-slate-500 hover:text-blue-700"
                    >
                        <ArrowLeft size={16} />
                        Back to Invoices
                    </Link>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Invoices & Payment Requests
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Detail payment request merchant dan public payment page.
                    </p>
                </div>

                <div className="flex flex-wrap gap-2">
                    <button
                        onClick={fetchInvoice}
                        className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                    >
                        <RefreshCw size={16} />
                        Refresh
                    </button>

                    {invoice && (
                        <Link
                            to={`/pay/${invoice.uuid}`}
                            className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white hover:bg-blue-700"
                        >
                            <ExternalLink size={16} />
                            Public Page
                        </Link>
                    )}
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

            {loading ? (
                <div className="flex min-h-80 items-center justify-center rounded-3xl bg-white shadow-sm ring-1 ring-slate-100">
                    <div className="flex items-center gap-3 text-slate-500">
                        <Loader2 className="animate-spin" size={20} />
                        Loading invoice...
                    </div>
                </div>
            ) : invoice ? (
                <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-6 flex flex-col justify-between gap-4 md:flex-row md:items-start">
                            <div className="flex items-start gap-4">
                                <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                    <FileText size={24} />
                                </div>
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h2 className="text-xl font-bold text-slate-950">
                                            {invoice.description}
                                        </h2>
                                        {statusMeta && (
                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-bold ${statusMeta.className}`}
                                            >
                                                {statusMeta.label}
                                            </span>
                                        )}
                                    </div>
                                    <p className="mt-1 text-sm text-slate-500">
                                        {invoice.customer_name || "Public customer"} /{" "}
                                        {invoice.customer_email || "no email"}
                                    </p>
                                </div>
                            </div>

                            <button
                                onClick={handleCancelInvoice}
                                disabled={!canCancel || cancelling}
                                className="inline-flex items-center justify-center gap-2 rounded-xl border border-red-100 px-4 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {cancelling ? (
                                    <Loader2 className="animate-spin" size={16} />
                                ) : (
                                    <Ban size={16} />
                                )}
                                Cancel Invoice
                            </button>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <InfoBlock label="IDR Amount" value={formatIdr(invoice.fiat_amount)} />
                            <InfoBlock
                                label="Stellar Testnet Amount"
                                value={`${formatXlm(invoice.stellar_amount)} XLM`}
                                hint="Demo conversion rate — no real money."
                            />
                            <InfoBlock
                                label="Demo Exchange Rate"
                                value={`1 XLM = ${formatIdr(invoice.demo_exchange_rate)}`}
                            />
                            <InfoBlock label="Payment Method" value="Stellar Testnet" />
                            <InfoBlock
                                label="Invoice Identifier"
                                value={invoice.uuid}
                                copyValue={invoice.uuid}
                            />
                            <InfoBlock
                                label="Payment Memo"
                                value={invoice.payment_memo}
                                copyValue={invoice.payment_memo}
                            />
                            <InfoBlock label="Expires At" value={formatDateTime(invoice.expires_at)} />
                            <InfoBlock label="Created At" value={formatDateTime(invoice.created_at)} />
                        </div>

                        <div className="mt-6 rounded-2xl bg-slate-50 p-5">
                            <p className="text-sm font-semibold text-slate-700">
                                Recipient Public Key
                            </p>
                            <p className="mt-2 break-all font-mono text-sm text-slate-700">
                                {shortenPublicKey(invoice.recipient_public_key)}
                            </p>
                            <CopyButton
                                value={invoice.recipient_public_key}
                                label="Copy Public Key"
                                copiedLabel="Public Key Copied"
                                className="mt-3"
                            />
                        </div>
                    </section>

                    <aside className="space-y-6">
                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <div className="mb-5 flex items-center gap-3">
                                <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                    <ExternalLink size={22} />
                                </div>
                                <div>
                                    <h2 className="text-xl font-bold text-slate-950">
                                        Public Payment Page
                                    </h2>
                                    <p className="text-sm text-slate-500">
                                        Bagikan link ini ke customer.
                                    </p>
                                </div>
                            </div>

                            <div className="rounded-2xl bg-slate-50 p-4">
                                <p className="break-all text-sm font-semibold text-slate-900">
                                    {publicInvoiceUrl}
                                </p>
                            </div>

                            <CopyButton
                                value={publicInvoiceUrl}
                                label="Copy Public Link"
                                copiedLabel="Public Link Copied"
                                className="mt-4 w-full px-4 py-3"
                            />
                        </section>

                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <div className="flex items-start gap-3">
                                <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                    <WalletCards size={22} />
                                </div>
                                <div>
                                    <p className="font-semibold text-slate-900">Wallet</p>
                                    <p className="mt-1 font-mono text-sm text-slate-500">
                                        {shortenPublicKey(invoice.recipient_public_key)}
                                    </p>
                                    <CopyButton
                                        value={invoice.recipient_public_key}
                                        label="Copy Public Key"
                                        copiedLabel="Public Key Copied"
                                        className="mt-3"
                                    />
                                </div>
                            </div>
                        </section>

                        {transactionHash && (
                            <section className="rounded-3xl bg-emerald-50 p-5 text-sm text-emerald-700">
                                <div className="flex items-start gap-3">
                                    <ExternalLink className="mt-0.5 shrink-0" size={18} />
                                    <div>
                                        <p className="font-semibold">Stellar payment confirmed</p>
                                        <p className="mt-2 break-all font-mono">
                                            {shortenHash(transactionHash)}
                                        </p>
                                        <CopyButton
                                            value={transactionHash}
                                            label="Copy Transaction Hash"
                                            copiedLabel="Hash Copied"
                                            className="mt-3 border-emerald-100 text-emerald-700 hover:bg-emerald-50"
                                        />
                                        <a
                                            href={getTestnetTransactionExplorerUrl(transactionHash)}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="mt-3 inline-flex items-center gap-2 font-semibold hover:text-emerald-900"
                                        >
                                            View on Testnet explorer
                                            <ExternalLink size={16} />
                                        </a>
                                    </div>
                                </div>
                            </section>
                        )}

                        <section className="rounded-3xl bg-blue-50 p-5 text-sm text-blue-700">
                            <div className="flex items-start gap-3">
                                <ShieldCheck className="mt-0.5 shrink-0" size={18} />
                                <div>
                                    <p className="font-semibold">Backend verification</p>
                                    <p className="mt-1">
                                        Paid status hanya dibuat setelah transaksi Stellar Testnet
                                        lolos verifikasi backend.
                                    </p>
                                </div>
                            </div>
                        </section>
                    </aside>
                </div>
            ) : (
                <div className="rounded-3xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-100">
                    Invoice tidak ditemukan.
                </div>
            )}
        </div>
    );
}

function InfoBlock({
    label,
    value,
    hint,
    copyValue,
}: {
    label: string;
    value: string;
    hint?: string;
    copyValue?: string;
}) {
    return (
        <div className="rounded-2xl bg-slate-50 p-5">
            <p className="text-sm text-slate-500">{label}</p>
            <p className="mt-2 break-all font-bold text-slate-950">{value}</p>
            {hint && <p className="mt-1 text-xs text-blue-600">{hint}</p>}
            {copyValue && (
                <CopyButton
                    value={copyValue}
                    label={`Copy ${label}`}
                    copiedLabel="Copied"
                    className="mt-3"
                />
            )}
        </div>
    );
}
