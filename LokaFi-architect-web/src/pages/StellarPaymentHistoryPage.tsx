import { useEffect, useState } from "react";
import { Link } from "react-router-dom";
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Loader2,
    RefreshCw,
    ReceiptText,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import { getStellarPayments } from "../features/stellar/stellarPaymentApi";
import { getApiErrorMessage } from "../utils/apiError";
import {
    formatDateTime,
    formatIdr,
    formatXlm,
    getTestnetTransactionExplorerUrl,
    shortenHash,
    shortenPublicKey,
} from "../utils/invoiceFormat";
import type { StellarPayment } from "../types/invoice";

export function StellarPaymentHistoryPage() {
    const [payments, setPayments] = useState<StellarPayment[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");

    async function fetchPayments() {
        try {
            setLoading(true);
            setError("");

            const data = await getStellarPayments();
            setPayments(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil Stellar payments"));
        } finally {
            setLoading(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchPayments();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Stellar Payments
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Riwayat pembayaran invoice yang sudah diverifikasi backend.
                    </p>
                </div>

                <button
                    onClick={fetchPayments}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                >
                    <RefreshCw size={16} />
                    Refresh
                </button>
            </div>

            <TestnetNotice />

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <div className="mb-5">
                    <h2 className="text-xl font-bold text-slate-950">
                        Confirmed Payment History
                    </h2>
                    <p className="text-sm text-slate-500">
                        Hash yang tampil di sini sudah lolos verifikasi Stellar Testnet.
                    </p>
                </div>

                {loading ? (
                    <div className="flex min-h-60 items-center justify-center text-slate-500">
                        <Loader2 className="mr-2 animate-spin" size={20} />
                        Loading Stellar payments...
                    </div>
                ) : payments.length === 0 ? (
                    <div className="flex min-h-60 flex-col items-center justify-center rounded-2xl bg-slate-50 p-6 text-center">
                        <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                            <AlertTriangle size={28} />
                        </div>
                        <h3 className="font-semibold text-slate-900">
                            Belum ada pembayaran Stellar
                        </h3>
                        <p className="mt-1 max-w-sm text-sm text-slate-500">
                            Payment akan muncul setelah public invoice dibayar dan backend
                            memverifikasi transaction hash.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-4">
                        {payments.map((payment) => (
                            <PaymentRow key={payment.id} payment={payment} />
                        ))}
                    </div>
                )}
            </section>
        </div>
    );
}

function PaymentRow({ payment }: { payment: StellarPayment }) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-slate-50 p-5">
            <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-start">
                <div className="flex items-start gap-4">
                    <div className="rounded-2xl bg-emerald-100 p-3 text-emerald-700">
                        <ReceiptText size={22} />
                    </div>

                    <div>
                        <div className="flex flex-wrap items-center gap-2">
                            <Link
                                to={payment.invoice ? `/invoices/${payment.invoice.id}` : "/invoices"}
                                className="font-bold text-slate-950 hover:text-blue-700"
                            >
                                {payment.invoice?.description ?? "Stellar invoice payment"}
                            </Link>
                            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                                <CheckCircle2 size={14} />
                                {payment.status}
                            </span>
                        </div>

                        <p className="mt-2 text-sm text-slate-500">
                            {payment.invoice
                                ? `${formatIdr(payment.invoice.fiat_amount)} / ${formatXlm(
                                      payment.invoice.stellar_amount,
                                  )} XLM demo`
                                : `${formatXlm(payment.amount)} XLM`}
                        </p>

                        <div className="mt-3 grid gap-2 text-xs text-slate-500 md:grid-cols-2">
                            <p className="font-mono">
                                Sender: {shortenPublicKey(payment.sender_public_key)}
                            </p>
                            <p className="font-mono">
                                Receiver: {shortenPublicKey(payment.receiver_public_key)}
                            </p>
                            <p className="font-mono">Memo: {payment.memo}</p>
                            <p>Confirmed: {formatDateTime(payment.confirmed_at)}</p>
                        </div>
                    </div>
                </div>

                <div className="flex flex-col gap-3">
                    <div className="rounded-2xl bg-white px-4 py-3 text-sm">
                        <p className="text-slate-500">Transaction Hash</p>
                        <p className="mt-1 font-mono font-bold text-slate-950">
                            {shortenHash(payment.transaction_hash)}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <CopyButton
                            value={payment.transaction_hash}
                            label="Copy Hash"
                            copiedLabel="Hash Copied"
                        />
                        <CopyButton
                            value={payment.memo}
                            label="Copy Memo"
                            copiedLabel="Memo Copied"
                        />
                        <CopyButton
                            value={payment.receiver_public_key}
                            label="Copy Receiver"
                            copiedLabel="Receiver Copied"
                        />
                        <a
                            href={getTestnetTransactionExplorerUrl(payment.transaction_hash)}
                            target="_blank"
                            rel="noreferrer"
                            className="inline-flex items-center gap-2 rounded-xl border border-emerald-100 bg-white px-3 py-2 text-sm font-semibold text-emerald-700 hover:bg-emerald-50"
                        >
                            <ExternalLink size={16} />
                            Explorer
                        </a>
                    </div>
                </div>
            </div>
        </div>
    );
}
