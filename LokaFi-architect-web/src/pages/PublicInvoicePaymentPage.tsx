import { useEffect, useState } from "react";
import { Link, useParams } from "react-router-dom";
import {
    AlertTriangle,
    CheckCircle2,
    Clock3,
    ExternalLink,
    FileText,
    Loader2,
    PlugZap,
    Send,
    ShieldCheck,
    WalletCards,
    XCircle,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import {
    getPublicInvoice,
    verifyPublicInvoicePayment,
} from "../features/invoices/invoiceApi";
import {
    connectFreighterWallet,
    submitNativeTestnetXlmPayment,
} from "../features/stellar/stellarWalletService";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import {
    formatDateTime,
    formatIdr,
    formatXlm,
    getInvoiceStatusMeta,
    getTestnetTransactionExplorerUrl,
    shortenHash,
    shortenPublicKey,
} from "../utils/invoiceFormat";
import type { Invoice, StellarPayment } from "../types/invoice";

type PaymentState =
    | "wallet_not_installed"
    | "wallet_not_connected"
    | "ready"
    | "wrong_network"
    | "awaiting_wallet_approval"
    | "user_rejected"
    | "submitting"
    | "submitted"
    | "verifying"
    | "paid"
    | "verification_failed"
    | "invoice_expired";

const PROCESSING_STATES: PaymentState[] = [
    "awaiting_wallet_approval",
    "submitting",
    "submitted",
    "verifying",
];

function getErrorMessage(error: unknown, fallback: string) {
    return getFirstValidationError(error) ?? getApiErrorMessage(error, fallback);
}

function mapPaymentErrorToState(error: unknown): PaymentState {
    const message = error instanceof Error ? error.message.toLowerCase() : "";

    if (
        message.includes("tidak tersedia") ||
        message.includes("install") ||
        message.includes("not available")
    ) {
        return "wallet_not_installed";
    }

    if (message.includes("testnet")) {
        return "wrong_network";
    }

    if (
        message.includes("reject") ||
        message.includes("declin") ||
        message.includes("denied") ||
        message.includes("ditolak")
    ) {
        return "user_rejected";
    }

    return "verification_failed";
}

function getPaymentStateCopy(state: PaymentState) {
    if (state === "wallet_not_installed") {
        return {
            title: "Freighter belum tersedia",
            body: "Install atau aktifkan Freighter di browser yang sedang dipakai.",
            className: "border-amber-100 bg-amber-50 text-amber-800",
        };
    }

    if (state === "wallet_not_connected") {
        return {
            title: "Wallet belum connected",
            body: "Connect Freighter Testnet sebelum membuat pembayaran.",
            className: "border-slate-100 bg-slate-50 text-slate-700",
        };
    }

    if (state === "ready") {
        return {
            title: "Siap membayar",
            body: "Transaksi akan ditandatangani oleh Freighter dan diverifikasi backend.",
            className: "border-blue-100 bg-blue-50 text-blue-700",
        };
    }

    if (state === "wrong_network") {
        return {
            title: "Network salah",
            body: "Ganti Freighter ke Stellar Testnet lalu coba lagi.",
            className: "border-red-100 bg-red-50 text-red-700",
        };
    }

    if (state === "awaiting_wallet_approval") {
        return {
            title: "Menunggu approval Freighter",
            body: "Review tujuan, nominal XLM, dan memo di popup Freighter.",
            className: "border-blue-100 bg-blue-50 text-blue-700",
        };
    }

    if (state === "user_rejected") {
        return {
            title: "Approval ditolak",
            body: "Freighter membatalkan request tanda tangan transaksi.",
            className: "border-amber-100 bg-amber-50 text-amber-800",
        };
    }

    if (state === "submitting") {
        return {
            title: "Submitting ke Stellar Testnet",
            body: "Transaksi sudah ditandatangani dan sedang dikirim ke Horizon Testnet.",
            className: "border-blue-100 bg-blue-50 text-blue-700",
        };
    }

    if (state === "submitted") {
        return {
            title: "Submitted",
            body: "Transaction hash sudah diterima. Backend akan memverifikasi detail transaksi.",
            className: "border-blue-100 bg-blue-50 text-blue-700",
        };
    }

    if (state === "verifying") {
        return {
            title: "Verifying payment",
            body: "Backend mengecek hash, memo, recipient, amount, asset native XLM, dan status invoice.",
            className: "border-blue-100 bg-blue-50 text-blue-700",
        };
    }

    if (state === "paid") {
        return {
            title: "Invoice paid",
            body: "Pembayaran valid dan income transaction sudah dibuat di finance app.",
            className: "border-emerald-100 bg-emerald-50 text-emerald-700",
        };
    }

    if (state === "invoice_expired") {
        return {
            title: "Invoice expired",
            body: "Invoice sudah lewat masa berlaku dan tidak bisa dibayar.",
            className: "border-amber-100 bg-amber-50 text-amber-800",
        };
    }

    return {
        title: "Verification failed",
        body: "Pembayaran belum bisa dikonfirmasi. Backend tidak menandai invoice sebagai paid.",
        className: "border-red-100 bg-red-50 text-red-700",
    };
}

export function PublicInvoicePaymentPage() {
    const { uuid } = useParams();
    const [invoice, setInvoice] = useState<Invoice | null>(null);
    const [payment, setPayment] = useState<StellarPayment | null>(null);
    const [customerPublicKey, setCustomerPublicKey] = useState("");
    const [submittedTransactionHash, setSubmittedTransactionHash] = useState("");
    const [loading, setLoading] = useState(true);
    const [connecting, setConnecting] = useState(false);
    const [paymentState, setPaymentState] =
        useState<PaymentState>("wallet_not_connected");
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    async function fetchInvoice() {
        if (!uuid) {
            setError("Invoice UUID tidak valid.");
            setLoading(false);
            return;
        }

        try {
            setLoading(true);
            setError("");

            const data = await getPublicInvoice(uuid);
            setInvoice(data);
            setPayment(data.latest_stellar_payment ?? null);

            if (data.status === "paid") {
                setPaymentState("paid");
            } else if (data.status === "expired") {
                setPaymentState("invoice_expired");
            } else if (!customerPublicKey) {
                setPaymentState("wallet_not_connected");
            }
        } catch (err: unknown) {
            setError(getErrorMessage(err, "Gagal mengambil public invoice"));
        } finally {
            setLoading(false);
        }
    }

    async function handleConnectCustomerWallet() {
        try {
            setConnecting(true);
            setError("");
            setSuccessMessage("");
            setPaymentState("awaiting_wallet_approval");

            const wallet = await connectFreighterWallet();
            setCustomerPublicKey(wallet.publicKey);
            setPaymentState("ready");
            setSuccessMessage("Customer Freighter Testnet berhasil terhubung.");
        } catch (err: unknown) {
            setPaymentState(mapPaymentErrorToState(err));
            setError(
                err instanceof Error
                    ? err.message
                    : "Gagal menghubungkan Freighter customer.",
            );
        } finally {
            setConnecting(false);
        }
    }

    async function handlePayInvoice() {
        if (!invoice || !uuid) return;

        if (invoice.status === "expired") {
            setPaymentState("invoice_expired");
            setError("Invoice sudah expired.");
            return;
        }

        if (invoice.status !== "pending") {
            setPaymentState(invoice.status === "paid" ? "paid" : "verification_failed");
            setError(`Invoice dengan status ${invoice.status} tidak bisa dibayar.`);
            return;
        }

        if (!customerPublicKey) {
            setPaymentState("wallet_not_connected");
            setError("Connect Freighter Testnet terlebih dahulu.");
            return;
        }

        let verifyingStarted = false;

        try {
            setError("");
            setSuccessMessage("");
            setSubmittedTransactionHash("");
            setPaymentState("awaiting_wallet_approval");

            const submittedPayment = await submitNativeTestnetXlmPayment(
                {
                    recipientPublicKey: invoice.recipient_public_key,
                    xlmAmount: invoice.stellar_amount,
                    paymentMemo: invoice.payment_memo,
                },
                {
                    onAwaitingWalletApproval: () =>
                        setPaymentState("awaiting_wallet_approval"),
                    onSubmitting: () => setPaymentState("submitting"),
                },
            );

            setCustomerPublicKey(submittedPayment.customerPublicKey);
            setSubmittedTransactionHash(submittedPayment.transactionHash);
            setPaymentState("submitted");
            setSuccessMessage("Transaksi dikirim ke Stellar Testnet. Verifikasi backend dimulai.");

            verifyingStarted = true;
            setPaymentState("verifying");

            const verification = await verifyPublicInvoicePayment(uuid, {
                transaction_hash: submittedPayment.transactionHash,
            });

            setInvoice(verification.invoice);
            setPayment(verification.payment);
            setPaymentState("paid");
            setSuccessMessage("Pembayaran valid. Invoice paid dan income transaction dibuat.");
        } catch (err: unknown) {
            setPaymentState(
                verifyingStarted ? "verification_failed" : mapPaymentErrorToState(err),
            );
            setError(getErrorMessage(err, "Gagal memproses pembayaran Stellar Testnet."));
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchInvoice();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [uuid]);

    const statusMeta = invoice ? getInvoiceStatusMeta(invoice.status) : null;
    const payable = invoice?.status === "pending";
    const effectivePaymentState =
        invoice?.status === "expired"
            ? "invoice_expired"
            : invoice?.status === "paid"
              ? "paid"
              : paymentState;
    const stateCopy = getPaymentStateCopy(effectivePaymentState);
    const processing =
        connecting || PROCESSING_STATES.includes(effectivePaymentState);
    const transactionHash =
        payment?.transaction_hash ||
        invoice?.latest_stellar_payment?.transaction_hash ||
        submittedTransactionHash;

    return (
        <div className="min-h-screen bg-slate-50 p-4 text-slate-900 sm:p-6 lg:p-8">
            <main className="mx-auto max-w-5xl space-y-6">
                <header className="flex flex-col justify-between gap-4 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100 lg:flex-row lg:items-center">
                    <div>
                        <Link
                            to="/"
                            className="text-sm font-semibold text-blue-600 hover:text-blue-700"
                        >
                            LokaFi
                        </Link>
                        <h1 className="mt-3 text-3xl font-bold tracking-tight text-slate-950">
                            Invoice Payment Request
                        </h1>
                        <p className="mt-1 text-slate-500">
                            Payment request merchant dengan metode Stellar Testnet.
                        </p>
                    </div>

                    <TestnetNotice />
                </header>

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
                    <div className="flex min-h-96 items-center justify-center rounded-3xl bg-white shadow-sm ring-1 ring-slate-100">
                        <div className="flex items-center gap-3 text-slate-500">
                            <Loader2 className="animate-spin" size={20} />
                            Loading public invoice...
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
                                            Merchant: {invoice.user?.name ?? "Merchant"}
                                        </p>
                                    </div>
                                </div>

                                <div className="rounded-2xl bg-slate-50 px-4 py-3 text-sm">
                                    <p className="text-slate-500">Expires</p>
                                    <p className="mt-1 font-semibold text-slate-900">
                                        {formatDateTime(invoice.expires_at)}
                                    </p>
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-2">
                                <InfoBlock label="Amount IDR" value={formatIdr(invoice.fiat_amount)} />
                                <InfoBlock
                                    label="Stellar Testnet Amount"
                                    value={`${formatXlm(invoice.stellar_amount)} XLM`}
                                    hint="Demo conversion rate — no real money."
                                />
                                <InfoBlock label="Payment Method" value="Stellar Testnet" />
                                <InfoBlock
                                    label="Invoice Status"
                                    value={statusMeta?.label ?? invoice.status}
                                />
                                <InfoBlock
                                    label="Payment Memo"
                                    value={invoice.payment_memo}
                                    copyValue={invoice.payment_memo}
                                />
                                <InfoBlock
                                    label="Recipient"
                                    value={shortenPublicKey(invoice.recipient_public_key)}
                                    copyValue={invoice.recipient_public_key}
                                />
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

                            {transactionHash && (
                                <div className="mt-6 rounded-2xl bg-emerald-50 p-5 text-sm text-emerald-700">
                                    <p className="font-semibold">Transaction Hash</p>
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
                            )}
                        </section>

                        <aside className="space-y-6">
                            <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                                <div className="mb-5 flex items-center gap-3">
                                    <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                        <WalletCards size={22} />
                                    </div>
                                    <div>
                                        <h2 className="text-xl font-bold text-slate-950">
                                            Customer Wallet
                                        </h2>
                                        <p className="text-sm text-slate-500">
                                            Freighter harus aktif di Stellar Testnet.
                                        </p>
                                    </div>
                                </div>

                                {customerPublicKey ? (
                                    <div className="rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-700">
                                        <div className="flex items-center gap-2 font-semibold">
                                            <CheckCircle2 size={16} />
                                            Connected
                                        </div>
                                        <p className="mt-2 break-all font-mono">
                                            {shortenPublicKey(customerPublicKey)}
                                        </p>
                                        <CopyButton
                                            value={customerPublicKey}
                                            label="Copy Customer Public Key"
                                            copiedLabel="Public Key Copied"
                                            className="mt-3 border-emerald-100 text-emerald-700 hover:bg-emerald-50"
                                        />
                                    </div>
                                ) : (
                                    <button
                                        onClick={handleConnectCustomerWallet}
                                        disabled={processing}
                                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {connecting || effectivePaymentState === "awaiting_wallet_approval" ? (
                                            <Loader2 className="animate-spin" size={18} />
                                        ) : (
                                            <PlugZap size={18} />
                                        )}
                                        Connect Freighter
                                    </button>
                                )}

                                <button
                                    onClick={handlePayInvoice}
                                    disabled={!payable || !customerPublicKey || processing}
                                    className="mt-3 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500"
                                >
                                    {PROCESSING_STATES.includes(effectivePaymentState) ? (
                                        <Loader2 className="animate-spin" size={18} />
                                    ) : invoice.status === "paid" ? (
                                        <CheckCircle2 size={18} />
                                    ) : invoice.status === "expired" ? (
                                        <Clock3 size={18} />
                                    ) : !customerPublicKey ? (
                                        <PlugZap size={18} />
                                    ) : (
                                        <Send size={18} />
                                    )}
                                    {PROCESSING_STATES.includes(effectivePaymentState)
                                        ? stateCopy.title
                                        : invoice.status === "paid"
                                          ? "Paid"
                                          : invoice.status === "expired"
                                            ? "Invoice expired"
                                            : !customerPublicKey
                                              ? "Connect wallet first"
                                              : `Pay Invoice - ${formatXlm(invoice.stellar_amount)} XLM`}
                                </button>
                            </section>

                            <section
                                className={`rounded-3xl border p-5 text-sm ${stateCopy.className}`}
                            >
                                <div className="flex items-start gap-3">
                                    {effectivePaymentState === "paid" ? (
                                        <CheckCircle2 className="mt-0.5 shrink-0" size={18} />
                                    ) : effectivePaymentState === "verification_failed" ||
                                      effectivePaymentState === "wrong_network" ? (
                                        <XCircle className="mt-0.5 shrink-0" size={18} />
                                    ) : (
                                        <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                                    )}
                                    <div>
                                        <p className="text-xs font-bold uppercase opacity-70">
                                            Payment confirmation
                                        </p>
                                        <p className="font-semibold">{stateCopy.title}</p>
                                        <p className="mt-1">{stateCopy.body}</p>
                                    </div>
                                </div>
                            </section>

                            {!payable && invoice.status !== "paid" && (
                                <section className="rounded-3xl bg-amber-50 p-5 text-sm text-amber-800">
                                    <div className="flex items-start gap-3">
                                        <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                                        <div>
                                            <p className="font-semibold">Invoice tidak payable.</p>
                                            <p className="mt-1">
                                                Status saat ini: {invoice.status}. Payment flow tidak
                                                bisa dilanjutkan.
                                            </p>
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
                                            Invoice hanya menjadi paid setelah backend memverifikasi
                                            transaksi di Stellar Testnet.
                                        </p>
                                    </div>
                                </div>
                            </section>
                        </aside>
                    </div>
                ) : (
                    <div className="rounded-3xl bg-white p-6 text-sm text-slate-500 shadow-sm ring-1 ring-slate-100">
                        Public invoice tidak ditemukan.
                    </div>
                )}
            </main>
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
