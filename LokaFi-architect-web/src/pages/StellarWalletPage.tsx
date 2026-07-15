import { useEffect, useState } from "react";
import type { ReactNode } from "react";
import {
    AlertTriangle,
    CheckCircle2,
    ExternalLink,
    Loader2,
    PlugZap,
    RefreshCw,
    ShieldCheck,
    Unplug,
    WalletCards,
    X,
    XCircle,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice, STELLAR_TESTNET_NOTICE } from "../components/TestnetNotice";
import {
    disconnectStoredStellarWallet,
    getStoredStellarWallet,
    storeStellarWallet,
} from "../features/stellar/stellarApi";
import {
    connectFreighterWallet,
    detectFreighterAvailability,
    disconnectFreighterLocalSession,
    ensureFreighterTestnet,
    getNativeTestnetXlmBalance,
    STELLAR_TESTNET_HORIZON_URL,
} from "../features/stellar/stellarWalletService";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import {
    getTestnetAccountExplorerUrl,
    shortenPublicKey,
} from "../utils/invoiceFormat";
import type { StellarBalance, StellarWallet } from "../types/stellar";

function getPageErrorMessage(error: unknown, fallback: string) {
    return (
        getFirstValidationError(error) ??
        getApiErrorMessage(error, error instanceof Error ? error.message : fallback)
    );
}

function formatDateTime(date?: string | null) {
    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(date));
}

function formatXlmBalance(balance?: StellarBalance | null) {
    if (!balance) return "-";

    return new Intl.NumberFormat("id-ID", {
        maximumFractionDigits: 7,
    }).format(Number(balance.balance));
}

export function StellarWalletPage() {
    const [wallet, setWallet] = useState<StellarWallet | null>(null);
    const [balance, setBalance] = useState<StellarBalance | null>(null);
    const [loading, setLoading] = useState(true);
    const [checking, setChecking] = useState(false);
    const [connecting, setConnecting] = useState(false);
    const [refreshingBalance, setRefreshingBalance] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");
    const [availabilityMessage, setAvailabilityMessage] = useState("");

    function clearMessages() {
        setError("");
        setSuccessMessage("");
        setAvailabilityMessage("");
    }

    async function loadBalance(publicKey: string, clearExistingError = true) {
        try {
            setRefreshingBalance(true);

            if (clearExistingError) {
                setError("");
            }

            const nextBalance = await getNativeTestnetXlmBalance(publicKey);
            setBalance(nextBalance);
            return nextBalance;
        } catch (err: unknown) {
            setError(getPageErrorMessage(err, "Gagal mengambil saldo Testnet XLM"));
            return null;
        } finally {
            setRefreshingBalance(false);
        }
    }

    async function fetchStoredWallet() {
        try {
            setLoading(true);
            setError("");

            const storedWallet = await getStoredStellarWallet();
            setWallet(storedWallet);

            if (storedWallet) {
                await loadBalance(storedWallet.public_key, false);
            } else {
                setBalance(null);
            }
        } catch (err: unknown) {
            setError(getPageErrorMessage(err, "Gagal mengambil wallet Stellar"));
        } finally {
            setLoading(false);
        }
    }

    async function handleCheckFreighter() {
        try {
            setChecking(true);
            clearMessages();

            const availability = await detectFreighterAvailability();

            if (!availability.available) {
                setError(availability.message);
                return;
            }

            const networkInfo = await ensureFreighterTestnet();
            setAvailabilityMessage(
                `Freighter tersedia dan aktif di ${networkInfo.networkName || "Testnet"}.`,
            );
        } catch (err: unknown) {
            setError(getPageErrorMessage(err, "Gagal memeriksa Freighter"));
        } finally {
            setChecking(false);
        }
    }

    async function handleConnectWallet() {
        try {
            setConnecting(true);
            clearMessages();

            const connectedWallet = await connectFreighterWallet();
            const storedWallet = await storeStellarWallet({
                public_key: connectedWallet.publicKey,
                network: connectedWallet.network,
                wallet_provider: connectedWallet.walletProvider,
            });

            setWallet(storedWallet);
            setSuccessMessage("Stellar wallet Testnet berhasil terhubung.");
            await loadBalance(storedWallet.public_key, false);
        } catch (err: unknown) {
            setError(getPageErrorMessage(err, "Gagal menghubungkan Freighter"));
        } finally {
            setConnecting(false);
        }
    }

    async function handleRefreshBalance() {
        clearMessages();

        if (!wallet) {
            setError("Hubungkan Freighter Testnet dulu sebelum refresh saldo.");
            return;
        }

        await loadBalance(wallet.public_key);
    }

    async function handleDisconnectWallet() {
        const confirmed = window.confirm(
            "Putuskan sesi Stellar wallet dari aplikasi ini?",
        );

        if (!confirmed) return;

        try {
            setDisconnecting(true);
            clearMessages();

            await disconnectStoredStellarWallet();
            await disconnectFreighterLocalSession();
            setWallet(null);
            setBalance(null);
            setSuccessMessage("Sesi Stellar wallet di aplikasi berhasil diputus.");
        } catch (err: unknown) {
            setError(getPageErrorMessage(err, "Gagal memutus wallet Stellar"));
        } finally {
            setDisconnecting(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchStoredWallet();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const isConnected = Boolean(wallet);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Stellar Wallet
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Hubungkan Freighter untuk demo Stellar Testnet Lokafi.
                    </p>
                </div>

                <TestnetNotice />
            </div>

            {error && (
                <Alert tone="error" message={error} onDismiss={() => setError("")} />
            )}
            {successMessage && (
                <Alert
                    tone="success"
                    message={successMessage}
                    onDismiss={() => setSuccessMessage("")}
                />
            )}
            {availabilityMessage && (
                <Alert
                    tone="info"
                    message={availabilityMessage}
                    onDismiss={() => setAvailabilityMessage("")}
                />
            )}

            <div className="rounded-3xl bg-linear-to-br from-slate-900 to-blue-900 p-6 text-white shadow-lg">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-4">
                        <div className="rounded-2xl bg-white/10 p-4">
                            <ShieldCheck size={28} />
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-xl font-bold">Freighter Connection</h2>
                                <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold">
                                    Testnet
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-blue-100">
                                Aplikasi hanya menyimpan public key. Freighter tetap menjadi
                                tempat signing saat customer membayar invoice.
                            </p>
                        </div>
                    </div>

                    <div className="rounded-2xl bg-white/10 px-4 py-3 text-sm">
                        <p className="text-blue-100">Stored Data</p>
                        <p className="font-semibold">public key only</p>
                    </div>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-4 md:flex-row md:items-center">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Wallet Status
                            </h2>
                            <p className="text-sm text-slate-500">
                                Status sesi wallet Stellar milik user login.
                            </p>
                        </div>

                        <button
                            onClick={fetchStoredWallet}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            <RefreshCw size={16} />
                            Refresh
                        </button>
                    </div>

                    {loading ? (
                        <LoadingState text="Loading Stellar wallet..." />
                    ) : wallet ? (
                        <div className="space-y-5">
                            <div className="rounded-2xl border border-slate-100 bg-slate-50 p-5">
                                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                                    <div className="flex items-start gap-4">
                                        <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                            <WalletCards size={24} />
                                        </div>

                                        <div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                <h3 className="font-bold text-slate-950">
                                                    Freighter Wallet
                                                </h3>
                                                <StatusPill connected={isConnected} />
                                                <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                                                    {wallet.network}
                                                </span>
                                            </div>

                                            <p className="mt-2 font-mono text-sm text-slate-600">
                                                {shortenPublicKey(wallet.public_key)}
                                            </p>

                                            <div className="mt-4 flex flex-wrap gap-2">
                                                <CopyButton
                                                    value={wallet.public_key}
                                                    label="Copy Public Key"
                                                    copiedLabel="Public Key Copied"
                                                />

                                                <a
                                                    href={getTestnetAccountExplorerUrl(wallet.public_key)}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                                                >
                                                    <ExternalLink size={16} />
                                                    Explorer
                                                </a>
                                            </div>
                                        </div>
                                    </div>

                                    <div className="rounded-2xl bg-white px-4 py-3 text-sm">
                                        <p className="text-slate-500">Connected At</p>
                                        <p className="mt-1 font-semibold text-slate-900">
                                            {formatDateTime(wallet.connected_at)}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <div className="grid gap-4 md:grid-cols-3">
                                <InfoCard
                                    label="Public Key"
                                    value={shortenPublicKey(wallet.public_key)}
                                    icon={<WalletCards size={20} />}
                                />
                                <InfoCard
                                    label="Provider"
                                    value={wallet.wallet_provider}
                                    icon={<PlugZap size={20} />}
                                />
                                <InfoCard
                                    label="Horizon"
                                    value="Testnet"
                                    icon={<ShieldCheck size={20} />}
                                />
                            </div>
                        </div>
                    ) : (
                        <EmptyState
                            icon={<WalletCards size={28} />}
                            title="Belum ada wallet Stellar"
                            description="Klik Connect Freighter untuk menyimpan public key Testnet ke aplikasi."
                        />
                    )}
                </section>

                <aside className="space-y-6">
                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">
                                Connect Freighter
                            </h2>
                            <p className="text-sm text-slate-500">
                                Pastikan Freighter berada di network Testnet.
                            </p>
                        </div>

                        <div className="space-y-3">
                            <button
                                onClick={handleCheckFreighter}
                                disabled={checking}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-3 font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {checking ? (
                                    <Loader2 className="animate-spin" size={18} />
                                ) : (
                                    <ShieldCheck size={18} />
                                )}
                                Check Freighter
                            </button>

                            <button
                                onClick={handleConnectWallet}
                                disabled={connecting}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {connecting ? (
                                    <Loader2 className="animate-spin" size={18} />
                                ) : (
                                    <PlugZap size={18} />
                                )}
                                Connect Freighter
                            </button>

                            <button
                                onClick={handleDisconnectWallet}
                                disabled={!wallet || disconnecting}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-red-100 px-4 py-3 font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                {disconnecting ? (
                                    <Loader2 className="animate-spin" size={18} />
                                ) : (
                                    <Unplug size={18} />
                                )}
                                Disconnect Local Session
                            </button>
                        </div>
                    </section>

                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5 flex items-center justify-between gap-4">
                            <div>
                                <h2 className="text-xl font-bold text-slate-950">
                                    Native Balance
                                </h2>
                                <p className="text-sm text-slate-500">
                                    XLM dari Stellar Testnet Horizon.
                                </p>
                            </div>

                            <button
                                onClick={handleRefreshBalance}
                                disabled={!wallet || refreshingBalance}
                                className="rounded-xl p-2 text-slate-500 hover:bg-slate-100 disabled:cursor-not-allowed disabled:opacity-50"
                                aria-label="Refresh balance"
                            >
                                {refreshingBalance ? (
                                    <Loader2 className="animate-spin" size={18} />
                                ) : (
                                    <RefreshCw size={18} />
                                )}
                            </button>
                        </div>

                        <div className="rounded-2xl bg-slate-50 p-5">
                            <p className="text-sm text-slate-500">Balance</p>
                            <p className="mt-2 text-3xl font-bold tracking-tight text-slate-950">
                                {formatXlmBalance(balance)} XLM
                            </p>

                            {balance && (
                                <div className="mt-4 flex flex-wrap gap-2">
                                    <span
                                        className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${
                                            balance.is_funded
                                                ? "bg-emerald-50 text-emerald-700"
                                                : "bg-amber-50 text-amber-700"
                                        }`}
                                    >
                                        {balance.is_funded ? (
                                            <CheckCircle2 size={14} />
                                        ) : (
                                            <AlertTriangle size={14} />
                                        )}
                                        {balance.is_funded ? "funded" : "not funded"}
                                    </span>
                                    <span className="rounded-full bg-blue-50 px-3 py-1 text-xs font-bold text-blue-700">
                                        XLM native
                                    </span>
                                </div>
                            )}
                        </div>

                        <p className="mt-4 break-all text-xs text-slate-400">
                            {STELLAR_TESTNET_HORIZON_URL}
                        </p>
                    </section>

                    <section className="rounded-3xl bg-amber-50 p-5 text-sm text-amber-800">
                        <div className="flex items-start gap-3">
                            <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                            <div>
                                <p className="font-semibold">
                                    {STELLAR_TESTNET_NOTICE}
                                </p>
                                <p className="mt-1">
                                    Aplikasi tidak menyimpan secret key, mnemonic, recovery
                                    phrase, atau signed transaction payload.
                                </p>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    );
}

function Alert({
    tone,
    message,
    onDismiss,
}: {
    tone: "error" | "success" | "info";
    message: string;
    onDismiss: () => void;
}) {
    const toneClass = {
        error: "border-red-100 bg-red-50 text-red-700",
        success: "border-emerald-100 bg-emerald-50 text-emerald-700",
        info: "border-blue-100 bg-blue-50 text-blue-700",
    }[tone];

    return (
        <div
            className={`flex items-start justify-between gap-3 rounded-2xl border px-5 py-4 text-sm ${toneClass}`}
        >
            <span>{message}</span>
            <button
                onClick={onDismiss}
                className="rounded-lg p-1 hover:bg-white/60"
                aria-label="Dismiss message"
            >
                <X size={16} />
            </button>
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

function EmptyState({
    icon,
    title,
    description,
}: {
    icon: ReactNode;
    title: string;
    description: string;
}) {
    return (
        <div className="flex min-h-60 flex-col items-center justify-center rounded-2xl bg-slate-50 p-6 text-center">
            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                {icon}
            </div>
            <h3 className="font-semibold text-slate-900">{title}</h3>
            <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>
        </div>
    );
}

function StatusPill({ connected }: { connected: boolean }) {
    if (connected) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                <CheckCircle2 size={14} />
                connected
            </span>
        );
    }

    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">
            <XCircle size={14} />
            disconnected
        </span>
    );
}

function InfoCard({
    label,
    value,
    icon,
}: {
    label: string;
    value: string;
    icon: ReactNode;
}) {
    return (
        <div className="rounded-2xl bg-slate-50 p-4">
            <div className="flex items-center justify-between gap-3">
                <p className="text-sm text-slate-500">{label}</p>
                <div className="rounded-xl bg-white p-2 text-blue-600 shadow-sm">
                    {icon}
                </div>
            </div>
            <p className="mt-3 break-all font-semibold text-slate-900">{value}</p>
        </div>
    );
}
