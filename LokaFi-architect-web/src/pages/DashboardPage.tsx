import { useEffect, useMemo, useRef, useState } from "react";
import { useNavigate } from "react-router-dom";
import {
    ArrowDown,
    ArrowUp,
    Banknote,
    FileText,
    Loader2,
    PieChart as PieChartIcon,
    Plus,
    RefreshCw,
    Send,
    Sparkles,
    Upload,
    Wallet,
} from "lucide-react";
import {
    Area,
    AreaChart,
    CartesianGrid,
    Cell,
    Pie,
    PieChart,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from "recharts";
import { getDashboardSummary } from "../features/dashboard/dashboardApi";
import { getWallets } from "../features/wallets/walletApi";
import { useAuthStore } from "../store/authStore";
import { getApiErrorMessage } from "../utils/apiError";
import type {
    DashboardAnomaly,
    DashboardBudgetAlert,
    DashboardExpenseDistribution,
    DashboardSourceDistribution,
    DashboardSummary,
    DashboardTransaction,
} from "../types/dashboard";
import type { Wallet as WalletType } from "../types/wallet";

const COLORS = ["#2563EB", "#059669", "#F59E0B", "#DC2626", "#7C3AED", "#475569"];
const SOURCE_OPTIONS = [
    { label: "Semua Sumber", value: "" },
    { label: "Manual", value: "manual" },
    { label: "Bank CSV", value: "bank_csv" },
    { label: "E-Wallet CSV", value: "ewallet_csv" },
    { label: "Stellar", value: "stellar" },
];

function localDateInput(date: Date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function last30DaysRange() {
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 29);

    return {
        start: localDateInput(start),
        end: localDateInput(end),
    };
}

function formatCurrency(value: number | string | null | undefined) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function formatPercent(value: number | null | undefined) {
    if (value === null || value === undefined || Number.isNaN(Number(value))) {
        return "Tidak ada perbandingan periode sebelumnya.";
    }

    const prefix = Number(value) > 0 ? "+" : "";
    return `${prefix}${Number(value).toFixed(1)}% vs periode sebelumnya`;
}

function jakartaDate(date: string) {
    if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
        return new Date(`${date}T00:00:00+07:00`);
    }

    return new Date(date);
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        timeZone: "Asia/Jakarta",
    }).format(jakartaDate(date));
}

function formatShortDate(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        timeZone: "Asia/Jakarta",
    }).format(jakartaDate(date));
}

function formatDateTime(date?: string | null) {
    if (!date) return "-";

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
        timeZone: "Asia/Jakarta",
    }).format(jakartaDate(date));
}

function humanize(value: string) {
    return value
        .split("_")
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(" ");
}

function sourceLabel(source: string) {
    const labels: Record<string, string> = {
        manual: "Manual",
        bank_csv: "Bank CSV",
        ewallet_csv: "E-Wallet CSV",
        stellar: "Stellar",
    };

    return labels[source] ?? humanize(source);
}

function severityClass(severity: string) {
    if (severity === "critical" || severity === "exceeded") {
        return "bg-red-50 text-red-700 ring-red-100";
    }

    if (severity === "warning") {
        return "bg-amber-50 text-amber-700 ring-amber-100";
    }

    if (severity === "notice") {
        return "bg-blue-50 text-blue-700 ring-blue-100";
    }

    return "bg-emerald-50 text-emerald-700 ring-emerald-100";
}

export function DashboardPage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const defaultRange = useMemo(() => last30DaysRange(), []);
    const [startDate, setStartDate] = useState(defaultRange.start);
    const [endDate, setEndDate] = useState(defaultRange.end);
    const [walletId, setWalletId] = useState("");
    const [source, setSource] = useState("");
    const [wallets, setWallets] = useState<WalletType[]>([]);
    const [dashboard, setDashboard] = useState<DashboardSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState("");
    const inFlight = useRef(false);
    const queued = useRef(false);

    async function fetchDashboard(options: { background?: boolean } = {}) {
        if (inFlight.current) {
            queued.current = true;
            return;
        }

        inFlight.current = true;

        try {
            if (options.background) {
                setRefreshing(true);
            } else {
                setLoading(true);
            }
            setError("");

            const [dashboardData, walletData] = await Promise.all([
                getDashboardSummary({
                    start_date: startDate,
                    end_date: endDate,
                    wallet_id: walletId ? Number(walletId) : null,
                    source: source || null,
                }),
                getWallets(),
            ]);

            setDashboard(dashboardData);
            setWallets(walletData);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data dashboard"));
        } finally {
            setLoading(false);
            setRefreshing(false);
            inFlight.current = false;

            if (queued.current) {
                queued.current = false;
                void fetchDashboard({ background: true });
            }
        }
    }

    function applyLast30Days() {
        const nextRange = last30DaysRange();
        setStartDate(nextRange.start);
        setEndDate(nextRange.end);
    }

    useEffect(() => {
        fetchDashboard();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const timeout = window.setTimeout(() => {
            void fetchDashboard({ background: true });
        }, 250);

        return () => window.clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [startDate, endDate, walletId, source]);

    useEffect(() => {
        function handleFocus() {
            void fetchDashboard({ background: true });
        }

        function handleMutation() {
            void fetchDashboard({ background: true });
        }

        const interval = window.setInterval(() => {
            if (document.visibilityState === "visible") {
                void fetchDashboard({ background: true });
            }
        }, 30000);

        window.addEventListener("focus", handleFocus);
        window.addEventListener("lokafi:data-mutated", handleMutation);

        return () => {
            window.clearInterval(interval);
            window.removeEventListener("focus", handleFocus);
            window.removeEventListener("lokafi:data-mutated", handleMutation);
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [startDate, endDate, walletId, source]);

    const hasCashflow = useMemo(() => {
        return dashboard?.daily_cashflow.some((item) => item.income > 0 || item.expense > 0) ?? false;
    }, [dashboard]);

    if (loading && !dashboard) {
        return (
            <div className="flex min-h-[60vh] items-center justify-center">
                <div className="flex items-center gap-3 rounded-2xl bg-white px-6 py-4 shadow-sm">
                    <Loader2 className="animate-spin text-blue-600" size={22} />
                    <span className="text-sm font-medium text-slate-600">
                        Memuat dashboard...
                    </span>
                </div>
            </div>
        );
    }

    if (error && !dashboard) {
        return (
            <div className="rounded-2xl border border-red-100 bg-red-50 p-6 text-red-700">
                <h2 className="font-semibold">Dashboard gagal dimuat</h2>
                <p className="mt-1 text-sm">{error}</p>
                <button
                    onClick={() => fetchDashboard()}
                    className="mt-4 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white"
                >
                    Coba Lagi
                </button>
            </div>
        );
    }

    if (!dashboard) {
        return null;
    }

    const summary = dashboard.summary;

    return (
        <div className="max-w-full space-y-7 overflow-hidden">
            <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                <div className="min-w-0">
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Dashboard
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-slate-500">
                        Ringkasan keuangan {user?.name ?? "kamu"} dari {formatDate(dashboard.period.start_date)} sampai{" "}
                        {formatDate(dashboard.period.end_date)}. Semua widget memakai periode dan filter yang sama.
                    </p>
                </div>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        void fetchDashboard({ background: true });
                    }}
                    className="grid gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 sm:grid-cols-2 xl:flex xl:items-end"
                >
                    <Field label="Periode">
                        <button
                            type="button"
                            onClick={applyLast30Days}
                            className="w-full rounded-xl border border-blue-200 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-100 xl:w-36"
                        >
                            30 Hari Terakhir
                        </button>
                    </Field>
                    <Field label="Mulai">
                        <input
                            type="date"
                            value={startDate}
                            onChange={(event) => setStartDate(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 xl:w-40"
                        />
                    </Field>
                    <Field label="Sampai">
                        <input
                            type="date"
                            value={endDate}
                            onChange={(event) => setEndDate(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 xl:w-40"
                        />
                    </Field>
                    <Field label="Akun">
                        <select
                            value={walletId}
                            onChange={(event) => setWalletId(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 xl:w-44"
                        >
                            <option value="">Semua Akun</option>
                            {wallets.map((wallet) => (
                                <option key={wallet.id} value={wallet.id}>
                                    {wallet.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Sumber">
                        <select
                            value={source}
                            onChange={(event) => setSource(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 xl:w-40"
                        >
                            {SOURCE_OPTIONS.map((item) => (
                                <option key={item.value} value={item.value}>
                                    {item.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <button
                        type="submit"
                        disabled={refreshing}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {refreshing ? <Loader2 className="animate-spin" size={17} /> : <RefreshCw size={17} />}
                        Segarkan
                    </button>
                </form>
            </div>

            {error && (
                <div className="rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-sm text-amber-700">
                    {error}
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    label="Saldo Total"
                    value={formatCurrency(summary.total_balance)}
                    detail="Saldo akun aktif saat ini"
                    icon={<Wallet size={20} />}
                    iconClass="bg-blue-100 text-blue-700"
                />
                <SummaryCard
                    label="Total Pemasukan"
                    value={formatCurrency(summary.total_income)}
                    detail={formatPercent(dashboard.comparison.income_change_percentage)}
                    icon={<ArrowDown size={20} />}
                    iconClass="bg-emerald-100 text-emerald-700"
                />
                <SummaryCard
                    label="Total Pengeluaran"
                    value={formatCurrency(summary.total_expense)}
                    detail={formatPercent(dashboard.comparison.expense_change_percentage)}
                    icon={<ArrowUp size={20} />}
                    iconClass="bg-red-100 text-red-700"
                />
                <SummaryCard
                    label="Arus Kas Bersih"
                    value={formatCurrency(summary.net_cashflow)}
                    detail={formatPercent(dashboard.comparison.net_cashflow_change_percentage)}
                    icon={<Banknote size={20} />}
                    iconClass="bg-indigo-100 text-indigo-700"
                />
            </div>

            <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(320px,1fr)]">
                <section className="min-w-0 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                    <div className="mb-5 flex flex-col justify-between gap-3 sm:flex-row sm:items-start">
                        <SectionHeader
                            title="Arus Kas Harian"
                            description="Pemasukan dan pengeluaran pada periode terpilih."
                        />
                        {refreshing && (
                            <div className="flex items-center gap-2 text-sm text-slate-500">
                                <Loader2 className="animate-spin" size={16} />
                                Menyegarkan
                            </div>
                        )}
                    </div>

                    {hasCashflow ? (
                        <div className="h-80 min-w-0">
                            <ResponsiveContainer width="100%" height="100%">
                                <AreaChart data={dashboard.daily_cashflow}>
                                    <defs>
                                        <linearGradient id="incomeGradient" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#10B981" stopOpacity={0.28} />
                                            <stop offset="95%" stopColor="#10B981" stopOpacity={0} />
                                        </linearGradient>
                                        <linearGradient id="expenseGradient" x1="0" y1="0" x2="0" y2="1">
                                            <stop offset="5%" stopColor="#EF4444" stopOpacity={0.22} />
                                            <stop offset="95%" stopColor="#EF4444" stopOpacity={0} />
                                        </linearGradient>
                                    </defs>
                                    <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                    <XAxis
                                        dataKey="date"
                                        tickFormatter={formatShortDate}
                                        tick={{ fontSize: 12, fill: "#64748B" }}
                                        axisLine={false}
                                        tickLine={false}
                                    />
                                    <YAxis
                                        tickFormatter={(value) => formatCompactCurrency(Number(value))}
                                        tick={{ fontSize: 12, fill: "#64748B" }}
                                        axisLine={false}
                                        tickLine={false}
                                        width={82}
                                    />
                                    <Tooltip
                                        formatter={(value: unknown) => formatCurrency(Number(value))}
                                        labelFormatter={(label) => formatDate(String(label))}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="income"
                                        name="Pemasukan"
                                        stroke="#10B981"
                                        fill="url(#incomeGradient)"
                                        strokeWidth={3}
                                    />
                                    <Area
                                        type="monotone"
                                        dataKey="expense"
                                        name="Pengeluaran"
                                        stroke="#EF4444"
                                        fill="url(#expenseGradient)"
                                        strokeWidth={3}
                                    />
                                </AreaChart>
                            </ResponsiveContainer>
                        </div>
                    ) : (
                        <EmptyBlock message="Belum ada transaksi pada periode ini." />
                    )}
                </section>

                <section className="min-w-0 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                    <SectionHeader
                        title="Distribusi Pengeluaran"
                        description="Kategori pengeluaran aktual pada periode terpilih."
                    />
                    <ExpenseDistribution items={dashboard.expense_distribution} />
                </section>
            </div>

            <div className="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                <section className="min-w-0 rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                    <SectionHeader
                        title="Transaksi Terbaru"
                        description="Transaksi valid dari periode dan filter dashboard."
                    />
                    <RecentTransactions transactions={dashboard.recent_transactions} />
                </section>

                <div className="space-y-6">
                    <section className="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                        <SectionHeader
                            title="Distribusi Sumber"
                            description="Manual, CSV, dan Stellar."
                        />
                        <SourceDistribution items={dashboard.source_distribution} />
                    </section>

                    <section className="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                        <SectionHeader title="Aksi Cepat" description="Aksi utama LokaFi MVP." />
                        <div className="mt-4 grid gap-3">
                            <QuickAction
                                icon={<Plus size={18} />}
                                label="Tambah Transaksi"
                                onClick={() => navigate("/transactions")}
                            />
                            <QuickAction
                                icon={<Upload size={18} />}
                                label="Import Statement Bank/E-Wallet"
                                onClick={() => navigate("/transaction-imports")}
                            />
                            <QuickAction
                                icon={<FileText size={18} />}
                                label="Buat Invoice"
                                onClick={() => navigate("/invoices/create")}
                            />
                            <QuickAction
                                icon={<Sparkles size={18} />}
                                label="Hubungkan Stellar Wallet"
                                onClick={() => navigate("/stellar-wallet")}
                            />
                        </div>
                    </section>
                </div>
            </div>

            <div className="grid gap-6 xl:grid-cols-2">
                <section className="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                    <SectionHeader title="Peringatan Budget" description="Budget yang perlu dicek." />
                    <BudgetAlerts items={dashboard.budget_alerts} />
                </section>
                <section className="rounded-3xl bg-white p-5 shadow-sm ring-1 ring-slate-100 sm:p-6">
                    <SectionHeader title="Aktivitas Tidak Biasa" description="Sinyal deterministik dari data transaksi." />
                    <Anomalies items={dashboard.anomalies} />
                </section>
            </div>
        </div>
    );
}

function formatCompactCurrency(value: number) {
    if (value === 0) return "Rp 0";

    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
        notation: Math.abs(value) >= 1_000_000 ? "compact" : "standard",
    }).format(value);
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <label className="block">
            <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {label}
            </span>
            {children}
        </label>
    );
}

function SectionHeader({ title, description }: { title: string; description: string }) {
    return (
        <div className="min-w-0">
            <h2 className="text-xl font-bold text-slate-950">{title}</h2>
            <p className="mt-1 text-sm text-slate-500">{description}</p>
        </div>
    );
}

function SummaryCard({
    label,
    value,
    detail,
    icon,
    iconClass,
}: {
    label: string;
    value: string;
    detail: string;
    icon: React.ReactNode;
    iconClass: string;
}) {
    return (
        <div className="min-w-0 rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <div className="flex items-start justify-between gap-3">
                <p className="text-sm font-medium text-slate-500">{label}</p>
                <div className={`rounded-2xl p-3 ${iconClass}`}>{icon}</div>
            </div>
            <h3 className="mt-5 truncate text-2xl font-bold tracking-tight text-slate-950">
                {value}
            </h3>
            <p className="mt-2 text-sm font-medium text-slate-500">{detail}</p>
        </div>
    );
}

function ExpenseDistribution({ items }: { items: DashboardExpenseDistribution[] }) {
    if (items.length === 0) {
        return <EmptyBlock message="Belum ada transaksi pengeluaran pada periode ini." />;
    }

    const chartData = items.map((item) => ({
        name: item.category_name,
        value: item.amount,
        color: item.category_color,
    }));

    return (
        <div className="mt-5">
            <div className="h-56">
                <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                        <Pie
                            data={chartData}
                            dataKey="value"
                            nameKey="name"
                            innerRadius={58}
                            outerRadius={88}
                            paddingAngle={3}
                        >
                            {chartData.map((entry, index) => (
                                <Cell
                                    key={entry.name}
                                    fill={entry.color || COLORS[index % COLORS.length]}
                                />
                            ))}
                        </Pie>
                        <Tooltip formatter={(value: unknown) => formatCurrency(Number(value))} />
                    </PieChart>
                </ResponsiveContainer>
            </div>
            <div className="mt-4 space-y-3">
                {items.map((item, index) => (
                    <div key={`${item.category_id ?? "uncategorized"}-${item.category_name}`} className="text-sm">
                        <div className="mb-1 flex items-center justify-between gap-3">
                            <div className="flex min-w-0 items-center gap-2">
                                <span
                                    className="h-3 w-3 shrink-0 rounded-full"
                                    style={{ backgroundColor: item.category_color || COLORS[index % COLORS.length] }}
                                />
                                <span className="truncate font-medium text-slate-700">{item.category_name}</span>
                            </div>
                            <span className="shrink-0 font-semibold text-slate-900">{formatCurrency(item.amount)}</span>
                        </div>
                        <p className="text-xs text-slate-500">
                            {item.share}% dari pengeluaran, {item.transaction_count} transaksi
                        </p>
                    </div>
                ))}
            </div>
        </div>
    );
}

function RecentTransactions({ transactions }: { transactions: DashboardTransaction[] }) {
    if (transactions.length === 0) {
        return <EmptyBlock message="Belum ada transaksi pada periode ini." />;
    }

    return (
        <div className="mt-5 overflow-x-auto rounded-2xl border border-slate-100">
            <table className="w-full min-w-[640px] text-left text-sm">
                <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                    <tr>
                        <th className="px-4 py-3">Transaksi</th>
                        <th className="px-4 py-3">Sumber</th>
                        <th className="px-4 py-3">Tanggal</th>
                        <th className="px-4 py-3 text-right">Nominal</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                    {transactions.map((transaction) => (
                        <tr key={transaction.id} className="hover:bg-slate-50">
                            <td className="px-4 py-4">
                                <div className="flex min-w-0 items-center gap-3">
                                    <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                        {transaction.type === "income" ? <ArrowDown size={18} /> : <ArrowUp size={18} />}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="truncate font-semibold text-slate-900">
                                            {transaction.merchant || transaction.description || transaction.type}
                                        </p>
                                        <p className="truncate text-xs text-slate-500">
                                            {transaction.category?.name ?? "Belum dikategorikan"} - {transaction.wallet?.name ?? "Tanpa akun"}
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td className="px-4 py-4 text-slate-600">{sourceLabel(transaction.source)}</td>
                            <td className="px-4 py-4 text-slate-600">{formatDateTime(transaction.happened_at)}</td>
                            <td
                                className={`px-4 py-4 text-right font-bold ${
                                    transaction.type === "income" ? "text-emerald-600" : "text-red-600"
                                }`}
                            >
                                {transaction.type === "income" ? "+" : "-"}
                                {formatCurrency(transaction.effective_amount)}
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function SourceDistribution({ items }: { items: DashboardSourceDistribution[] }) {
    const activeItems = items.filter((item) => item.count > 0);

    if (activeItems.length === 0) {
        return <EmptyBlock message="Belum ada sumber transaksi aktif pada periode ini." />;
    }

    return (
        <div className="mt-5 space-y-4">
            {items.map((item) => (
                <div key={item.source}>
                    <div className="mb-2 flex items-center justify-between gap-4 text-sm">
                        <span className="font-semibold text-slate-800">{item.label}</span>
                        <span className="text-slate-500">
                            {formatCurrency(item.amount)} / {item.count} transaksi
                        </span>
                    </div>
                    <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                        <div
                            className="h-full rounded-full bg-blue-600"
                            style={{ width: `${Math.min(item.share, 100)}%` }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function BudgetAlerts({ items }: { items: DashboardBudgetAlert[] }) {
    if (items.length === 0) {
        return <EmptyBlock message="Belum ada budget yang perlu dicek." />;
    }

    return (
        <div className="mt-5 space-y-3">
            {items.map((item) => (
                <div key={item.budget_id} className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-semibold text-slate-900">{item.category_name ?? "Kategori"}</p>
                            <p className="mt-1 text-sm text-slate-500">
                                {formatCurrency(item.amount_spent)} dari {formatCurrency(item.budget_amount)}
                            </p>
                        </div>
                        <Badge label={humanize(item.severity)} className={severityClass(item.severity)} />
                    </div>
                    <div className="mt-3 h-2 overflow-hidden rounded-full bg-white">
                        <div
                            className="h-full rounded-full bg-blue-600"
                            style={{ width: `${Math.min(item.usage_percentage, 100)}%` }}
                        />
                    </div>
                </div>
            ))}
        </div>
    );
}

function Anomalies({ items }: { items: DashboardAnomaly[] }) {
    if (items.length === 0) {
        return <EmptyBlock message="Tidak ada aktivitas tidak biasa pada periode ini." />;
    }

    return (
        <div className="mt-5 space-y-3">
            {items.map((item, index) => (
                <div key={`${item.type}-${index}`} className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
                    <div className="flex items-start justify-between gap-3">
                        <div>
                            <p className="font-semibold text-slate-900">{humanize(item.type)}</p>
                            <p className="mt-1 text-sm text-slate-500">{humanize(item.explanation_code)}</p>
                        </div>
                        <Badge label={humanize(item.severity)} className={severityClass(item.severity)} />
                    </div>
                </div>
            ))}
        </div>
    );
}

function QuickAction({
    icon,
    label,
    onClick,
}: {
    icon: React.ReactNode;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex w-full items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
        >
            <div className="flex min-w-0 items-center gap-3">
                <div className="rounded-xl bg-blue-50 p-3 text-blue-700">{icon}</div>
                <span className="truncate font-semibold text-slate-800">{label}</span>
            </div>
            <Send className="shrink-0 text-slate-400" size={16} />
        </button>
    );
}

function Badge({ label, className }: { label: string; className: string }) {
    return (
        <span className={`inline-flex items-center rounded-full px-3 py-1 text-xs font-bold ring-1 ${className}`}>
            {label}
        </span>
    );
}

function EmptyBlock({ message }: { message: string }) {
    return (
        <div className="mt-5 flex min-h-44 flex-col items-center justify-center rounded-2xl bg-slate-50 px-4 text-center text-sm text-slate-500">
            <PieChartIcon className="mb-3 text-slate-400" size={28} />
            {message}
        </div>
    );
}
