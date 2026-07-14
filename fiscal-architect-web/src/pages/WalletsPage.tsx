import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import {
    Building2,
    Loader2,
    Plus,
    Trash2,
    Wallet as WalletIcon,
    Smartphone,
    PiggyBank,
} from "lucide-react";
import {
    createWallet,
    deleteWallet,
    getWallets,
} from "../features/wallets/walletApi";
import { getApiErrorMessage } from "../utils/apiError";
import type { Wallet, WalletType } from "../types/wallet";

function formatCurrency(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
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

function getWalletTypeLabel(type: WalletType) {
    const labels: Record<WalletType, string> = {
        cash: "Cash",
        bank: "Bank",
        ewallet: "E-Wallet",
        investment_cash: "Investment Cash",
    };

    return labels[type];
}

function getWalletIcon(type: WalletType) {
    if (type === "bank") return <Building2 size={20} />;
    if (type === "ewallet") return <Smartphone size={20} />;
    if (type === "investment_cash") return <PiggyBank size={20} />;
    return <WalletIcon size={20} />;
}

type WalletBadge = {
    label: string;
    className: string;
};

function getWalletBadges(wallet: Wallet): WalletBadge[] {
    const badges: WalletBadge[] = [];
    const source = wallet.sync_source ?? "manual";

    if (source === "manual") {
        badges.push({
            label: "manual",
            className: "bg-slate-100 text-slate-700",
        });
    }

    if (source === "open_banking_simulator") {
        badges.push({
            label: "open_banking_simulator",
            className: "bg-blue-50 text-blue-700",
        });
    }

    if (source === "open_banking_provider") {
        badges.push({
            label: "open_banking_provider",
            className: "bg-indigo-50 text-indigo-700",
        });
    }

    if (source === "brankas") {
        badges.push({
            label: "brankas",
            className: "bg-indigo-50 text-indigo-700",
        });
    }

    if (wallet.type === "investment_cash") {
        badges.push({
            label: "investment_cash",
            className: "bg-emerald-50 text-emerald-700",
        });
    }

    if (wallet.connection_status === "connected") {
        badges.push({
            label: "connected bank",
            className: "bg-emerald-50 text-emerald-700",
        });
    }

    if (wallet.connection_status === "revoked") {
        badges.push({
            label: "revoked",
            className: "bg-red-50 text-red-700",
        });
    }

    return badges;
}

function InfoRow({ label, value }: { label: string; value?: string | null }) {
    if (!value) return null;

    return (
        <div className="flex items-center justify-between gap-3 text-sm">
            <span className="text-slate-500">{label}</span>
            <span className="font-semibold text-slate-700">{value}</span>
        </div>
    );
}

export function WalletsPage() {
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [error, setError] = useState("");

    const [name, setName] = useState("");
    const [type, setType] = useState<WalletType>("bank");
    const [currency, setCurrency] = useState("IDR");
    const [openingBalance, setOpeningBalance] = useState("");

    const totalBalance = useMemo(() => {
        return wallets.reduce((total, wallet) => {
            return total + Number(wallet.current_balance);
        }, 0);
    }, [wallets]);

    async function fetchWallets() {
        try {
            setLoading(true);
            setError("");

            const data = await getWallets();
            setWallets(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data wallet"));
        } finally {
            setLoading(false);
        }
    }

    async function handleCreateWallet(event: FormEvent) {
        event.preventDefault();

        if (!name.trim()) {
            setError("Nama wallet wajib diisi");
            return;
        }

        try {
            setSubmitting(true);
            setError("");

            await createWallet({
                name,
                type,
                currency,
                opening_balance: Number(openingBalance || 0),
            });

            setName("");
            setType("bank");
            setCurrency("IDR");
            setOpeningBalance("");

            await fetchWallets();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membuat wallet"));
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDeleteWallet(wallet: Wallet) {
        const confirmed = window.confirm(
            `Yakin mau hapus wallet "${wallet.name}"?`
        );

        if (!confirmed) return;

        try {
            setDeletingId(wallet.id);
            setError("");

            await deleteWallet(wallet.id);
            await fetchWallets();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menghapus wallet"));
        } finally {
            setDeletingId(null);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchWallets();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Wallets
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Kelola rekening, cash, dan e-wallet kamu di satu tempat.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">Total Balance</p>
                    <p className="mt-1 text-2xl font-bold text-slate-950">
                        {formatCurrency(totalBalance)}
                    </p>
                </div>
            </div>

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex items-center justify-between">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Your Wallets
                            </h2>
                            <p className="text-sm text-slate-500">
                                Semua wallet milik user login.
                            </p>
                        </div>

                        <button
                            onClick={fetchWallets}
                            className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            Refresh
                        </button>
                    </div>

                    {loading ? (
                        <div className="flex min-h-[240px] items-center justify-center">
                            <div className="flex items-center gap-3 text-slate-500">
                                <Loader2 className="animate-spin" size={20} />
                                Loading wallets...
                            </div>
                        </div>
                    ) : wallets.length === 0 ? (
                        <div className="flex min-h-[240px] flex-col items-center justify-center rounded-2xl bg-slate-50 text-center">
                            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                                <WalletIcon size={28} />
                            </div>
                            <h3 className="font-semibold text-slate-900">
                                Belum ada wallet
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Tambahkan wallet pertama kamu dari form di kanan.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {wallets.map((wallet) => {
                                const badges = getWalletBadges(wallet);

                                return (
                                    <div
                                    key={wallet.id}
                                    className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-center gap-3">
                                            <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                                {getWalletIcon(wallet.type)}
                                            </div>

                                            <div>
                                                <h3 className="font-bold text-slate-950">
                                                    {wallet.name}
                                                </h3>
                                                <p className="text-sm text-slate-500">
                                                    {getWalletTypeLabel(wallet.type)} · {wallet.currency}
                                                </p>
                                            </div>
                                        </div>

                                        <button
                                            onClick={() => handleDeleteWallet(wallet)}
                                            disabled={deletingId === wallet.id}
                                            className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                        >
                                            {deletingId === wallet.id ? (
                                                <Loader2 className="animate-spin" size={18} />
                                            ) : (
                                                <Trash2 size={18} />
                                            )}
                                        </button>
                                    </div>

                                    <div className="mt-4 flex flex-wrap gap-2">
                                        {badges.map((badge) => (
                                            <span
                                                key={badge.label}
                                                className={`rounded-full px-3 py-1 text-xs font-bold ${badge.className}`}
                                            >
                                                {badge.label}
                                            </span>
                                        ))}
                                    </div>

                                    <div className="mt-6">
                                        <p className="text-sm text-slate-500">Current Balance</p>
                                        <p className="mt-1 text-2xl font-bold text-slate-950">
                                            {formatCurrency(wallet.current_balance)}
                                        </p>
                                    </div>

                                    <div className="mt-4 flex items-center justify-between text-sm">
                                        <span className="text-slate-500">Opening Balance</span>
                                        <span className="font-semibold text-slate-700">
                                            {formatCurrency(wallet.opening_balance)}
                                        </span>
                                    </div>

                                    {(wallet.provider_code ||
                                        wallet.account_number_masked ||
                                        wallet.sync_source ||
                                        wallet.last_synced_at) && (
                                            <div className="mt-4 space-y-2 rounded-2xl bg-white p-4">
                                                <InfoRow
                                                    label="Provider"
                                                    value={wallet.provider_code?.toUpperCase()}
                                                />
                                                <InfoRow
                                                    label="Account"
                                                    value={wallet.account_number_masked}
                                                />
                                                <InfoRow
                                                    label="Sync Source"
                                                    value={wallet.sync_source ?? "manual"}
                                                />
                                                <InfoRow
                                                    label="Last Sync"
                                                    value={formatDateTime(wallet.last_synced_at)}
                                                />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5">
                        <h2 className="text-xl font-bold text-slate-950">Add Wallet</h2>
                        <p className="text-sm text-slate-500">
                            Tambahkan wallet manual dulu. Open banking nanti menyusul.
                        </p>
                    </div>

                    <form onSubmit={handleCreateWallet} className="space-y-4">
                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Wallet Name
                            </label>
                            <input
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder="Contoh: BCA Utama"
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Wallet Type
                            </label>
                            <select
                                value={type}
                                onChange={(event) => setType(event.target.value as WalletType)}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            >
                                <option value="bank">Bank</option>
                                <option value="cash">Cash</option>
                                <option value="ewallet">E-Wallet</option>
                                <option value="investment_cash">Investment Cash</option>
                            </select>
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Currency
                            </label>
                            <input
                                value={currency}
                                onChange={(event) => setCurrency(event.target.value)}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Opening Balance
                            </label>
                            <input
                                type="number"
                                min="0"
                                value={openingBalance}
                                onChange={(event) => setOpeningBalance(event.target.value)}
                                placeholder="0"
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <button
                            type="submit"
                            disabled={submitting}
                            className="flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {submitting ? (
                                <>
                                    <Loader2 className="animate-spin" size={18} />
                                    Saving...
                                </>
                            ) : (
                                <>
                                    <Plus size={18} />
                                    Add Wallet
                                </>
                            )}
                        </button>
                    </form>

                    <div className="mt-6 rounded-2xl bg-blue-50 p-4 text-sm text-blue-700">
                        <p className="font-semibold">Next Feature</p>
                        <p className="mt-1">
                            Setelah manual wallet aman, kita tambah mode Hubungkan Rekening
                            BRI/Mandiri/BCA via simulator Open Banking.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
