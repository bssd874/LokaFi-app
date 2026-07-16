import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    CheckCircle2,
    CreditCard,
    Loader2,
    Plus,
    RefreshCw,
    Search,
    Send,
    Sparkles,
    Tags,
    Trash2,
} from "lucide-react";
import {
    acceptAiTransactionCategory,
    acceptTransactionCategorySuggestion,
    aiCategorizePending,
    askAiTransactionCategory,
    bulkCategorizeTransactions,
    createTransaction,
    deleteTransaction,
    getReviewRequiredTransactions,
    getTransactions,
    reprocessTransactionCategorization,
    suggestTransactionCategory,
    updateTransactionCategory,
} from "../features/transactions/transactionApi";
import { getWallets } from "../features/wallets/walletApi";
import { getCategories } from "../features/categories/categoryApi";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import type { Wallet } from "../types/wallet";
import type { Category } from "../types/category";
import type {
    CategorizationStatus,
    Transaction,
    TransactionSource,
    TransactionType,
} from "../types/transaction";

function formatCurrency(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(new Date(date));
}

function toDateTimeLocalValue(date = new Date()) {
    const offset = date.getTimezoneOffset();
    const localDate = new Date(date.getTime() - offset * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
}

function getSourceBadge(source?: TransactionSource | null) {
    const normalizedSource = source ?? "manual";

    const styles: Record<TransactionSource, string> = {
        manual: "bg-slate-100 text-slate-700",
        brankas: "bg-indigo-50 text-indigo-700",
        bank_csv: "bg-sky-50 text-sky-700",
        ewallet_csv: "bg-emerald-50 text-emerald-700",
        stellar: "bg-violet-50 text-violet-700",
        open_banking_simulator: "bg-blue-50 text-blue-700",
        open_banking_provider: "bg-indigo-50 text-indigo-700",
        portfolio_simulator: "bg-emerald-50 text-emerald-700",
    };

    return {
        label: normalizedSource,
        className: styles[normalizedSource] ?? "bg-slate-100 text-slate-700",
    };
}

function getStatusBadge(transaction: Transaction) {
    if (transaction.category_label?.is_verified) {
        return {
            label: "Terverifikasi",
            className: "bg-emerald-50 text-emerald-700",
            icon: <CheckCircle2 size={13} />,
        };
    }

    if (transaction.categorization_status === "review_required") {
        return {
            label: "Perlu Review",
            className: "bg-amber-50 text-amber-700",
            icon: <AlertTriangle size={13} />,
        };
    }

    if (transaction.categorization_status === "categorized" || transaction.category_id) {
        return {
            label: "Sudah Dikategorikan",
            className: "bg-blue-50 text-blue-700",
            icon: null,
        };
    }

    return {
        label: "Belum Dikategorikan",
        className: "bg-amber-50 text-amber-700",
        icon: null,
    };
}

function getConfidenceBadge(transaction: Transaction) {
    const confidence = transaction.categorization_confidence ?? "none";

    const styles = {
        high: "bg-emerald-50 text-emerald-700",
        medium: "bg-blue-50 text-blue-700",
        low: "bg-amber-50 text-amber-700",
        none: "bg-slate-100 text-slate-600",
    };

    return {
        label: confidence,
        className: styles[confidence],
    };
}

function getCategorySourceLabel(transaction: Transaction) {
    const source = transaction.category_source ?? "unclassified";

    const labels: Record<string, string> = {
        user: "User correction",
        imported: "Imported",
        system: "System",
        unclassified: "Unclassified",
        verified_mapping: "Verified mapping",
        user_rule: "User rule",
        default_rule: "Default rule",
        historical_mapping: "Historical match",
        review_required: "Needs review",
        ai_suggestion: "AI suggestion",
        ai_error: "AI unavailable",
    };

    return labels[source] ?? source;
}

export function TransactionsPage() {
    const [transactions, setTransactions] = useState<Transaction[]>([]);
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [categories, setCategories] = useState<Category[]>([]);

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [categorizingId, setCategorizingId] = useState<number | null>(null);
    const [suggestingId, setSuggestingId] = useState<number | null>(null);
    const [acceptingId, setAcceptingId] = useState<number | null>(null);
    const [askingAiId, setAskingAiId] = useState<number | null>(null);
    const [bulkSubmitting, setBulkSubmitting] = useState(false);
    const [reprocessing, setReprocessing] = useState(false);
    const [aiBatching, setAiBatching] = useState(false);
    const [loadingReviewQueue, setLoadingReviewQueue] = useState(false);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const [search, setSearch] = useState("");
    const [filterType, setFilterType] = useState<TransactionType | "">("");
    const [filterStatus, setFilterStatus] = useState<CategorizationStatus | "">("");
    const [filterWalletId, setFilterWalletId] = useState("");
    const [filterFrom, setFilterFrom] = useState("");
    const [filterTo, setFilterTo] = useState("");

    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkCategoryId, setBulkCategoryId] = useState("");

    const [type, setType] = useState<TransactionType>("expense");
    const [walletId, setWalletId] = useState("");
    const [fromWalletId, setFromWalletId] = useState("");
    const [toWalletId, setToWalletId] = useState("");
    const [categoryId, setCategoryId] = useState("");
    const [amount, setAmount] = useState("");
    const [fee, setFee] = useState("0");
    const [merchant, setMerchant] = useState("");
    const [note, setNote] = useState("");
    const [happenedAt, setHappenedAt] = useState(toDateTimeLocalValue());

    const visibleCategories = useMemo(() => {
        if (type === "transfer") return [];
        return categories.filter((category) => category.type === type);
    }, [categories, type]);

    const selectedTransactions = useMemo(() => {
        return transactions.filter((transaction) => selectedIds.includes(transaction.id));
    }, [selectedIds, transactions]);

    const allVisibleSelected = transactions.length > 0
        && transactions.every((transaction) => selectedIds.includes(transaction.id));

    function transactionCategories(transaction: Transaction) {
        if (transaction.type === "transfer") return [];
        return categories.filter((category) => category.type === transaction.type);
    }

    async function fetchInitialData() {
        try {
            setLoading(true);
            setError("");

            const [transactionPage, walletData, categoryData] = await Promise.all([
                getTransactions(buildFilters()),
                getWallets(),
                getCategories(),
            ]);

            setTransactions(transactionPage.data);
            setWallets(walletData);
            setCategories(categoryData);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data transaksi"));
        } finally {
            setLoading(false);
        }
    }

    function buildFilters() {
        return {
            search: search || undefined,
            type: filterType || undefined,
            wallet_id: filterWalletId ? Number(filterWalletId) : undefined,
            categorization_status: filterStatus || undefined,
            from: filterFrom || undefined,
            to: filterTo || undefined,
        };
    }

    async function fetchTransactionsOnly() {
        try {
            setError("");

            const transactionPage = await getTransactions(buildFilters());

            setTransactions(transactionPage.data);
            setSelectedIds([]);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data transaksi"));
        }
    }

    function resetForm() {
        setType("expense");
        setWalletId("");
        setFromWalletId("");
        setToWalletId("");
        setCategoryId("");
        setAmount("");
        setFee("0");
        setMerchant("");
        setNote("");
        setHappenedAt(toDateTimeLocalValue());
    }

    function handleChangeType(newType: TransactionType) {
        setType(newType);
        setWalletId("");
        setFromWalletId("");
        setToWalletId("");
        setCategoryId("");
        setMerchant("");
    }

    function toggleSelected(transactionId: number) {
        setSelectedIds((current) => (
            current.includes(transactionId)
                ? current.filter((id) => id !== transactionId)
                : [...current, transactionId]
        ));
    }

    function toggleAllVisible() {
        if (allVisibleSelected) {
            setSelectedIds([]);
            return;
        }

        setSelectedIds(transactions.map((transaction) => transaction.id));
    }

    async function handleCreateTransaction(event: FormEvent) {
        event.preventDefault();

        if (!amount || Number(amount) <= 0) {
            setError("Amount wajib lebih dari 0");
            return;
        }

        if (type !== "transfer" && (!walletId || !categoryId)) {
            setError("Wallet dan category wajib diisi untuk income/expense");
            return;
        }

        if (type === "transfer" && (!fromWalletId || !toWalletId)) {
            setError("From wallet dan to wallet wajib diisi untuk transfer");
            return;
        }

        if (type === "transfer" && fromWalletId === toWalletId) {
            setError("Wallet asal dan tujuan tidak boleh sama");
            return;
        }

        try {
            setSubmitting(true);
            setError("");
            setSuccessMessage("");

            if (type === "transfer") {
                await createTransaction({
                    type,
                    from_wallet_id: Number(fromWalletId),
                    to_wallet_id: Number(toWalletId),
                    amount: Number(amount),
                    fee: Number(fee || 0),
                    currency: "IDR",
                    note,
                    description: note,
                    happened_at: `${happenedAt.replace("T", " ")}:00`,
                });
            } else {
                await createTransaction({
                    type,
                    wallet_id: Number(walletId),
                    category_id: Number(categoryId),
                    amount: Number(amount),
                    fee: Number(fee || 0),
                    currency: "IDR",
                    merchant,
                    note,
                    description: note || merchant,
                    happened_at: `${happenedAt.replace("T", " ")}:00`,
                });
            }

            setSuccessMessage("Transaksi berhasil disimpan dan label kategori diperbarui.");
            resetForm();
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(
                getFirstValidationError(err)
                ?? getApiErrorMessage(err, "Gagal membuat transaksi"),
            );
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDeleteTransaction(transaction: Transaction) {
        const confirmed = window.confirm(
            `Yakin mau hapus transaksi "${transaction.merchant || transaction.description || transaction.note || transaction.type}"?`,
        );

        if (!confirmed) return;

        try {
            setDeletingId(transaction.id);
            setError("");
            setSuccessMessage("");

            await deleteTransaction(transaction.id);
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menghapus transaksi"));
        } finally {
            setDeletingId(null);
        }
    }

    async function handleCategoryChange(transaction: Transaction, nextCategoryId: string) {
        if (!nextCategoryId) return;

        try {
            setCategorizingId(transaction.id);
            setError("");
            setSuccessMessage("");

            await updateTransactionCategory(transaction.id, Number(nextCategoryId));
            setSuccessMessage("Kategori transaksi berhasil diperbarui.");
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal memperbarui kategori transaksi"));
        } finally {
            setCategorizingId(null);
        }
    }

    async function handleSuggestCategory(transaction: Transaction) {
        try {
            setSuggestingId(transaction.id);
            setError("");
            setSuccessMessage("");

            const suggestion = await suggestTransactionCategory(transaction.id);
            setSuccessMessage(
                suggestion.category
                    ? `Suggestion dibuat: ${suggestion.category.name} (${suggestion.confidence}).`
                    : "Transaksi ditandai perlu review karena belum ada rule atau mapping yang cocok.",
            );
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membuat suggestion kategori"));
        } finally {
            setSuggestingId(null);
        }
    }

    async function handleAcceptSuggestion(transaction: Transaction) {
        try {
            setAcceptingId(transaction.id);
            setError("");
            setSuccessMessage("");

            const updated = transaction.category_source === "ai_suggestion"
                ? await acceptAiTransactionCategory(transaction.id)
                : await acceptTransactionCategorySuggestion(transaction.id);
            setSuccessMessage(
                `${transaction.category_source === "ai_suggestion" ? "AI suggestion" : "Suggestion"} diterima dan mapping reusable dibuat untuk ${updated.category?.name ?? "kategori"}.`,
            );
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menerima suggestion kategori"));
        } finally {
            setAcceptingId(null);
        }
    }

    async function handleAskAi(transaction: Transaction) {
        try {
            setAskingAiId(transaction.id);
            setError("");
            setSuccessMessage("");

            const result = await askAiTransactionCategory(transaction.id);

            if (
                result.validation_status === "provider_error" ||
                result.validation_status === "invalid_response" ||
                result.source === "ai_error"
            ) {
                setError(result.user_message);
            } else {
                setSuccessMessage(result.user_message);
            }

            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal meminta AI suggestion"));
        } finally {
            setAskingAiId(null);
        }
    }

    async function handleAiCategorizeSelected() {
        try {
            setAiBatching(true);
            setError("");
            setSuccessMessage("");

            const result = await aiCategorizePending(25);
            setSuccessMessage(
                `AI batch selesai: ${result.processed_count} diproses, ${result.suggested_count} suggestion, ${result.skipped_count} dilewati, ${result.failed_count} gagal.`,
            );
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menjalankan AI batch categorization"));
        } finally {
            setAiBatching(false);
        }
    }

    async function handleLoadReviewQueue() {
        try {
            setLoadingReviewQueue(true);
            setError("");
            setFilterStatus("review_required");

            const transactionPage = await getReviewRequiredTransactions();
            setTransactions(transactionPage.data);
            setSelectedIds([]);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil review queue"));
        } finally {
            setLoadingReviewQueue(false);
        }
    }

    async function handleReprocessSelected() {
        if (selectedIds.length === 0) {
            setError("Pilih transaksi yang mau diproses ulang.");
            return;
        }

        try {
            setReprocessing(true);
            setError("");
            setSuccessMessage("");

            const result = await reprocessTransactionCategorization(selectedIds);
            setSuccessMessage(
                `${result.updated_count} categorized, ${result.review_required_count} perlu review, ${result.skipped_count} dilewati.`,
            );
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal reprocess kategorisasi"));
        } finally {
            setReprocessing(false);
        }
    }

    async function handleBulkCategory() {
        if (selectedIds.length === 0) {
            setError("Pilih transaksi yang mau dikategorikan.");
            return;
        }

        if (!bulkCategoryId) {
            setError("Pilih kategori bulk terlebih dahulu.");
            return;
        }

        try {
            setBulkSubmitting(true);
            setError("");
            setSuccessMessage("");

            const result = await bulkCategorizeTransactions(selectedIds, Number(bulkCategoryId));
            setSuccessMessage(
                `${result.updated_count} transaksi diperbarui. ${result.skipped_count} transaksi dilewati.`,
            );
            setBulkCategoryId("");
            await fetchTransactionsOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal bulk categorization"));
        } finally {
            setBulkSubmitting(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchInitialData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const timeout = setTimeout(() => {
            fetchTransactionsOnly();
        }, 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, filterType, filterStatus, filterWalletId, filterFrom, filterTo]);

    return (
        <div className="min-w-0 space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Transactions
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Kelola transaksi, label kategori manual, review rules/history, dan AI suggestion fallback.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">Visible Transactions</p>
                    <p className="mt-1 text-2xl font-bold text-slate-950">
                        {transactions.length}
                    </p>
                </div>
            </div>

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

            <section className="rounded-3xl border border-blue-100 bg-blue-50 p-5 text-sm text-blue-800">
                <div className="flex items-start gap-3">
                    <Tags className="mt-0.5 shrink-0" size={18} />
                    <div>
                        <p className="font-semibold">Hybrid categorization workflow</p>
                        <p className="mt-1">
                            AI hanya dipakai setelah verified mapping, user rule, default rule,
                            dan historical mapping tidak memberi hasil acceptable. Hasil AI tetap
                            suggestion yang harus diterima atau dikoreksi user.
                        </p>
                    </div>
                </div>
            </section>

            <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.65fr)_minmax(300px,1fr)]">
                <div className="min-w-0 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-4">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Transaction List
                            </h2>
                            <p className="text-sm text-slate-500">
                                Data sensitif mentah tidak ditampilkan. Dataset memakai sanitized description.
                            </p>
                        </div>

                        <div className="grid min-w-0 gap-3 md:grid-cols-2">
                            <div className="relative">
                                <Search
                                    size={16}
                                    className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                                />
                                <input
                                    value={search}
                                    onChange={(event) => setSearch(event.target.value)}
                                    placeholder="Search merchant/description..."
                                    className="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm outline-none focus:border-blue-500"
                                />
                            </div>

                            <select
                                value={filterStatus}
                                onChange={(event) =>
                                    setFilterStatus(event.target.value as CategorizationStatus | "")
                                }
                                className="min-w-0 rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                            >
                                <option value="">Semua</option>
                                <option value="unclassified">Belum Dikategorikan</option>
                                <option value="review_required">Perlu Review</option>
                                <option value="categorized">Sudah Dikategorikan</option>
                            </select>

                            <select
                                value={filterType}
                                onChange={(event) =>
                                    setFilterType(event.target.value as TransactionType | "")
                                }
                                className="min-w-0 rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                            >
                                <option value="">All Type</option>
                                <option value="income">Income</option>
                                <option value="expense">Expense</option>
                                <option value="transfer">Transfer</option>
                            </select>

                            <select
                                value={filterWalletId}
                                onChange={(event) => setFilterWalletId(event.target.value)}
                                className="min-w-0 rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                            >
                                <option value="">All Wallet</option>
                                {wallets.map((wallet) => (
                                    <option key={wallet.id} value={wallet.id}>
                                        {wallet.name}
                                    </option>
                                ))}
                            </select>

                            <div className="grid min-w-0 grid-cols-2 gap-2">
                                <input
                                    type="date"
                                    value={filterFrom}
                                    onChange={(event) => setFilterFrom(event.target.value)}
                                    className="min-w-0 rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                                />
                                <input
                                    type="date"
                                    value={filterTo}
                                    onChange={(event) => setFilterTo(event.target.value)}
                                    className="min-w-0 rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                                />
                            </div>
                        </div>
                    </div>

                    <div className="mb-4 rounded-2xl border border-slate-100 bg-slate-50 p-4">
                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div>
                                <p className="text-sm font-semibold text-slate-900">
                                    Bulk Categorization
                                </p>
                                <p className="text-xs text-slate-500">
                                    {selectedTransactions.length} transaksi dipilih
                                </p>
                            </div>

                            <div className="flex min-w-0 flex-col gap-2 sm:flex-row sm:flex-wrap">
                                <button
                                    type="button"
                                    onClick={handleLoadReviewQueue}
                                    disabled={loadingReviewQueue}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-100 bg-white px-4 py-2 text-sm font-semibold text-amber-700 hover:bg-amber-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {loadingReviewQueue ? (
                                        <Loader2 className="animate-spin" size={16} />
                                    ) : (
                                        <AlertTriangle size={16} />
                                    )}
                                    Review Queue
                                </button>

                                <button
                                    type="button"
                                    onClick={handleReprocessSelected}
                                    disabled={reprocessing || selectedIds.length === 0}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {reprocessing ? (
                                        <Loader2 className="animate-spin" size={16} />
                                    ) : (
                                        <RefreshCw size={16} />
                                    )}
                                    Reprocess Selected
                                </button>

                                <button
                                    type="button"
                                    onClick={handleAiCategorizeSelected}
                                    disabled={aiBatching}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-violet-100 bg-white px-4 py-2 text-sm font-semibold text-violet-700 hover:bg-violet-50 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {aiBatching ? (
                                        <Loader2 className="animate-spin" size={16} />
                                    ) : (
                                        <Sparkles size={16} />
                                    )}
                                    AI Pending
                                </button>

                                <select
                                    value={bulkCategoryId}
                                    onChange={(event) => setBulkCategoryId(event.target.value)}
                                    className="min-w-[180px] flex-1 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500"
                                >
                                    <option value="">Pilih kategori</option>
                                    {categories.map((category) => (
                                        <option key={category.id} value={category.id}>
                                            {category.name} ({category.type})
                                        </option>
                                    ))}
                                </select>

                                <button
                                    type="button"
                                    onClick={handleBulkCategory}
                                    disabled={bulkSubmitting || selectedIds.length === 0}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                                >
                                    {bulkSubmitting ? (
                                        <Loader2 className="animate-spin" size={16} />
                                    ) : (
                                        <Tags size={16} />
                                    )}
                                    Terapkan Kategori
                                </button>
                            </div>
                        </div>
                    </div>

                    {loading ? (
                        <div className="flex min-h-[320px] items-center justify-center">
                            <div className="flex items-center gap-3 text-slate-500">
                                <Loader2 className="animate-spin" size={20} />
                                Loading transactions...
                            </div>
                        </div>
                    ) : transactions.length === 0 ? (
                        <div className="flex min-h-[320px] flex-col items-center justify-center rounded-2xl bg-slate-50 p-6 text-center">
                            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                                <CreditCard size={28} />
                            </div>
                            <h3 className="font-semibold text-slate-900">
                                Belum ada transaksi
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Tambahkan transaksi manual atau sinkronkan Brankas.
                            </p>
                        </div>
                    ) : (
                        <div className="max-w-full overflow-x-auto rounded-2xl border border-slate-100">
                            <table className="w-full min-w-[1120px] text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">
                                            <input
                                                type="checkbox"
                                                checked={allVisibleSelected}
                                                onChange={toggleAllVisible}
                                                className="h-4 w-4 rounded border-slate-300"
                                            />
                                        </th>
                                        <th className="px-4 py-3">Transaction</th>
                                        <th className="px-4 py-3">Status</th>
                                        <th className="px-4 py-3">Category</th>
                                        <th className="px-4 py-3">Wallet</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3 text-right">Amount</th>
                                        <th className="px-4 py-3 text-right">Action</th>
                                    </tr>
                                </thead>

                                <tbody className="divide-y divide-slate-100">
                                    {transactions.map((transaction) => {
                                        const sourceBadge = getSourceBadge(transaction.source);
                                        const statusBadge = getStatusBadge(transaction);
                                        const confidenceBadge = getConfidenceBadge(transaction);
                                        const options = transactionCategories(transaction);

                                        return (
                                            <tr key={transaction.id} className="hover:bg-slate-50">
                                                <td className="px-4 py-4 align-top">
                                                    <input
                                                        type="checkbox"
                                                        checked={selectedIds.includes(transaction.id)}
                                                        onChange={() => toggleSelected(transaction.id)}
                                                        className="h-4 w-4 rounded border-slate-300"
                                                    />
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="flex items-start gap-3">
                                                        <div
                                                            className={`flex h-10 w-10 items-center justify-center rounded-xl ${
                                                                transaction.type === "income"
                                                                    ? "bg-emerald-50 text-emerald-700"
                                                                    : transaction.type === "expense"
                                                                        ? "bg-red-50 text-red-700"
                                                                        : "bg-blue-50 text-blue-700"
                                                            }`}
                                                        >
                                                            {transaction.type === "income" ? (
                                                                <ArrowDownCircle size={18} />
                                                            ) : transaction.type === "expense" ? (
                                                                <ArrowUpCircle size={18} />
                                                            ) : (
                                                                <Send size={18} />
                                                            )}
                                                        </div>

                                                        <div>
                                                            <p className="font-semibold text-slate-900">
                                                                {transaction.merchant
                                                                    || transaction.sanitized_description
                                                                    || transaction.description
                                                                    || transaction.note
                                                                    || transaction.type}
                                                            </p>
                                                            <p className="mt-1 max-w-md truncate text-xs text-slate-500">
                                                                {transaction.sanitized_description
                                                                    || transaction.description
                                                                    || transaction.note
                                                                    || "-"}
                                                            </p>
                                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                <span className="text-xs capitalize text-slate-500">
                                                                    {transaction.type}
                                                                </span>
                                                                <span
                                                                    className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${sourceBadge.className}`}
                                                                >
                                                                    {sourceBadge.label}
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    <div className="space-y-2">
                                                        <span
                                                            className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${statusBadge.className}`}
                                                        >
                                                            {statusBadge.icon}
                                                            {statusBadge.label}
                                                        </span>
                                                        <div className="flex flex-wrap gap-1">
                                                            <span
                                                                className={`rounded-full px-2 py-0.5 text-[11px] font-bold ${confidenceBadge.className}`}
                                                            >
                                                                {confidenceBadge.label}
                                                            </span>
                                                            <span className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-bold text-slate-600">
                                                                {getCategorySourceLabel(transaction)}
                                                            </span>
                                                        </div>
                                                        {transaction.categorization_explanation && (
                                                            <p className="max-w-xs text-xs text-slate-500">
                                                                {transaction.categorization_explanation}
                                                            </p>
                                                        )}
                                                    </div>
                                                </td>

                                                <td className="px-4 py-4">
                                                    {transaction.type === "transfer" ? (
                                                        <span className="text-slate-500">Transfer</span>
                                                    ) : (
                                                        <div className="space-y-2">
                                                            {transaction.suggested_category && !transaction.category_id && (
                                                                <div className="rounded-xl bg-amber-50 p-2 text-xs text-amber-800">
                                                                    <p className="font-semibold">
                                                                        {transaction.category_source === "ai_suggestion"
                                                                            ? "AI suggestion"
                                                                            : "Suggestion"}
                                                                        : {transaction.suggested_category.name}
                                                                    </p>
                                                                    {transaction.category_source === "ai_suggestion" && (
                                                                        <p className="mt-1">
                                                                            Review sebelum menerima.
                                                                        </p>
                                                                    )}
                                                                    <button
                                                                        type="button"
                                                                        onClick={() => handleAcceptSuggestion(transaction)}
                                                                        disabled={acceptingId === transaction.id}
                                                                        className="mt-2 inline-flex items-center gap-1 rounded-lg bg-amber-600 px-2 py-1 font-semibold text-white hover:bg-amber-700 disabled:opacity-60"
                                                                    >
                                                                        {acceptingId === transaction.id ? (
                                                                            <Loader2 className="animate-spin" size={13} />
                                                                        ) : (
                                                                            <CheckCircle2 size={13} />
                                                                        )}
                                                                        Accept
                                                                    </button>
                                                                </div>
                                                            )}

                                                            <div className="flex items-center gap-2">
                                                            <select
                                                                value={transaction.category_id ?? ""}
                                                                onChange={(event) =>
                                                                    handleCategoryChange(transaction, event.target.value)
                                                                }
                                                                disabled={categorizingId === transaction.id}
                                                                className="w-48 rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500 disabled:opacity-60"
                                                            >
                                                                <option value="">Pilih kategori</option>
                                                                {options.map((category) => (
                                                                    <option key={category.id} value={category.id}>
                                                                        {category.name}
                                                                    </option>
                                                                ))}
                                                            </select>
                                                            {categorizingId === transaction.id && (
                                                                <Loader2 className="animate-spin text-slate-400" size={16} />
                                                            )}
                                                            </div>
                                                        </div>
                                                    )}
                                                </td>

                                                <td className="px-4 py-4 text-slate-600">
                                                    {transaction.type === "transfer"
                                                        ? `${transaction.from_wallet?.name ?? "-"} -> ${
                                                            transaction.to_wallet?.name ?? "-"
                                                        }`
                                                        : transaction.wallet?.name ?? "-"}
                                                </td>

                                                <td className="px-4 py-4 text-slate-600">
                                                    {formatDate(transaction.happened_at)}
                                                </td>

                                                <td
                                                    className={`px-4 py-4 text-right font-bold ${
                                                        transaction.type === "income"
                                                            ? "text-emerald-600"
                                                            : transaction.type === "expense"
                                                                ? "text-red-600"
                                                                : "text-blue-600"
                                                    }`}
                                                >
                                                    {transaction.type === "income"
                                                        ? "+"
                                                        : transaction.type === "expense"
                                                            ? "-"
                                                            : ""}
                                                    {formatCurrency(transaction.amount)}
                                                </td>

                                                <td className="px-4 py-4 text-right">
                                                    <div className="flex justify-end gap-1">
                                                        {transaction.type !== "transfer" && !transaction.category_id && (
                                                            <>
                                                                <button
                                                                    onClick={() => handleSuggestCategory(transaction)}
                                                                    disabled={suggestingId === transaction.id}
                                                                    className="rounded-xl p-2 text-slate-400 hover:bg-blue-50 hover:text-blue-600 disabled:opacity-50"
                                                                    title="Suggest category from rules/history"
                                                                >
                                                                    {suggestingId === transaction.id ? (
                                                                        <Loader2 className="animate-spin" size={18} />
                                                                    ) : (
                                                                        <Tags size={18} />
                                                                    )}
                                                                </button>

                                                                <button
                                                                    onClick={() => handleAskAi(transaction)}
                                                                    disabled={askingAiId === transaction.id}
                                                                    className="rounded-xl p-2 text-slate-400 hover:bg-violet-50 hover:text-violet-600 disabled:opacity-50"
                                                                    title="Ask AI after rules/history fallback"
                                                                >
                                                                    {askingAiId === transaction.id ? (
                                                                        <Loader2 className="animate-spin" size={18} />
                                                                    ) : (
                                                                        <Sparkles size={18} />
                                                                    )}
                                                                </button>
                                                            </>
                                                        )}

                                                        <button
                                                            onClick={() => handleDeleteTransaction(transaction)}
                                                            disabled={deletingId === transaction.id}
                                                            className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                                            title="Delete transaction"
                                                        >
                                                            {deletingId === transaction.id ? (
                                                                <Loader2 className="animate-spin" size={18} />
                                                            ) : (
                                                                <Trash2 size={18} />
                                                            )}
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>

                <div className="min-w-0 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5">
                        <h2 className="text-xl font-bold text-slate-950">
                            Add Transaction
                        </h2>
                        <p className="text-sm text-slate-500">
                            Transaksi manual dengan kategori akan masuk dataset verified.
                        </p>
                    </div>

                    <div className="mb-5 grid grid-cols-3 gap-2 rounded-2xl bg-slate-100 p-1">
                        {(["expense", "income", "transfer"] as TransactionType[]).map((item) => (
                            <button
                                key={item}
                                type="button"
                                onClick={() => handleChangeType(item)}
                                className={`rounded-xl px-3 py-2 text-sm font-semibold capitalize ${
                                    type === item
                                        ? "bg-white text-blue-600 shadow-sm"
                                        : "text-slate-500"
                                }`}
                            >
                                {item}
                            </button>
                        ))}
                    </div>

                    <form onSubmit={handleCreateTransaction} className="space-y-4">
                        {type === "transfer" ? (
                            <>
                                <FieldSelect
                                    label="From Wallet"
                                    value={fromWalletId}
                                    onChange={setFromWalletId}
                                    placeholder="Pilih wallet asal"
                                    options={wallets.map((wallet) => ({
                                        value: String(wallet.id),
                                        label: `${wallet.name} - ${formatCurrency(wallet.current_balance)}`,
                                    }))}
                                />

                                <FieldSelect
                                    label="To Wallet"
                                    value={toWalletId}
                                    onChange={setToWalletId}
                                    placeholder="Pilih wallet tujuan"
                                    options={wallets.map((wallet) => ({
                                        value: String(wallet.id),
                                        label: `${wallet.name} - ${formatCurrency(wallet.current_balance)}`,
                                    }))}
                                />
                            </>
                        ) : (
                            <>
                                <FieldSelect
                                    label="Wallet"
                                    value={walletId}
                                    onChange={setWalletId}
                                    placeholder="Pilih wallet"
                                    options={wallets.map((wallet) => ({
                                        value: String(wallet.id),
                                        label: `${wallet.name} - ${formatCurrency(wallet.current_balance)}`,
                                    }))}
                                />

                                <FieldSelect
                                    label="Category"
                                    value={categoryId}
                                    onChange={setCategoryId}
                                    placeholder="Pilih category"
                                    options={visibleCategories.map((category) => ({
                                        value: String(category.id),
                                        label: category.name,
                                    }))}
                                />

                                <TextInput
                                    label="Merchant"
                                    value={merchant}
                                    onChange={setMerchant}
                                    placeholder={type === "income" ? "Contoh: Gaji Bulanan" : "Contoh: Warung Makan"}
                                />
                            </>
                        )}

                        <TextInput
                            label="Amount"
                            type="number"
                            value={amount}
                            onChange={setAmount}
                            placeholder="0"
                        />

                        <TextInput
                            label="Fee"
                            type="number"
                            value={fee}
                            onChange={setFee}
                            placeholder="0"
                        />

                        <TextInput
                            label="Date"
                            type="datetime-local"
                            value={happenedAt}
                            onChange={setHappenedAt}
                        />

                        <div>
                            <label className="text-sm font-semibold text-slate-700">Note</label>
                            <textarea
                                value={note}
                                onChange={(event) => setNote(event.target.value)}
                                placeholder="Catatan opsional"
                                rows={3}
                                className="mt-1 w-full resize-none rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
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
                                    Add {type}
                                </>
                            )}
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}

function FieldSelect({
    label,
    value,
    onChange,
    placeholder,
    options,
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    placeholder: string;
    options: Array<{ value: string; label: string }>;
}) {
    return (
        <div>
            <label className="text-sm font-semibold text-slate-700">{label}</label>
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
            >
                <option value="">{placeholder}</option>
                {options.map((option) => (
                    <option key={option.value} value={option.value}>
                        {option.label}
                    </option>
                ))}
            </select>
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
