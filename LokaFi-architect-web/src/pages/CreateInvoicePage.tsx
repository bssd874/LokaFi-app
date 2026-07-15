import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    AlertTriangle,
    ArrowLeft,
    FileText,
    Loader2,
    Plus,
    ShieldCheck,
    WalletCards,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import { createInvoice } from "../features/invoices/invoiceApi";
import { getStoredStellarWallet } from "../features/stellar/stellarApi";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import { formatIdr, formatXlm, shortenPublicKey } from "../utils/invoiceFormat";
import type { StellarWallet } from "../types/stellar";

const DEMO_IDR_PER_XLM = 2500;

function toDateTimeLocalValue(date = new Date()) {
    const offset = date.getTimezoneOffset();
    const localDate = new Date(date.getTime() - offset * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
}

function toApiDateTime(value: string) {
    return `${value.replace("T", " ")}:00`;
}

export function CreateInvoicePage() {
    const navigate = useNavigate();
    const [wallet, setWallet] = useState<StellarWallet | null>(null);
    const [loadingWallet, setLoadingWallet] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState("");

    const [customerName, setCustomerName] = useState("");
    const [customerEmail, setCustomerEmail] = useState("");
    const [description, setDescription] = useState("");
    const [fiatAmount, setFiatAmount] = useState("");
    const [expiresAt, setExpiresAt] = useState("");

    const demoXlmAmount = useMemo(() => {
        if (!fiatAmount || Number(fiatAmount) <= 0) return 0;

        return Number(fiatAmount) / DEMO_IDR_PER_XLM;
    }, [fiatAmount]);

    async function fetchWallet() {
        try {
            setLoadingWallet(true);
            setError("");

            const storedWallet = await getStoredStellarWallet();
            setWallet(storedWallet);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil Stellar wallet"));
        } finally {
            setLoadingWallet(false);
        }
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();

        if (!wallet) {
            setError("Hubungkan Stellar Wallet Testnet sebelum membuat invoice.");
            return;
        }

        if (!description.trim()) {
            setError("Description wajib diisi.");
            return;
        }

        if (!fiatAmount || Number(fiatAmount) <= 0) {
            setError("Amount IDR wajib lebih dari 0.");
            return;
        }

        try {
            setSubmitting(true);
            setError("");

            const invoice = await createInvoice({
                customer_name: customerName,
                customer_email: customerEmail,
                description,
                fiat_amount: Number(fiatAmount),
                recipient_public_key: wallet.public_key,
                expires_at: toApiDateTime(expiresAt),
            });

            navigate(`/invoices/${invoice.id}`);
        } catch (err: unknown) {
            setError(
                getFirstValidationError(err) ??
                    getApiErrorMessage(err, "Gagal membuat invoice"),
            );
        } finally {
            setSubmitting(false);
        }
    }

    useEffect(() => {
        const tomorrow = new Date();
        tomorrow.setDate(tomorrow.getDate() + 1);

        // eslint-disable-next-line react-hooks/set-state-in-effect
        setExpiresAt(toDateTimeLocalValue(tomorrow));
        fetchWallet();
    }, []);

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
                        Buat payment request merchant dengan metode Stellar Testnet.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">
                        Demo conversion rate — no real money.
                    </p>
                    <p className="mt-1 font-bold text-blue-600">
                        1 XLM = {formatIdr(DEMO_IDR_PER_XLM)}
                    </p>
                </div>
            </div>

            <TestnetNotice />

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            {!loadingWallet && !wallet && (
                <div className="flex items-start gap-3 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                    <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                    <div>
                        <p className="font-semibold">Stellar wallet belum terhubung.</p>
                        <p className="mt-1">
                            Hubungkan Freighter Testnet di{" "}
                            <Link
                                to="/accounts"
                                className="font-semibold text-amber-900 underline"
                            >
                                Accounts
                            </Link>{" "}
                            sebelum membuat invoice.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex items-center gap-3">
                        <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                            <FileText size={22} />
                        </div>
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Payment Request Details
                            </h2>
                            <p className="text-sm text-slate-500">
                                Status invoice baru otomatis pending dan dibayar lewat Stellar Testnet.
                            </p>
                        </div>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <TextInput
                                label="Customer Name (optional)"
                                value={customerName}
                                onChange={setCustomerName}
                                placeholder="Opsional"
                            />
                            <TextInput
                                label="Customer Email (optional)"
                                type="email"
                                value={customerEmail}
                                onChange={setCustomerEmail}
                                placeholder="customer@example.com"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Description
                            </label>
                            <textarea
                                value={description}
                                onChange={(event) => setDescription(event.target.value)}
                                rows={4}
                                placeholder="Contoh: Jasa desain poster kampus"
                                className="mt-1 w-full resize-none rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <TextInput
                                label="IDR Amount"
                                type="number"
                                value={fiatAmount}
                                onChange={setFiatAmount}
                                placeholder="75000"
                            />
                            <TextInput
                                label="Expires At"
                                type="datetime-local"
                                value={expiresAt}
                                onChange={setExpiresAt}
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Recipient Account
                            </label>
                            <select
                                value={wallet?.public_key ?? ""}
                                onChange={() => undefined}
                                disabled={!wallet}
                                className="mt-1 w-full rounded-xl border border-slate-200 bg-slate-50 px-4 py-2.5 font-mono text-sm outline-none"
                            >
                                <option value="">
                                    Connect a Stellar Testnet account in Accounts
                                </option>
                                {wallet && (
                                    <option value={wallet.public_key}>
                                        Freighter Testnet -{" "}
                                        {shortenPublicKey(wallet.public_key)}
                                    </option>
                                )}
                            </select>
                            {wallet && (
                                <div className="mt-2 flex flex-wrap items-center gap-2 text-xs text-slate-500">
                                    <span>Stored value:</span>
                                    <span className="font-mono">
                                        {shortenPublicKey(wallet.public_key)}
                                    </span>
                                    <CopyButton
                                        value={wallet.public_key}
                                        label="Copy Public Key"
                                        copiedLabel="Public Key Copied"
                                    />
                                </div>
                            )}
                        </div>

                        <div className="rounded-2xl bg-blue-50 p-4 text-sm text-blue-700">
                            <p className="font-semibold">Payment method</p>
                            <p className="mt-1">
                                Stellar Testnet only for this MVP.
                            </p>
                        </div>

                        <button
                            type="submit"
                            disabled={submitting || loadingWallet || !wallet}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            {submitting ? (
                                <Loader2 className="animate-spin" size={18} />
                            ) : (
                                <Plus size={18} />
                            )}
                            Create Payment Request
                        </button>
                    </form>
                </section>

                <aside className="space-y-6">
                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5 flex items-center gap-3">
                            <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                <WalletCards size={22} />
                            </div>
                            <div>
                                <h2 className="text-xl font-bold text-slate-950">
                                    Recipient Account
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Diambil dari Accounts &gt; Stellar milik user login.
                                </p>
                            </div>
                        </div>

                        {loadingWallet ? (
                            <div className="flex min-h-32 items-center justify-center text-slate-500">
                                <Loader2 className="mr-2 animate-spin" size={18} />
                                Loading wallet...
                            </div>
                        ) : wallet ? (
                            <div className="rounded-2xl bg-slate-50 p-4">
                                <p className="text-sm text-slate-500">Public Key</p>
                                <p className="mt-2 break-all font-mono text-sm font-semibold text-slate-900">
                                    {shortenPublicKey(wallet.public_key)}
                                </p>
                                <CopyButton
                                    value={wallet.public_key}
                                    label="Copy Public Key"
                                    copiedLabel="Public Key Copied"
                                    className="mt-3"
                                />
                                <p className="mt-3 rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                                    {wallet.network} / {wallet.wallet_provider}
                                </p>
                            </div>
                        ) : (
                            <p className="rounded-2xl bg-amber-50 p-4 text-sm text-amber-800">
                                Wallet belum tersedia.
                            </p>
                        )}
                    </section>

                    <section className="rounded-3xl bg-blue-50 p-5 text-sm text-blue-700">
                            <div className="flex items-start gap-3">
                                <ShieldCheck className="mt-0.5 shrink-0" size={18} />
                                <div>
                                <p className="font-semibold">
                                    Demo conversion rate — no real money.
                                </p>
                                <p className="mt-1">
                                    XLM equivalent dihitung memakai rate demo statis dan bukan
                                    data exchange-rate pasar.
                                </p>
                            </div>
                        </div>
                    </section>

                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <p className="text-sm text-slate-500">Preview</p>
                        <p className="mt-2 text-2xl font-bold text-slate-950">
                            {formatIdr(fiatAmount || 0)}
                        </p>
                        <p className="mt-1 text-sm font-semibold text-blue-600">
                            Stellar Testnet amount: {formatXlm(demoXlmAmount)} XLM
                        </p>
                        <p className="mt-1 text-xs text-slate-500">
                            Demo conversion rate — no real money.
                        </p>
                    </section>
                </aside>
            </div>
        </div>
    );
}

function TextInput({
    label,
    value,
    onChange,
    placeholder,
    type = "text",
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder?: string;
    type?: string;
}) {
    return (
        <div>
            <label className="text-sm font-semibold text-slate-700">{label}</label>
            <input
                type={type}
                min={type === "number" ? "0" : undefined}
                value={value}
                onChange={(event) => onChange(event.target.value)}
                placeholder={placeholder}
                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
            />
        </div>
    );
}
