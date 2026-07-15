import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import {
    AlertTriangle,
    CheckCircle2,
    Loader2,
    PiggyBank,
    Plus,
    Trash2,
    TrendingUp,
    XCircle,
} from "lucide-react";
import {
    createOrUpdateBudget,
    deleteBudget,
    getBudgetProgress,
    getBudgets,
} from "../features/budgets/budgetApi";
import { getCategories } from "../features/categories/categoryApi";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import type { Category } from "../types/category";
import type { Budget, BudgetProgressItem } from "../types/budget";

function formatCurrency(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function getStatusLabel(status: BudgetProgressItem["status"]) {
    if (status === "over_budget") return "Over Budget";
    if (status === "warning") return "Warning";
    return "Safe";
}

function getStatusDescription(status: BudgetProgressItem["status"]) {
    if (status === "over_budget") return "Pengeluaran sudah melewati batas budget.";
    if (status === "warning") return "Pengeluaran mendekati batas budget.";
    return "Masih aman di bawah batas budget.";
}

function getStatusStyle(status: BudgetProgressItem["status"]) {
    if (status === "over_budget") {
        return {
            text: "text-red-700",
            bg: "bg-red-50",
            bar: "bg-red-500",
            icon: <XCircle size={18} />,
        };
    }

    if (status === "warning") {
        return {
            text: "text-amber-700",
            bg: "bg-amber-50",
            bar: "bg-amber-500",
            icon: <AlertTriangle size={18} />,
        };
    }

    return {
        text: "text-emerald-700",
        bg: "bg-emerald-50",
        bar: "bg-emerald-500",
        icon: <CheckCircle2 size={18} />,
    };
}

export function BudgetsPage() {
    const [budgets, setBudgets] = useState<Budget[]>([]);
    const [progressItems, setProgressItems] = useState<BudgetProgressItem[]>([]);
    const [expenseCategories, setExpenseCategories] = useState<Category[]>([]);

    const [month, setMonth] = useState("2026-05");
    const [categoryId, setCategoryId] = useState("");
    const [amount, setAmount] = useState("");

    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [error, setError] = useState("");

    const totalBudget = useMemo(() => {
        return progressItems.reduce((total, item) => total + item.budget_amount, 0);
    }, [progressItems]);

    const totalSpent = useMemo(() => {
        return progressItems.reduce((total, item) => total + item.spent_amount, 0);
    }, [progressItems]);

    const totalRemaining = totalBudget - totalSpent;

    const totalPercentage =
        totalBudget > 0 ? Math.round((totalSpent / totalBudget) * 100) : 0;

    async function fetchBudgets() {
        try {
            setLoading(true);
            setError("");

            const [budgetData, progressData, categoriesData] = await Promise.all([
                getBudgets(month),
                getBudgetProgress(month),
                getCategories("expense"),
            ]);

            setBudgets(budgetData);
            setProgressItems(progressData.items);
            setExpenseCategories(categoriesData);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data budget"));
        } finally {
            setLoading(false);
        }
    }

    async function refreshBudgetOnly() {
        try {
            setError("");

            const [budgetData, progressData] = await Promise.all([
                getBudgets(month),
                getBudgetProgress(month),
            ]);

            setBudgets(budgetData);
            setProgressItems(progressData.items);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal refresh data budget"));
        }
    }

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();

        if (!categoryId) {
            setError("Kategori expense wajib dipilih");
            return;
        }

        if (!amount || Number(amount) < 0) {
            setError("Nominal budget wajib diisi");
            return;
        }

        try {
            setSubmitting(true);
            setError("");

            await createOrUpdateBudget({
                category_id: Number(categoryId),
                month,
                amount: Number(amount),
            });

            setCategoryId("");
            setAmount("");

            await refreshBudgetOnly();
        } catch (err: unknown) {
            setError(
                getFirstValidationError(err) ??
                getApiErrorMessage(err, "Gagal menyimpan budget"),
            );
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDeleteBudget(budgetId: number) {
        const confirmed = window.confirm("Yakin mau hapus budget ini?");

        if (!confirmed) return;

        try {
            setDeletingId(budgetId);
            setError("");

            await deleteBudget(budgetId);
            await refreshBudgetOnly();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menghapus budget"));
        } finally {
            setDeletingId(null);
        }
    }

    function handleFillForm(item: BudgetProgressItem) {
        setCategoryId(String(item.category_id));
        setAmount(String(item.budget_amount));
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchBudgets();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [month]);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Budgets
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Atur batas pengeluaran bulanan berdasarkan kategori expense.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">Budget Month</p>
                    <input
                        type="month"
                        value={month}
                        onChange={(event) => setMonth(event.target.value)}
                        className="mt-1 rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold outline-none focus:border-blue-500"
                    />
                </div>
            </div>

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    label="Budget Rules"
                    value={String(budgets.length)}
                    icon={<PiggyBank size={22} />}
                    iconClass="bg-slate-100 text-slate-700"
                />

                <SummaryCard
                    label="Total Budget"
                    value={formatCurrency(totalBudget)}
                    icon={<PiggyBank size={22} />}
                    iconClass="bg-blue-100 text-blue-700"
                />

                <SummaryCard
                    label="Total Spent"
                    value={formatCurrency(totalSpent)}
                    icon={<TrendingUp size={22} />}
                    iconClass="bg-red-100 text-red-700"
                />

                <SummaryCard
                    label="Remaining"
                    value={formatCurrency(totalRemaining)}
                    icon={<CheckCircle2 size={22} />}
                    iconClass={
                        totalRemaining < 0
                            ? "bg-red-100 text-red-700"
                            : "bg-emerald-100 text-emerald-700"
                    }
                />
            </div>

            <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                <div className="mb-3 flex items-center justify-between">
                    <div>
                        <h2 className="text-xl font-bold text-slate-950">
                            Monthly Budget Progress
                        </h2>
                        <p className="text-sm text-slate-500">
                            Total progress semua budget pada bulan {month}.
                        </p>
                    </div>

                    <p
                        className={`rounded-full px-3 py-1 text-sm font-semibold ${totalPercentage >= 100
                                ? "bg-red-50 text-red-700"
                                : totalPercentage >= 80
                                    ? "bg-amber-50 text-amber-700"
                                    : "bg-emerald-50 text-emerald-700"
                            }`}
                    >
                        {totalPercentage}%
                    </p>
                </div>

                <div className="h-4 overflow-hidden rounded-full bg-slate-100">
                    <div
                        className={`h-full rounded-full ${totalPercentage >= 100
                                ? "bg-red-500"
                                : totalPercentage >= 80
                                    ? "bg-amber-500"
                                    : "bg-blue-600"
                            }`}
                        style={{ width: `${Math.min(totalPercentage, 100)}%` }}
                    />
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex items-center justify-between">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Budget List
                            </h2>
                            <p className="text-sm text-slate-500">
                                Progress budget berdasarkan transaksi expense aktual.
                            </p>
                        </div>

                        <button
                            onClick={fetchBudgets}
                            className="rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                        >
                            Refresh
                        </button>
                    </div>

                    {loading ? (
                        <div className="flex min-h-[320px] items-center justify-center">
                            <div className="flex items-center gap-3 text-slate-500">
                                <Loader2 className="animate-spin" size={20} />
                                Loading budgets...
                            </div>
                        </div>
                    ) : progressItems.length === 0 ? (
                        <div className="flex min-h-[320px] flex-col items-center justify-center rounded-2xl bg-slate-50 text-center">
                            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                                <PiggyBank size={28} />
                            </div>
                            <h3 className="font-semibold text-slate-900">
                                Belum ada budget
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Tambahkan budget pertama dari form di kanan.
                            </p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            {progressItems.map((item) => {
                                const statusStyle = getStatusStyle(item.status);

                                return (
                                    <div
                                        key={item.budget_id}
                                        className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                    >
                                        <div className="flex flex-col justify-between gap-4 md:flex-row md:items-start">
                                            <div>
                                                <div className="flex items-center gap-3">
                                                    <div
                                                        className="h-3 w-3 rounded-full"
                                                        style={{
                                                            backgroundColor:
                                                                item.category_color || "#2563EB",
                                                        }}
                                                    />
                                                    <h3 className="font-bold text-slate-950">
                                                        {item.category_name}
                                                    </h3>
                                                </div>

                                                <p className="mt-1 text-sm text-slate-500">
                                                    {formatCurrency(item.spent_amount)} spent from{" "}
                                                    {formatCurrency(item.budget_amount)}
                                                </p>
                                            </div>

                                            <div className="flex items-center gap-2">
                                                <button
                                                    onClick={() => handleFillForm(item)}
                                                    className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50"
                                                >
                                                    Edit
                                                </button>

                                                <button
                                                    onClick={() => handleDeleteBudget(item.budget_id)}
                                                    disabled={deletingId === item.budget_id}
                                                    className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                                >
                                                    {deletingId === item.budget_id ? (
                                                        <Loader2 className="animate-spin" size={18} />
                                                    ) : (
                                                        <Trash2 size={18} />
                                                    )}
                                                </button>
                                            </div>
                                        </div>

                                        <div className="mt-5">
                                            <div className="mb-2 flex items-center justify-between text-sm">
                                                <span className="font-medium text-slate-600">
                                                    Remaining: {formatCurrency(item.remaining_amount)}
                                                </span>

                                                <span
                                                    className={`flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${statusStyle.bg} ${statusStyle.text}`}
                                                >
                                                    {statusStyle.icon}
                                                    {getStatusLabel(item.status)}
                                                </span>
                                            </div>

                                            <div className="h-3 overflow-hidden rounded-full bg-white">
                                                <div
                                                    className={`h-full rounded-full ${statusStyle.bar}`}
                                                    style={{
                                                        width: `${Math.min(item.percentage, 100)}%`,
                                                    }}
                                                />
                                            </div>

                                            <p className="mt-2 text-right text-sm font-semibold text-slate-700">
                                                {item.percentage}%
                                            </p>

                                            <p className={`mt-2 text-sm ${statusStyle.text}`}>
                                                {getStatusDescription(item.status)}
                                            </p>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>

                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5">
                        <h2 className="text-xl font-bold text-slate-950">Set Budget</h2>
                        <p className="text-sm text-slate-500">
                            Jika kategori dan bulan sama, budget akan otomatis di-update.
                        </p>
                    </div>

                    <form onSubmit={handleSubmit} className="space-y-4">
                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Month
                            </label>
                            <input
                                type="month"
                                value={month}
                                onChange={(event) => setMonth(event.target.value)}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Expense Category
                            </label>
                            <select
                                value={categoryId}
                                onChange={(event) => setCategoryId(event.target.value)}
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            >
                                <option value="">Pilih kategori expense</option>
                                {expenseCategories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Budget Amount
                            </label>
                            <input
                                type="number"
                                min="0"
                                value={amount}
                                onChange={(event) => setAmount(event.target.value)}
                                placeholder="Contoh: 1000000"
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
                                    Save Budget
                                </>
                            )}
                        </button>
                    </form>

                    <div className="mt-6 rounded-2xl bg-blue-50 p-4 text-sm text-blue-700">
                        <p className="font-semibold">Budget Rule</p>
                        <p className="mt-1">
                            Budget hanya bisa dibuat untuk kategori expense seperti Makanan,
                            Transport, Tagihan, atau Hiburan.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}

type SummaryCardProps = {
    label: string;
    value: string;
    icon: React.ReactNode;
    iconClass: string;
};

function SummaryCard({ label, value, icon, iconClass }: SummaryCardProps) {
    return (
        <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <div className="flex items-start justify-between">
                <p className="text-sm font-medium text-slate-500">{label}</p>
                <div className={`rounded-2xl p-3 ${iconClass}`}>{icon}</div>
            </div>

            <h3 className="mt-5 text-2xl font-bold tracking-tight text-slate-950">
                {value}
            </h3>
        </div>
    );
}
