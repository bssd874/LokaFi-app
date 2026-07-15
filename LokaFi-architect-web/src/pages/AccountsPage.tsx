import { useEffect, useMemo, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { Link } from "react-router-dom";
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    CreditCard,
    Landmark,
    Loader2,
    Plus,
    RefreshCw,
    ShieldCheck,
    Smartphone,
    Trash2,
    Unplug,
    Upload,
    Wallet as WalletIcon,
    WalletCards,
} from "lucide-react";
import { CopyButton } from "../components/CopyButton";
import { TestnetNotice } from "../components/TestnetNotice";
import { createWallet, deleteWallet, getWallets } from "../features/wallets/walletApi";
import { getTransactionImports } from "../features/transactionImports/transactionImportApi";
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
} from "../features/stellar/stellarWalletService";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import { formatDateTime, formatIdr, formatXlm, shortenPublicKey } from "../utils/invoiceFormat";
import type { TransactionImportBatch } from "../types/transactionImport";
import type { StellarBalance, StellarWallet } from "../types/stellar";
import type { Wallet, WalletType } from "../types/wallet";

type AccountTab = "overview" | "cash" | "bank" | "stellar";
type AddSourceType = "cash" | "bank" | "ewallet" | "stellar";

const tabs: Array<{ id: AccountTab; label: string }> = [
    { id: "overview", label: "Overview" },
    { id: "cash", label: "Cash" },
    { id: "bank", label: "Bank & E-Wallet" },
    { id: "stellar", label: "Stellar" },
];

const addSourceOptions: Array<{
    id: AddSourceType;
    label: string;
    description: string;
    icon: ReactNode;
}> = [
    {
        id: "cash",
        label: "Cash Wallet",
        description: "Manual cash balance for everyday transactions.",
        icon: <WalletIcon size={18} />,
    },
    {
        id: "bank",
        label: "Bank Account",
        description: "Local account metadata plus CSV statement import.",
        icon: <Landmark size={18} />,
    },
    {
        id: "ewallet",
        label: "E-Wallet",
        description: "Local provider metadata plus CSV history import.",
        icon: <Smartphone size={18} />,
    },
    {
        id: "stellar",
        label: "Stellar Wallet",
        description: "Freighter Testnet public key and XLM balance.",
        icon: <WalletCards size={18} />,
    },
];

function walletTypeLabel(type: WalletType) {
    const labels: Record<WalletType, string> = {
        cash: "Cash Wallet",
        bank: "Bank Account",
        ewallet: "E-Wallet",
        investment_cash: "Investment Cash",
    };

    return labels[type];
}

function sourceTypeForAddSource(type: AddSourceType): WalletType {
    if (type === "bank") return "bank";
    if (type === "ewallet") return "ewallet";
    return "cash";
}

function formatBalance(value: number | string) {
    return formatIdr(value);
}

function latestImportFor(
    batches: TransactionImportBatch[],
    sourceType: "bank_csv" | "ewallet_csv",
) {
    return batches.find((batch) => batch.source_type === sourceType) ?? null;
}

function importStatusLabel(batch?: TransactionImportBatch | null) {
    if (!batch) return "No CSV import yet";

    return `${batch.status}: ${batch.imported_count} imported, ${batch.duplicate_count} duplicate, ${batch.invalid_count} invalid`;
}

export function AccountsPage() {
    const [activeTab, setActiveTab] = useState<AccountTab>("overview");
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [imports, setImports] = useState<TransactionImportBatch[]>([]);
    const [stellarWallet, setStellarWallet] = useState<StellarWallet | null>(null);
    const [stellarBalance, setStellarBalance] = useState<StellarBalance | null>(null);

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [stellarBusy, setStellarBusy] = useState(false);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");
    const [stellarMessage, setStellarMessage] = useState("");

    const [sourceType, setSourceType] = useState<AddSourceType>("cash");
    const [sourceName, setSourceName] = useState("");
    const [providerLabel, setProviderLabel] = useState("");
    const [accountMasked, setAccountMasked] = useState("");
    const [currency, setCurrency] = useState("IDR");
    const [openingBalance, setOpeningBalance] = useState("");

    const cashWallets = useMemo(
        () => wallets.filter((wallet) => wallet.type === "cash"),
        [wallets],
    );
    const bankWallets = useMemo(
        () => wallets.filter((wallet) => wallet.type === "bank"),
        [wallets],
    );
    const ewallets = useMemo(
        () => wallets.filter((wallet) => wallet.type === "ewallet"),
        [wallets],
    );
    const totalBalance = useMemo(
        () => wallets.reduce((total, wallet) => total + Number(wallet.current_balance), 0),
        [wallets],
    );
    const latestBankImport = latestImportFor(imports, "bank_csv");
    const latestEwalletImport = latestImportFor(imports, "ewallet_csv");

    async function fetchAccounts() {
        try {
            setLoading(true);
            setError("");

            const [walletData, importPage, storedStellarWallet] = await Promise.all([
                getWallets(),
                getTransactionImports(),
                getStoredStellarWallet(),
            ]);

            setWallets(walletData);
            setImports(importPage.data);
            setStellarWallet(storedStellarWallet);

            if (storedStellarWallet) {
                try {
                    const balance = await getNativeTestnetXlmBalance(storedStellarWallet.public_key);
                    setStellarBalance(balance);
                } catch {
                    setStellarBalance(null);
                }
            } else {
                setStellarBalance(null);
            }
        } catch (err: unknown) {
            setError(
                getFirstValidationError(err) ??
                getApiErrorMessage(err, "Gagal mengambil data accounts"),
            );
        } finally {
            setLoading(false);
        }
    }

    async function handleCreateSource(event: FormEvent) {
        event.preventDefault();

        if (sourceType === "stellar") {
            setActiveTab("stellar");
            setError("");
            setSuccessMessage("Gunakan panel Stellar untuk connect Freighter Testnet.");
            return;
        }

        const walletType = sourceTypeForAddSource(sourceType);
        const fallbackName = providerLabel
            ? `${providerLabel} ${walletTypeLabel(walletType)}`
            : walletTypeLabel(walletType);
        const name = sourceName.trim() || fallbackName;

        try {
            setSubmitting(true);
            setError("");
            setSuccessMessage("");

            await createWallet({
                name,
                type: walletType,
                currency,
                opening_balance: Number(openingBalance || 0),
                provider_code: providerLabel || undefined,
                account_number_masked: accountMasked || undefined,
            });

            setSourceName("");
            setProviderLabel("");
            setAccountMasked("");
            setOpeningBalance("");
            setSuccessMessage(`${walletTypeLabel(walletType)} berhasil dibuat.`);
            await fetchAccounts();

            if (walletType === "cash") {
                setActiveTab("cash");
            } else {
                setActiveTab("bank");
            }
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membuat financial source"));
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDeleteWallet(wallet: Wallet) {
        const confirmed = window.confirm(`Hapus account "${wallet.name}" dari aplikasi?`);

        if (!confirmed) return;

        try {
            setDeletingId(wallet.id);
            setError("");
            setSuccessMessage("");

            await deleteWallet(wallet.id);
            setSuccessMessage("Account berhasil dihapus.");
            await fetchAccounts();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menghapus account"));
        } finally {
            setDeletingId(null);
        }
    }

    async function handleCheckFreighter() {
        try {
            setStellarBusy(true);
            setError("");
            setStellarMessage("");

            const availability = await detectFreighterAvailability();

            if (!availability.available) {
                setError(availability.message);
                return;
            }

            const network = await ensureFreighterTestnet();
            setStellarMessage(`Freighter tersedia di ${network.networkName || "Testnet"}.`);
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : "Gagal memeriksa Freighter.");
        } finally {
            setStellarBusy(false);
        }
    }

    async function handleConnectStellar() {
        try {
            setStellarBusy(true);
            setError("");
            setSuccessMessage("");
            setStellarMessage("");

            const wallet = await connectFreighterWallet();
            const storedWallet = await storeStellarWallet({
                public_key: wallet.publicKey,
                network: wallet.network,
                wallet_provider: wallet.walletProvider,
            });
            const balance = await getNativeTestnetXlmBalance(storedWallet.public_key);

            setStellarWallet(storedWallet);
            setStellarBalance(balance);
            setSuccessMessage("Stellar Wallet Testnet berhasil terhubung.");
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : "Gagal connect Freighter.");
        } finally {
            setStellarBusy(false);
        }
    }

    async function handleDisconnectStellar() {
        const confirmed = window.confirm("Putuskan sesi Stellar wallet lokal dari aplikasi?");

        if (!confirmed) return;

        try {
            setStellarBusy(true);
            setError("");
            setSuccessMessage("");
            setStellarMessage("");

            await disconnectStoredStellarWallet();
            await disconnectFreighterLocalSession();
            setStellarWallet(null);
            setStellarBalance(null);
            setSuccessMessage("Sesi Stellar wallet lokal berhasil diputus.");
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal disconnect Stellar wallet"));
        } finally {
            setStellarBusy(false);
        }
    }

    async function refreshStellarBalance() {
        if (!stellarWallet) {
            setError("Connect Freighter Testnet dulu.");
            return;
        }

        try {
            setStellarBusy(true);
            setError("");
            setStellarBalance(await getNativeTestnetXlmBalance(stellarWallet.public_key));
        } catch (err: unknown) {
            setError(err instanceof Error ? err.message : "Gagal mengambil saldo XLM.");
        } finally {
            setStellarBusy(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchAccounts();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Accounts
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Kelola cash wallet, bank/e-wallet statement import, dan Stellar Testnet wallet dari satu tempat.
                    </p>
                </div>

                <button
                    onClick={fetchAccounts}
                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 shadow-sm hover:bg-slate-50"
                >
                    <RefreshCw size={16} />
                    Refresh
                </button>
            </div>

            {error && <Alert tone="error" message={error} />}
            {successMessage && <Alert tone="success" message={successMessage} />}
            {stellarMessage && <Alert tone="info" message={stellarMessage} />}

            <div className="grid gap-6 xl:grid-cols-[1.55fr_0.9fr]">
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-wrap gap-2">
                        {tabs.map((tab) => (
                            <button
                                key={tab.id}
                                onClick={() => setActiveTab(tab.id)}
                                className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${
                                    activeTab === tab.id
                                        ? "bg-blue-600 text-white"
                                        : "bg-slate-100 text-slate-600 hover:bg-slate-200"
                                }`}
                            >
                                {tab.label}
                            </button>
                        ))}
                    </div>

                    {loading ? (
                        <LoadingState text="Loading accounts..." />
                    ) : (
                        <>
                            {activeTab === "overview" && (
                                <OverviewTab
                                    totalBalance={totalBalance}
                                    cashWallets={cashWallets}
                                    bankWallets={bankWallets}
                                    ewallets={ewallets}
                                    stellarWallet={stellarWallet}
                                    stellarBalance={stellarBalance}
                                    latestBankImport={latestBankImport}
                                    latestEwalletImport={latestEwalletImport}
                                    onSelectTab={setActiveTab}
                                />
                            )}

                            {activeTab === "cash" && (
                                <WalletSection
                                    title="Cash Wallets"
                                    description="Manual accounts for physical cash and offline balances."
                                    wallets={cashWallets}
                                    emptyTitle="Belum ada cash wallet"
                                    emptyDescription="Tambahkan Cash Wallet dari panel Add Financial Source."
                                    icon={<WalletIcon size={22} />}
                                    action={
                                        <Link
                                            to="/transactions"
                                            className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
                                        >
                                            <CreditCard size={16} />
                                            Record Manual Transactions
                                        </Link>
                                    }
                                    deletingId={deletingId}
                                    onDelete={handleDeleteWallet}
                                />
                            )}

                            {activeTab === "bank" && (
                                <BankEwalletTab
                                    bankWallets={bankWallets}
                                    ewallets={ewallets}
                                    latestBankImport={latestBankImport}
                                    latestEwalletImport={latestEwalletImport}
                                    deletingId={deletingId}
                                    onDelete={handleDeleteWallet}
                                />
                            )}

                            {activeTab === "stellar" && (
                                <StellarTab
                                    wallet={stellarWallet}
                                    balance={stellarBalance}
                                    busy={stellarBusy}
                                    onCheck={handleCheckFreighter}
                                    onConnect={handleConnectStellar}
                                    onDisconnect={handleDisconnectStellar}
                                    onRefreshBalance={refreshStellarBalance}
                                />
                            )}
                        </>
                    )}
                </section>

                <aside className="space-y-6">
                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">
                                Add Financial Source
                            </h2>
                            <p className="text-sm text-slate-500">
                                Choose how this account should be represented in LokaFi.
                            </p>
                        </div>

                        <div className="mb-5 grid gap-2">
                            {addSourceOptions.map((option) => (
                                <button
                                    key={option.id}
                                    type="button"
                                    onClick={() => {
                                        setSourceType(option.id);
                                        if (option.id === "stellar") {
                                            setActiveTab("stellar");
                                        }
                                    }}
                                    className={`flex items-start gap-3 rounded-2xl border p-4 text-left transition ${
                                        sourceType === option.id
                                            ? "border-blue-200 bg-blue-50 text-blue-800"
                                            : "border-slate-100 bg-slate-50 text-slate-700 hover:bg-white"
                                    }`}
                                >
                                    <div className="mt-0.5 rounded-xl bg-white p-2 text-blue-600 shadow-sm">
                                        {option.icon}
                                    </div>
                                    <div>
                                        <p className="font-bold">{option.label}</p>
                                        <p className="mt-1 text-xs">{option.description}</p>
                                    </div>
                                </button>
                            ))}
                        </div>

                        <form onSubmit={handleCreateSource} className="space-y-4">
                            {sourceType !== "stellar" ? (
                                <>
                                    <TextInput
                                        label="Account Name"
                                        value={sourceName}
                                        onChange={setSourceName}
                                        placeholder={
                                            sourceType === "cash"
                                                ? "Cash on Hand"
                                                : sourceType === "bank"
                                                    ? "BCA Main Account"
                                                    : "OVO Personal"
                                        }
                                    />

                                    {sourceType !== "cash" && (
                                        <>
                                            <TextInput
                                                label="Provider Label"
                                                value={providerLabel}
                                                onChange={setProviderLabel}
                                                placeholder={sourceType === "bank" ? "BCA / Mandiri" : "OVO / GoPay"}
                                            />
                                            <TextInput
                                                label="Masked Account Label"
                                                value={accountMasked}
                                                onChange={setAccountMasked}
                                                placeholder={sourceType === "bank" ? "****1234" : "user@example.com / 08****1234"}
                                            />
                                        </>
                                    )}

                                    <TextInput
                                        label="Currency"
                                        value={currency}
                                        onChange={setCurrency}
                                    />

                                    <TextInput
                                        label="Opening Balance"
                                        type="number"
                                        value={openingBalance}
                                        onChange={setOpeningBalance}
                                        placeholder="0"
                                    />

                                    <button
                                        type="submit"
                                        disabled={submitting}
                                        className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                                    >
                                        {submitting ? (
                                            <Loader2 className="animate-spin" size={18} />
                                        ) : (
                                            <Plus size={18} />
                                        )}
                                        Create Manual Account
                                    </button>
                                </>
                            ) : (
                                <button
                                    type="submit"
                                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700"
                                >
                                    <WalletCards size={18} />
                                    Open Stellar Connection Panel
                                </button>
                            )}
                        </form>

                        <div className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-800">
                            <p className="font-semibold">No direct credential collection</p>
                            <p className="mt-1">
                                Bank and e-wallet accounts here are local metadata plus CSV statement import.
                                LokaFi does not ask for bank login, PIN, OTP, or password.
                            </p>
                        </div>
                    </section>
                </aside>
            </div>
        </div>
    );
}

function OverviewTab({
    totalBalance,
    cashWallets,
    bankWallets,
    ewallets,
    stellarWallet,
    stellarBalance,
    latestBankImport,
    latestEwalletImport,
    onSelectTab,
}: {
    totalBalance: number;
    cashWallets: Wallet[];
    bankWallets: Wallet[];
    ewallets: Wallet[];
    stellarWallet: StellarWallet | null;
    stellarBalance: StellarBalance | null;
    latestBankImport: TransactionImportBatch | null;
    latestEwalletImport: TransactionImportBatch | null;
    onSelectTab: (tab: AccountTab) => void;
}) {
    return (
        <div className="space-y-6">
            <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <OverviewCard
                    title="Cash Wallet"
                    value={`${cashWallets.length} accounts`}
                    detail={formatBalance(cashWallets.reduce((total, wallet) => total + Number(wallet.current_balance), 0))}
                    icon={<WalletIcon size={22} />}
                    onClick={() => onSelectTab("cash")}
                />
                <OverviewCard
                    title="Bank Account"
                    value={`${bankWallets.length} accounts`}
                    detail={importStatusLabel(latestBankImport)}
                    icon={<Landmark size={22} />}
                    onClick={() => onSelectTab("bank")}
                />
                <OverviewCard
                    title="E-Wallet"
                    value={`${ewallets.length} accounts`}
                    detail={importStatusLabel(latestEwalletImport)}
                    icon={<Smartphone size={22} />}
                    onClick={() => onSelectTab("bank")}
                />
                <OverviewCard
                    title="Stellar Wallet"
                    value={stellarWallet ? "connected" : "not connected"}
                    detail={stellarWallet ? `${formatXlm(stellarBalance?.balance ?? 0)} XLM Testnet` : "Freighter Testnet"}
                    icon={<WalletCards size={22} />}
                    onClick={() => onSelectTab("stellar")}
                />
            </div>

            <div className="rounded-3xl bg-linear-to-br from-slate-900 to-blue-900 p-6 text-white shadow-lg">
                <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                    <div>
                        <h2 className="text-xl font-bold">Unified source balance</h2>
                        <p className="mt-1 text-sm text-blue-100">
                            Manual cash, bank metadata, and e-wallet metadata feed the existing wallet and transaction system.
                        </p>
                    </div>
                    <div className="rounded-2xl bg-white/10 px-5 py-4">
                        <p className="text-sm text-blue-100">Manual IDR Balance</p>
                        <p className="mt-1 text-2xl font-bold">{formatBalance(totalBalance)}</p>
                    </div>
                </div>
            </div>

            <div className="grid gap-4 md:grid-cols-3">
                <FlowCard
                    title="Cash"
                    description="Create manual accounts, view balance, and record manual transactions."
                    to="/transactions"
                    action="Record Transaction"
                />
                <FlowCard
                    title="Bank & E-Wallet"
                    description="Upload CSV statement files. No direct synchronization is presented as live."
                    to="/transaction-imports"
                    action="Import CSV"
                />
                <FlowCard
                    title="Stellar"
                    description="Connect Freighter on Testnet. Only the public key is stored."
                    action="Open Stellar"
                    onClick={() => onSelectTab("stellar")}
                />
            </div>
        </div>
    );
}

function BankEwalletTab({
    bankWallets,
    ewallets,
    latestBankImport,
    latestEwalletImport,
    deletingId,
    onDelete,
}: {
    bankWallets: Wallet[];
    ewallets: Wallet[];
    latestBankImport: TransactionImportBatch | null;
    latestEwalletImport: TransactionImportBatch | null;
    deletingId: number | null;
    onDelete: (wallet: Wallet) => void;
}) {
    return (
        <div className="space-y-6">
            <section className="rounded-2xl border border-amber-100 bg-amber-50 p-5 text-sm text-amber-800">
                <div className="flex items-start gap-3">
                    <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                    <div>
                        <p className="font-semibold">Statement import, not live synchronization</p>
                        <p className="mt-1">
                            Bank and e-wallet accounts in this MVP store local metadata and imported CSV history only.
                            Direct bank/e-wallet login and live sync are not enabled.
                        </p>
                    </div>
                </div>
            </section>

            <div className="grid gap-4 md:grid-cols-2">
                <ImportStatusCard
                    title="Latest Bank CSV Import"
                    batch={latestBankImport}
                    sourceType="bank_csv"
                />
                <ImportStatusCard
                    title="Latest E-Wallet CSV Import"
                    batch={latestEwalletImport}
                    sourceType="ewallet_csv"
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <WalletSection
                    title="Bank Accounts"
                    description="Local bank account labels and balances. Use CSV import for statements."
                    wallets={bankWallets}
                    emptyTitle="Belum ada bank account"
                    emptyDescription="Tambahkan Bank Account dari panel Add Financial Source."
                    icon={<Building2 size={22} />}
                    action={<CsvImportButton label="Import Bank CSV Statement" />}
                    deletingId={deletingId}
                    onDelete={onDelete}
                />
                <WalletSection
                    title="E-Wallets"
                    description="Local e-wallet labels and balances. Use CSV import for transaction history."
                    wallets={ewallets}
                    emptyTitle="Belum ada e-wallet"
                    emptyDescription="Tambahkan E-Wallet dari panel Add Financial Source."
                    icon={<Smartphone size={22} />}
                    action={<CsvImportButton label="Import E-Wallet History" />}
                    deletingId={deletingId}
                    onDelete={onDelete}
                />
            </div>
        </div>
    );
}

function StellarTab({
    wallet,
    balance,
    busy,
    onCheck,
    onConnect,
    onDisconnect,
    onRefreshBalance,
}: {
    wallet: StellarWallet | null;
    balance: StellarBalance | null;
    busy: boolean;
    onCheck: () => void;
    onConnect: () => void;
    onDisconnect: () => void;
    onRefreshBalance: () => void;
}) {
    return (
        <div className="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
            <section className="space-y-5">
                <div className="flex flex-col justify-between gap-3 rounded-2xl bg-slate-50 p-5 md:flex-row md:items-center">
                    <div>
                        <h2 className="text-xl font-bold text-slate-950">Stellar Wallet</h2>
                        <p className="text-sm text-slate-500">
                            Freighter signs in the browser. LokaFi stores public key only.
                        </p>
                    </div>
                    <TestnetNotice />
                </div>

                {wallet ? (
                    <div className="rounded-2xl border border-slate-100 bg-white p-5 shadow-sm">
                        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                            <div className="flex items-start gap-4">
                                <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                    <WalletCards size={24} />
                                </div>
                                <div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        <h3 className="font-bold text-slate-950">Freighter Testnet</h3>
                                        <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 text-xs font-bold text-emerald-700">
                                            <CheckCircle2 size={14} />
                                            connected
                                        </span>
                                    </div>
                                    <p className="mt-2 font-mono text-sm text-slate-600">
                                        {shortenPublicKey(wallet.public_key)}
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2">
                                        <CopyButton
                                            value={wallet.public_key}
                                            label="Copy Public Key"
                                            copiedLabel="Public Key Copied"
                                        />
                                        <button
                                            onClick={onRefreshBalance}
                                            disabled={busy}
                                            className="inline-flex items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                                        >
                                            {busy ? <Loader2 className="animate-spin" size={16} /> : <RefreshCw size={16} />}
                                            Refresh Balance
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div className="rounded-2xl bg-slate-50 px-4 py-3">
                                <p className="text-sm text-slate-500">Connected At</p>
                                <p className="mt-1 font-semibold text-slate-900">
                                    {formatDateTime(wallet.connected_at)}
                                </p>
                            </div>
                        </div>
                    </div>
                ) : (
                    <EmptyState
                        icon={<WalletCards size={28} />}
                        title="Belum ada Stellar wallet"
                        description="Connect Freighter di Testnet untuk menyimpan public key lokal."
                    />
                )}
            </section>

            <aside className="space-y-4">
                <div className="rounded-2xl bg-slate-50 p-5">
                    <p className="text-sm text-slate-500">Native XLM Balance</p>
                    <p className="mt-2 text-3xl font-bold text-slate-950">
                        {balance ? formatXlm(balance.balance) : "-"} XLM
                    </p>
                    <p className="mt-2 text-xs text-slate-500">
                        Stellar Testnet only - no real money.
                    </p>
                </div>

                <button
                    onClick={onCheck}
                    disabled={busy}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-3 font-semibold text-slate-600 hover:bg-slate-50 disabled:opacity-60"
                >
                    {busy ? <Loader2 className="animate-spin" size={18} /> : <ShieldCheck size={18} />}
                    Check Freighter Testnet
                </button>

                <button
                    onClick={onConnect}
                    disabled={busy}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                >
                    {busy ? <Loader2 className="animate-spin" size={18} /> : <WalletCards size={18} />}
                    Connect Freighter
                </button>

                <button
                    onClick={onDisconnect}
                    disabled={!wallet || busy}
                    className="inline-flex w-full items-center justify-center gap-2 rounded-xl border border-red-100 px-4 py-3 font-semibold text-red-600 hover:bg-red-50 disabled:opacity-50"
                >
                    {busy ? <Loader2 className="animate-spin" size={18} /> : <Unplug size={18} />}
                    Disconnect Local Session
                </button>
            </aside>
        </div>
    );
}

function WalletSection({
    title,
    description,
    wallets,
    emptyTitle,
    emptyDescription,
    icon,
    action,
    deletingId,
    onDelete,
}: {
    title: string;
    description: string;
    wallets: Wallet[];
    emptyTitle: string;
    emptyDescription: string;
    icon: ReactNode;
    action: ReactNode;
    deletingId: number | null;
    onDelete: (wallet: Wallet) => void;
}) {
    return (
        <section className="space-y-4">
            <div className="flex flex-col justify-between gap-3 md:flex-row md:items-center">
                <div>
                    <h2 className="text-xl font-bold text-slate-950">{title}</h2>
                    <p className="text-sm text-slate-500">{description}</p>
                </div>
                {action}
            </div>

            {wallets.length === 0 ? (
                <EmptyState icon={icon} title={emptyTitle} description={emptyDescription} />
            ) : (
                <div className="grid gap-4 md:grid-cols-2">
                    {wallets.map((wallet) => (
                        <div
                            key={wallet.id}
                            className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                        >
                            <div className="flex items-start justify-between gap-3">
                                <div className="flex items-center gap-3">
                                    <div className="rounded-2xl bg-blue-100 p-3 text-blue-700">
                                        {icon}
                                    </div>
                                    <div>
                                        <h3 className="font-bold text-slate-950">{wallet.name}</h3>
                                        <p className="text-sm text-slate-500">
                                            {walletTypeLabel(wallet.type)} - {wallet.currency}
                                        </p>
                                    </div>
                                </div>

                                <button
                                    onClick={() => onDelete(wallet)}
                                    disabled={deletingId === wallet.id}
                                    className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                    aria-label={`Delete ${wallet.name}`}
                                >
                                    {deletingId === wallet.id ? (
                                        <Loader2 className="animate-spin" size={18} />
                                    ) : (
                                        <Trash2 size={18} />
                                    )}
                                </button>
                            </div>

                            <div className="mt-5">
                                <p className="text-sm text-slate-500">Current Balance</p>
                                <p className="mt-1 text-2xl font-bold text-slate-950">
                                    {formatBalance(wallet.current_balance)}
                                </p>
                            </div>
                            <div className="mt-4 flex items-center justify-between text-sm">
                                <span className="text-slate-500">Opening Balance</span>
                                <span className="font-semibold text-slate-700">
                                    {formatBalance(wallet.opening_balance)}
                                </span>
                            </div>
                            {(wallet.provider_code || wallet.account_number_masked) && (
                                <div className="mt-4 space-y-2 rounded-2xl bg-white p-4 text-sm">
                                    {wallet.provider_code && (
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-slate-500">Provider</span>
                                            <span className="font-semibold text-slate-700">
                                                {wallet.provider_code}
                                            </span>
                                        </div>
                                    )}
                                    {wallet.account_number_masked && (
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-slate-500">Account</span>
                                            <span className="font-semibold text-slate-700">
                                                {wallet.account_number_masked}
                                            </span>
                                        </div>
                                    )}
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}

function OverviewCard({
    title,
    value,
    detail,
    icon,
    onClick,
}: {
    title: string;
    value: string;
    detail: string;
    icon: ReactNode;
    onClick: () => void;
}) {
    return (
        <button
            onClick={onClick}
            className="rounded-2xl bg-slate-50 p-5 text-left transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
        >
            <div className="mb-4 inline-flex rounded-xl bg-blue-100 p-3 text-blue-700">
                {icon}
            </div>
            <p className="text-sm text-slate-500">{title}</p>
            <p className="mt-1 text-xl font-bold text-slate-950">{value}</p>
            <p className="mt-2 text-xs text-slate-500">{detail}</p>
        </button>
    );
}

function ImportStatusCard({
    title,
    batch,
    sourceType,
}: {
    title: string;
    batch: TransactionImportBatch | null;
    sourceType: "bank_csv" | "ewallet_csv";
}) {
    return (
        <div className="rounded-2xl bg-slate-50 p-5">
            <div className="flex items-center justify-between gap-3">
                <div>
                    <p className="font-bold text-slate-950">{title}</p>
                    <p className="mt-1 text-sm text-slate-500">{sourceType}</p>
                </div>
                <div className="rounded-xl bg-white p-2 text-blue-600 shadow-sm">
                    <Upload size={18} />
                </div>
            </div>

            <p className="mt-4 text-sm font-semibold text-slate-800">
                {importStatusLabel(batch)}
            </p>
            <p className="mt-1 text-xs text-slate-500">
                Last import: {formatDateTime(batch?.processed_at ?? batch?.created_at)}
            </p>

            <Link
                to="/transaction-imports"
                className="mt-4 inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
            >
                <Upload size={16} />
                Import CSV
            </Link>
        </div>
    );
}

function FlowCard({
    title,
    description,
    action,
    to,
    onClick,
}: {
    title: string;
    description: string;
    action: string;
    to?: string;
    onClick?: () => void;
}) {
    const content = (
        <>
            <h3 className="font-bold text-slate-950">{title}</h3>
            <p className="mt-1 text-sm text-slate-500">{description}</p>
            <span className="mt-4 inline-flex text-sm font-semibold text-blue-600">
                {action}
            </span>
        </>
    );

    if (to) {
        return (
            <Link to={to} className="rounded-2xl bg-slate-50 p-5 transition hover:bg-white hover:shadow-md">
                {content}
            </Link>
        );
    }

    return (
        <button onClick={onClick} className="rounded-2xl bg-slate-50 p-5 text-left transition hover:bg-white hover:shadow-md">
            {content}
        </button>
    );
}

function CsvImportButton({ label }: { label: string }) {
    return (
        <Link
            to="/transaction-imports"
            className="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700"
        >
            <Upload size={16} />
            {label}
        </Link>
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

function LoadingState({ text }: { text: string }) {
    return (
        <div className="flex min-h-96 items-center justify-center">
            <div className="flex items-center gap-3 text-slate-500">
                <Loader2 className="animate-spin" size={20} />
                {text}
            </div>
        </div>
    );
}

function Alert({ tone, message }: { tone: "error" | "success" | "info"; message: string }) {
    const className =
        tone === "error"
            ? "border-red-100 bg-red-50 text-red-700"
            : tone === "success"
                ? "border-emerald-100 bg-emerald-50 text-emerald-700"
                : "border-blue-100 bg-blue-50 text-blue-700";

    return (
        <div className={`rounded-2xl border px-5 py-4 text-sm ${className}`}>
            {message}
        </div>
    );
}
