import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { useSearchParams } from "react-router-dom";
import {
    AlertCircle,
    Building2,
    CheckCircle2,
    Clock3,
    ExternalLink,
    Landmark,
    Loader2,
    PlugZap,
    RefreshCw,
    ShieldCheck,
    Unplug,
    XCircle,
} from "lucide-react";
import {
    connectBank,
    getBankConnections,
    getBankProviders,
    revokeBankConnection,
    syncBankConnection,
} from "../features/bankConnections/bankConnectionApi";
import { getApiErrorMessage } from "../utils/apiError";
import type { BankConnection, BankProvider } from "../types/bankConnection";

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

function formatCurrency(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function getProviderBadge(providerCode: string) {
    if (providerCode === "bri") return "bg-blue-50 text-blue-700";
    if (providerCode === "mandiri") return "bg-yellow-50 text-yellow-700";
    if (providerCode === "bca") return "bg-sky-50 text-sky-700";

    return "bg-slate-50 text-slate-700";
}

function getStatusBadge(status: BankConnection["status"]) {
    if (status === "connected") {
        return {
            className: "bg-emerald-50 text-emerald-700",
            icon: <CheckCircle2 size={14} />,
            label: "connected",
        };
    }

    if (status === "pending") {
        return {
            className: "bg-amber-50 text-amber-700",
            icon: <Clock3 size={14} />,
            label: "pending",
        };
    }

    if (status === "failed") {
        return {
            className: "bg-red-50 text-red-700",
            icon: <XCircle size={14} />,
            label: "failed",
        };
    }

    if (status === "revoked") {
        return {
            className: "bg-slate-100 text-slate-700",
            icon: <Unplug size={14} />,
            label: "revoked",
        };
    }

    return {
        className: "bg-slate-50 text-slate-700",
        icon: <AlertCircle size={14} />,
        label: status,
    };
}

function getModeBadge(mode?: string | null) {
    if (mode === "brankas") return "bg-indigo-50 text-indigo-700";
    if (mode === "mock") return "bg-amber-50 text-amber-700";
    return "bg-slate-50 text-slate-700";
}

function getProviderStatusBadge(status: BankProvider["status"]) {
    if (status === "available") return "bg-emerald-50 text-emerald-700";
    if (status === "unavailable") return "bg-amber-50 text-amber-700";

    return "bg-slate-100 text-slate-600";
}

function notifyDataRefresh() {
    localStorage.setItem("finance_data_refresh_at", String(Date.now()));
    window.dispatchEvent(new Event("finance-data-refreshed"));
}

export function BankConnectionsPage() {
    const [searchParams] = useSearchParams();
    const [providers, setProviders] = useState<BankProvider[]>([]);
    const [connections, setConnections] = useState<BankConnection[]>([]);

    const [loading, setLoading] = useState(true);
    const [startingProviderCode, setStartingProviderCode] = useState<string | null>(null);
    const [syncingId, setSyncingId] = useState<number | null>(null);
    const [revokingId, setRevokingId] = useState<number | null>(null);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const connectedCount = useMemo(() => {
        return connections.filter((connection) => connection.status === "connected").length;
    }, [connections]);

    const pendingCount = useMemo(() => {
        return connections.filter((connection) => connection.status === "pending").length;
    }, [connections]);

    const isMockMode = providers.some((provider) => provider.mode === "mock");

    async function fetchData() {
        try {
            setLoading(true);
            setError("");

            const [providerData, connectionData] = await Promise.all([
                getBankProviders(),
                getBankConnections(),
            ]);

            setProviders(providerData);
            setConnections(connectionData);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data koneksi bank"));
        } finally {
            setLoading(false);
        }
    }

    async function refreshConnectionsOnly() {
        try {
            setError("");
            const connectionData = await getBankConnections();
            setConnections(connectionData);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal refresh koneksi bank"));
        }
    }

    async function handleStartConnection(providerCode: BankProvider["code"]) {
        try {
            setStartingProviderCode(providerCode);
            setError("");
            setSuccessMessage("");

            const result = await connectBank({
                provider_code: providerCode,
                redirect_to: `${window.location.origin}/bank-connections`,
            });

            if (!result.redirect_url) {
                setError("Backend tidak mengembalikan redirect URL Brankas.");
                return;
            }

            window.location.assign(result.redirect_url);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal memulai koneksi Brankas"));
        } finally {
            setStartingProviderCode(null);
        }
    }

    async function handleSync(connection: BankConnection) {
        try {
            setSyncingId(connection.id);
            setError("");
            setSuccessMessage("");

            const result = await syncBankConnection(connection.id);
            notifyDataRefresh();
            setSuccessMessage(
                `Sync berhasil. ${result.imported_transactions_count ?? 0} transaksi baru diimport.`,
            );

            await refreshConnectionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal sync rekening"));
        } finally {
            setSyncingId(null);
        }
    }

    async function handleRevoke(connection: BankConnection) {
        const confirmed = window.confirm(
            `Yakin mau cabut koneksi ${connection.provider_name} ${connection.account_number_masked ?? ""}?`,
        );

        if (!confirmed) return;

        try {
            setRevokingId(connection.id);
            setError("");
            setSuccessMessage("");

            await revokeBankConnection(connection.id);
            notifyDataRefresh();
            setSuccessMessage("Koneksi bank berhasil dicabut.");
            await refreshConnectionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal revoke koneksi"));
        } finally {
            setRevokingId(null);
        }
    }

    function handleProviderSubmit(event: FormEvent<HTMLFormElement>, providerCode: BankProvider["code"]) {
        event.preventDefault();
        handleStartConnection(providerCode);
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchData();
    }, []);

    useEffect(() => {
        const callbackStatus = searchParams.get("bank_connection_status");
        const message = searchParams.get("message");
        const imported = searchParams.get("imported");

        if (callbackStatus === "success") {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setSuccessMessage(
                `${message ?? "Koneksi bank berhasil."} ${imported ? `${imported} transaksi diimport.` : ""}`.trim(),
            );
            notifyDataRefresh();
            refreshConnectionsOnly();
        }

        if (callbackStatus === "failed") {
            setError(message ?? "Koneksi bank gagal.");
            refreshConnectionsOnly();
        }
    }, [searchParams]);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Bank Connections
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Hubungkan rekening melalui Brankas consent flow untuk saldo dan mutasi rekening.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <StatCard label="Connected" value={connectedCount} className="text-emerald-600" />
                    <StatCard label="Pending" value={pendingCount} className="text-amber-600" />
                </div>
            </div>

            {error && <Alert tone="error" message={error} />}
            {successMessage && <Alert tone="success" message={successMessage} />}

            <div className="rounded-3xl bg-linear-to-br from-slate-900 to-blue-900 p-6 text-white shadow-lg">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div className="flex items-center gap-4">
                        <div className="rounded-2xl bg-white/10 p-4">
                            <ShieldCheck size={28} />
                        </div>
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h2 className="text-xl font-bold">Brankas Account Linking</h2>
                                <span className="rounded-full bg-white/10 px-3 py-1 text-xs font-bold">
                                    {isMockMode ? "mock fallback" : "sandbox"}
                                </span>
                            </div>
                            <p className="mt-1 text-sm text-blue-100">
                                Flow ini hanya untuk account linking, balance retrieval, dan transaction import.
                                Tidak ada transfer atau pembayaran real.
                            </p>
                        </div>
                    </div>

                    <div className="rounded-2xl bg-white/10 px-4 py-3 text-sm">
                        <p className="text-blue-100">Scope</p>
                        <p className="font-semibold">Balance + Statement</p>
                    </div>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.35fr_1fr]">
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-4 md:flex-row md:items-center">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Connected Accounts
                            </h2>
                            <p className="text-sm text-slate-500">
                                Akun yang sudah masuk consent flow Brankas.
                            </p>
                        </div>

                        <button
                            onClick={fetchData}
                            className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            <RefreshCw size={16} />
                            Refresh
                        </button>
                    </div>

                    {loading ? (
                        <LoadingState text="Loading bank connections..." />
                    ) : connections.length === 0 ? (
                        <EmptyState
                            icon={<Landmark size={28} />}
                            title="Belum ada rekening terhubung"
                            description="Pilih provider di panel kanan untuk memulai consent Brankas."
                        />
                    ) : (
                        <div className="space-y-4">
                            {connections.map((connection) => {
                                const wallet = connection.wallets?.[0];
                                const statusBadge = getStatusBadge(connection.status);

                                return (
                                    <div
                                        key={connection.id}
                                        className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                    >
                                        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                                            <div className="flex items-start gap-4">
                                                <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                                    <Building2 size={22} />
                                                </div>

                                                <div>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <h3 className="font-bold text-slate-950">
                                                            {connection.provider_name}
                                                        </h3>

                                                        <span
                                                            className={`rounded-full px-3 py-1 text-xs font-bold uppercase ${getProviderBadge(
                                                                connection.provider_code,
                                                            )}`}
                                                        >
                                                            {connection.provider_code}
                                                        </span>

                                                        <span
                                                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${statusBadge.className}`}
                                                        >
                                                            {statusBadge.icon}
                                                            {statusBadge.label}
                                                        </span>

                                                        <span
                                                            className={`rounded-full px-3 py-1 text-xs font-bold ${getModeBadge(
                                                                connection.mode,
                                                            )}`}
                                                        >
                                                            {connection.mode ?? "brankas"}
                                                        </span>
                                                    </div>

                                                    <p className="mt-1 text-sm text-slate-500">
                                                        {connection.account_holder_name ?? "-"} -{" "}
                                                        {connection.account_number_masked ?? "menunggu callback"}
                                                    </p>

                                                    <p className="mt-2 text-xs text-slate-400">
                                                        Last synced: {formatDateTime(connection.last_synced_at)}
                                                    </p>

                                                    {connection.error_message && (
                                                        <p className="mt-2 text-sm text-red-600">
                                                            {connection.error_message}
                                                        </p>
                                                    )}
                                                </div>
                                            </div>

                                            <div className="flex flex-wrap gap-2">
                                                <button
                                                    onClick={() => handleSync(connection)}
                                                    disabled={
                                                        syncingId === connection.id ||
                                                        connection.status !== "connected"
                                                    }
                                                    className="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    {syncingId === connection.id ? (
                                                        <Loader2 className="animate-spin" size={16} />
                                                    ) : (
                                                        <RefreshCw size={16} />
                                                    )}
                                                    Sync
                                                </button>

                                                <button
                                                    onClick={() => handleRevoke(connection)}
                                                    disabled={
                                                        revokingId === connection.id ||
                                                        connection.status === "revoked"
                                                    }
                                                    className="inline-flex items-center gap-2 rounded-xl border border-red-100 bg-white px-3 py-2 text-sm font-semibold text-red-600 hover:bg-red-50 disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    {revokingId === connection.id ? (
                                                        <Loader2 className="animate-spin" size={16} />
                                                    ) : (
                                                        <Unplug size={16} />
                                                    )}
                                                    Revoke
                                                </button>
                                            </div>
                                        </div>

                                        {wallet && (
                                            <div className="mt-5 grid gap-3 rounded-2xl bg-white p-4 md:grid-cols-3">
                                                <InfoBlock label="Wallet" value={wallet.name} />
                                                <InfoBlock
                                                    label="Current Balance"
                                                    value={formatCurrency(wallet.current_balance)}
                                                />
                                                <InfoBlock
                                                    label="Sync Source"
                                                    value={wallet.sync_source ?? "open_banking_provider"}
                                                />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </section>

                <aside className="space-y-6">
                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">Connect Bank</h2>
                            <p className="text-sm text-slate-500">
                                Pilih bank lalu lanjutkan consent di Brankas.
                            </p>
                        </div>

                        {loading ? (
                            <LoadingState text="Loading providers..." />
                        ) : providers.length === 0 ? (
                            <EmptyState
                                icon={<Landmark size={24} />}
                                title="Provider belum tersedia"
                                description="Cek konfigurasi Brankas atau mock fallback."
                            />
                        ) : (
                            <div className="space-y-3">
                                {providers.map((provider) => (
                                    <form
                                        key={provider.code}
                                        onSubmit={(event) => handleProviderSubmit(event, provider.code)}
                                        className="rounded-2xl border border-slate-100 bg-slate-50 p-4"
                                    >
                                        <div className="flex items-start justify-between gap-3">
                                            <div>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <p className="font-bold text-slate-950">
                                                        {provider.name}
                                                    </p>
                                                    <span
                                                        className={`rounded-full px-2.5 py-1 text-xs font-bold uppercase ${getProviderBadge(
                                                            provider.code,
                                                        )}`}
                                                    >
                                                        {provider.code}
                                                    </span>
                                                </div>
                                                <p className="mt-1 text-xs uppercase text-slate-500">
                                                    {provider.brankas_code ?? provider.code} - {provider.mode}
                                                </p>
                                            </div>

                                            <span
                                                className={`rounded-full px-3 py-1 text-xs font-bold ${getProviderStatusBadge(
                                                    provider.status,
                                                )}`}
                                            >
                                                {provider.status}
                                            </span>
                                        </div>

                                        <p className="mt-3 text-sm text-slate-500">
                                            {provider.description}
                                        </p>

                                        <button
                                            type="submit"
                                            disabled={
                                                startingProviderCode === provider.code ||
                                                provider.status !== "available"
                                            }
                                            className="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                        >
                                            {startingProviderCode === provider.code ? (
                                                <>
                                                    <Loader2 className="animate-spin" size={18} />
                                                    Starting...
                                                </>
                                            ) : (
                                                <>
                                                    <PlugZap size={18} />
                                                    Connect Bank
                                                    <ExternalLink size={16} />
                                                </>
                                            )}
                                        </button>
                                    </form>
                                ))}
                            </div>
                        )}
                    </section>

                    <section className="rounded-3xl bg-blue-50 p-5 text-sm text-blue-700">
                        <div className="flex items-start gap-3">
                            <CheckCircle2 className="mt-0.5" size={18} />
                            <div>
                                <p className="font-semibold">Integration Scope</p>
                                <p className="mt-1">
                                    Account linking, balance retrieval, dan import mutasi saja.
                                    Transfer dan pembayaran tidak diaktifkan.
                                </p>
                            </div>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    );
}

function StatCard({
    label,
    value,
    className,
}: {
    label: string;
    value: number;
    className: string;
}) {
    return (
        <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
            <p className="text-sm text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${className}`}>{value}</p>
        </div>
    );
}

function Alert({ tone, message }: { tone: "error" | "success"; message: string }) {
    const className =
        tone === "error"
            ? "border-red-100 bg-red-50 text-red-700"
            : "border-emerald-100 bg-emerald-50 text-emerald-700";

    return (
        <div className={`rounded-2xl border px-5 py-4 text-sm ${className}`}>
            {message}
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
    icon: React.ReactNode;
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

function InfoBlock({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-slate-500">{label}</p>
            <p className="mt-1 font-semibold text-slate-900">{value}</p>
        </div>
    );
}
