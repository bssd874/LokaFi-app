import { useEffect, useMemo, useState } from "react";
import { Link, useNavigate } from "react-router-dom";
import {
    AlertCircle,
    ArrowDown,
    ArrowUp,
    Banknote,
    CreditCard,
    Landmark,
    Loader2,
    PiggyBank,
    Plus,
    Send,
    Wallet,
    LineChart,
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
import { getBankConnections } from "../features/bankConnections/bankConnectionApi";
import { getPortfolio } from "../features/investments/investmentApi";
import { useAuthStore } from "../store/authStore";
import { getApiErrorMessage } from "../utils/apiError";
import type { DashboardSummary } from "../types/dashboard";
import type { BankConnection } from "../types/bankConnection";
import type { PortfolioSummary } from "../types/investment";

function formatCurrency(value: number | string) {
    const numericValue = Number(value ?? 0);

    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(numericValue);
}

function formatDate(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(new Date(date));
}

function formatShortDate(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
    }).format(new Date(date));
}

function formatDateTime(date?: string | null) {
    if (!date) return "Belum pernah sync";

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(date));
}

const COLORS = ["#2563EB", "#059669", "#EF4444", "#F59E0B", "#8B5CF6", "#64748B"];

const emptyPortfolioSummary: PortfolioSummary = {
    investment_cash_balance: 0,
    total_portfolio_value: 0,
    total_cost_basis: 0,
    total_unrealized_profit_loss: 0,
    total_unrealized_profit_loss_percentage: 0,
    total_assets: 0,
    total_equity: 0,
};

export function DashboardPage() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const [dashboard, setDashboard] = useState<DashboardSummary | null>(null);
    const [bankConnections, setBankConnections] = useState<BankConnection[]>([]);
    const [portfolioSummary, setPortfolioSummary] =
        useState<PortfolioSummary>(emptyPortfolioSummary);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState("");
    const [bankError, setBankError] = useState("");
    const [portfolioError, setPortfolioError] = useState("");

    async function fetchDashboard() {
        try {
            setLoading(true);
            setError("");

            // Sesuaikan dengan data testing lo.
            // Kalau transaksi test lo pakai 2026-05, ini bakal muncul.
            const data = await getDashboardSummary({
                from: "2026-05-01",
                to: "2026-05-31",
            });

            setDashboard(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data dashboard"));
        } finally {
            setLoading(false);
        }
    }

    async function fetchSimulatorSummaries() {
        setBankError("");
        setPortfolioError("");

        const [bankResult, portfolioResult] = await Promise.allSettled([
            getBankConnections(),
            getPortfolio(),
        ]);

        if (bankResult.status === "fulfilled") {
            setBankConnections(bankResult.value);
        } else {
            setBankError("Ringkasan koneksi bank belum bisa dimuat.");
        }

        if (portfolioResult.status === "fulfilled") {
            setPortfolioSummary(portfolioResult.value.summary);
        } else {
            setPortfolioSummary(emptyPortfolioSummary);
            setPortfolioError("Ringkasan investasi belum bisa dimuat.");
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchDashboard();
        fetchSimulatorSummaries();
    }, []);

    const expenseChartData = useMemo(() => {
        if (!dashboard) return [];

        return dashboard.expense_by_category.map((item) => ({
            name: item.category_name,
            value: Number(item.total),
            color: item.category_color,
        }));
    }, [dashboard]);

    const connectedBankCount = useMemo(() => {
        return bankConnections.filter((connection) => connection.status === "connected")
            .length;
    }, [bankConnections]);

    const latestSyncAt = useMemo(() => {
        return bankConnections
            .map((connection) => connection.last_synced_at)
            .filter(Boolean)
            .sort((a, b) => new Date(String(b)).getTime() - new Date(String(a)).getTime())[0];
    }, [bankConnections]);

    if (loading) {
        return (
            <div className="flex min-h-[60vh] items-center justify-center">
                <div className="flex items-center gap-3 rounded-2xl bg-white px-6 py-4 shadow-sm">
                    <Loader2 className="animate-spin text-blue-600" size={22} />
                    <span className="text-sm font-medium text-slate-600">
                        Loading dashboard...
                    </span>
                </div>
            </div>
        );
    }

    if (error || !dashboard) {
        return (
            <div className="rounded-2xl border border-red-100 bg-red-50 p-6 text-red-700">
                <h2 className="font-semibold">Dashboard gagal dimuat</h2>
                <p className="mt-1 text-sm">{error}</p>
                <button
                    onClick={fetchDashboard}
                    className="mt-4 rounded-xl bg-red-600 px-4 py-2 text-sm font-semibold text-white"
                >
                    Coba Lagi
                </button>
            </div>
        );
    }

    const summary = dashboard.summary;

    return (
        <div className="space-y-7">
            {/* Header */}
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Dashboard
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Ringkasan keuangan {user?.name ?? "kamu"} dari {formatDate(dashboard.period.from)} sampai{" "}
                        {formatDate(dashboard.period.to)}.
                    </p>
                </div>

                <div className="flex gap-3">
                    <button className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm">
                        Last 30 Days
                    </button>
                    <button className="rounded-xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 shadow-sm">
                        All Wallets
                    </button>
                </div>
            </div>

            {/* Summary Cards */}
            <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                <SummaryCard
                    label="Total Balance"
                    value={formatCurrency(summary.total_balance)}
                    trend="+2.4% vs last month"
                    icon={<Wallet size={20} />}
                    iconClass="bg-blue-100 text-blue-700"
                />

                <SummaryCard
                    label="Total Income"
                    value={formatCurrency(summary.total_income)}
                    trend="+8.1% vs last month"
                    icon={<ArrowDown size={20} />}
                    iconClass="bg-emerald-100 text-emerald-700"
                    positive
                />

                <SummaryCard
                    label="Total Expense"
                    value={formatCurrency(summary.total_expense)}
                    trend="-1.2% vs last month"
                    icon={<ArrowUp size={20} />}
                    iconClass="bg-red-100 text-red-700"
                    danger
                />

                <SummaryCard
                    label="Net Cashflow"
                    value={formatCurrency(summary.net_cashflow)}
                    trend="+12.5% vs last month"
                    icon={<Banknote size={20} />}
                    iconClass="bg-indigo-100 text-indigo-700"
                    positive
                />
            </div>

            {/* Main Charts */}
            <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-6 flex items-start justify-between">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Monthly Cashflow Performance
                            </h2>
                            <p className="text-sm text-slate-500">
                                Income vs expense pada periode terpilih
                            </p>
                        </div>

                        <div className="flex items-center gap-4 text-sm text-slate-500">
                            <div className="flex items-center gap-2">
                                <span className="h-2.5 w-2.5 rounded-full bg-emerald-500" />
                                Income
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="h-2.5 w-2.5 rounded-full bg-red-500" />
                                Expense
                            </div>
                        </div>
                    </div>

                    <div className="h-[340px]">
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
                                    tickFormatter={(value) => `${Number(value) / 1000}k`}
                                    tick={{ fontSize: 12, fill: "#64748B" }}
                                    axisLine={false}
                                    tickLine={false}
                                />
                                <Tooltip
                                    formatter={(value: unknown) => formatCurrency(Number(value))}
                                    labelFormatter={(label) => formatDate(String(label))}
                                />
                                <Area
                                    type="monotone"
                                    dataKey="income"
                                    stroke="#10B981"
                                    fill="url(#incomeGradient)"
                                    strokeWidth={3}
                                />
                                <Area
                                    type="monotone"
                                    dataKey="expense"
                                    stroke="#EF4444"
                                    fill="url(#expenseGradient)"
                                    strokeWidth={3}
                                />
                            </AreaChart>
                        </ResponsiveContainer>
                    </div>
                </div>

                <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                    <h2 className="text-xl font-bold text-slate-950">
                        Expense Distribution
                    </h2>
                    <p className="text-sm text-slate-500">Distribusi pengeluaran</p>

                    <div className="mt-6 h-[230px]">
                        {expenseChartData.length > 0 ? (
                            <ResponsiveContainer width="100%" height="100%">
                                <PieChart>
                                    <Pie
                                        data={expenseChartData}
                                        dataKey="value"
                                        nameKey="name"
                                        innerRadius={65}
                                        outerRadius={95}
                                        paddingAngle={4}
                                    >
                                        {expenseChartData.map((entry, index) => (
                                            <Cell
                                                key={entry.name}
                                                fill={entry.color || COLORS[index % COLORS.length]}
                                            />
                                        ))}
                                    </Pie>
                                    <Tooltip
                                        formatter={(value: unknown) =>
                                            formatCurrency(Number(value))
                                        }
                                    />
                                </PieChart>
                            </ResponsiveContainer>
                        ) : (
                            <div className="flex h-full items-center justify-center rounded-2xl bg-slate-50 text-sm text-slate-500">
                                Belum ada data expense
                            </div>
                        )}
                    </div>

                    <div className="mt-4 space-y-3">
                        {expenseChartData.map((item, index) => (
                            <div
                                key={item.name}
                                className="flex items-center justify-between text-sm"
                            >
                                <div className="flex items-center gap-2">
                                    <span
                                        className="h-3 w-3 rounded-full"
                                        style={{ backgroundColor: item.color || COLORS[index % COLORS.length] }}
                                    />
                                    <span className="text-slate-600">{item.name}</span>
                                </div>
                                <span className="font-semibold text-slate-900">
                                    {formatCurrency(item.value)}
                                </span>
                            </div>
                        ))}
                    </div>
                </div>
            </div>

            {/* Bottom */}
            <div className="grid gap-6 xl:grid-cols-[2fr_1fr]">
                <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-6 flex items-center justify-between">
                        <h2 className="text-xl font-bold text-slate-950">
                            Recent Transactions
                        </h2>
                        <button className="text-sm font-semibold text-blue-600">
                            View All
                        </button>
                    </div>

                    <div className="overflow-hidden rounded-2xl border border-slate-100">
                        <table className="w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Transaction</th>
                                    <th className="px-4 py-3">Category</th>
                                    <th className="px-4 py-3">Date</th>
                                    <th className="px-4 py-3 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {dashboard.recent_transactions.length > 0 ? (
                                    dashboard.recent_transactions.map((transaction) => (
                                        <tr key={transaction.id} className="hover:bg-slate-50">
                                            <td className="px-4 py-4">
                                                <div className="flex items-center gap-3">
                                                    <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-blue-50 text-blue-700">
                                                        {transaction.type === "income" ? (
                                                            <ArrowDown size={18} />
                                                        ) : transaction.type === "expense" ? (
                                                            <CreditCard size={18} />
                                                        ) : (
                                                            <Send size={18} />
                                                        )}
                                                    </div>
                                                    <div>
                                                        <p className="font-semibold text-slate-900">
                                                            {transaction.merchant ||
                                                                transaction.note ||
                                                                transaction.type}
                                                        </p>
                                                        <p className="text-xs text-slate-500">
                                                            {transaction.wallet?.name ||
                                                                transaction.from_wallet?.name ||
                                                                "-"}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="px-4 py-4 text-slate-600">
                                                {transaction.category?.name ?? "Transfer"}
                                            </td>
                                            <td className="px-4 py-4 text-slate-600">
                                                {formatDate(transaction.happened_at)}
                                            </td>
                                            <td
                                                className={`px-4 py-4 text-right font-bold ${transaction.type === "income"
                                                        ? "text-emerald-600"
                                                        : transaction.type === "expense"
                                                            ? "text-red-600"
                                                            : "text-blue-600"
                                                    }`}
                                            >
                                                {transaction.type === "income" ? "+" : "-"}
                                                {formatCurrency(Number(transaction.amount))}
                                            </td>
                                        </tr>
                                    ))
                                ) : (
                                    <tr>
                                        <td
                                            colSpan={4}
                                            className="px-4 py-10 text-center text-sm text-slate-500"
                                        >
                                            Belum ada transaksi
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                        <h2 className="text-xl font-bold text-slate-950">Quick Actions</h2>

                        <div className="mt-5 space-y-3">
                            <QuickAction
                                icon={<Plus size={18} />}
                                label="Add Transaction"
                                className="bg-blue-50 text-blue-700"
                                onClick={() => navigate("/transactions")}
                            />
                            <QuickAction
                                icon={<PiggyBank size={18} />}
                                label="Create Budget"
                                className="bg-emerald-50 text-emerald-700"
                                onClick={() => navigate("/budgets")}
                            />
                            <QuickAction
                                icon={<Landmark size={18} />}
                                label="Connect Bank"
                                className="bg-indigo-50 text-indigo-700"
                                onClick={() => navigate("/bank-connections")}
                            />
                            <QuickAction
                                icon={<LineChart size={18} />}
                                label="Buy Asset"
                                className="bg-blue-50 text-blue-700"
                                onClick={() => navigate("/investments")}
                            />
                        </div>
                    </div>

                    <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-bold text-slate-950">
                                    Bank Connections
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Simulator Open Banking summary
                                </p>
                            </div>
                            <div className="rounded-2xl bg-indigo-50 p-3 text-indigo-700">
                                <Landmark size={20} />
                            </div>
                        </div>

                        {bankError ? (
                            <InlineError message={bankError} />
                        ) : (
                            <div className="mt-5 grid gap-3">
                                <div className="rounded-2xl bg-slate-50 p-4">
                                    <p className="text-sm text-slate-500">Connected Accounts</p>
                                    <p className="mt-1 text-2xl font-bold text-slate-950">
                                        {connectedBankCount}
                                    </p>
                                </div>
                                <div className="rounded-2xl bg-slate-50 p-4">
                                    <p className="text-sm text-slate-500">Latest Sync</p>
                                    <p className="mt-1 font-semibold text-slate-900">
                                        {formatDateTime(latestSyncAt)}
                                    </p>
                                </div>
                            </div>
                        )}

                        <Link
                            to="/bank-connections"
                            className="mt-5 inline-flex text-sm font-semibold text-blue-600 hover:text-blue-700"
                        >
                            View Bank Connections
                        </Link>
                    </div>

                    <div className="rounded-3xl bg-white p-7 shadow-sm ring-1 ring-slate-100">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h2 className="text-xl font-bold text-slate-950">
                                    Investments
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Portfolio simulator summary
                                </p>
                            </div>
                            <div className="rounded-2xl bg-blue-50 p-3 text-blue-700">
                                <LineChart size={20} />
                            </div>
                        </div>

                        {portfolioError ? (
                            <InlineError message={portfolioError} />
                        ) : (
                            <div className="mt-5 space-y-3">
                                <MiniMetric
                                    label="Investment Cash"
                                    value={formatCurrency(
                                        portfolioSummary.investment_cash_balance,
                                    )}
                                />
                                <MiniMetric
                                    label="Portfolio Value"
                                    value={formatCurrency(
                                        portfolioSummary.total_portfolio_value,
                                    )}
                                />
                                <MiniMetric
                                    label="Unrealized P/L"
                                    value={formatCurrency(
                                        portfolioSummary.total_unrealized_profit_loss,
                                    )}
                                    className={
                                        portfolioSummary.total_unrealized_profit_loss >= 0
                                            ? "text-emerald-600"
                                            : "text-red-600"
                                    }
                                />
                            </div>
                        )}

                        <Link
                            to="/investments"
                            className="mt-5 inline-flex text-sm font-semibold text-blue-600 hover:text-blue-700"
                        >
                            View Investments
                        </Link>
                    </div>
                </div>
            </div>
        </div>
    );
}

type SummaryCardProps = {
    label: string;
    value: string;
    trend: string;
    icon: React.ReactNode;
    iconClass: string;
    positive?: boolean;
    danger?: boolean;
};

function SummaryCard({
    label,
    value,
    trend,
    icon,
    iconClass,
    positive,
    danger,
}: SummaryCardProps) {
    return (
        <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <div className="flex items-start justify-between">
                <p className="text-sm font-medium text-slate-500">{label}</p>
                <div className={`rounded-2xl p-3 ${iconClass}`}>{icon}</div>
            </div>

            <h3 className="mt-5 text-2xl font-bold tracking-tight text-slate-950">
                {value}
            </h3>

            <p
                className={`mt-2 text-sm font-medium ${positive ? "text-emerald-600" : danger ? "text-red-600" : "text-slate-500"
                    }`}
            >
                {trend}
            </p>
        </div>
    );
}

type QuickActionProps = {
    icon: React.ReactNode;
    label: string;
    className: string;
    onClick: () => void;
};

function QuickAction({ icon, label, className, onClick }: QuickActionProps) {
    return (
        <button
            onClick={onClick}
            className="flex w-full items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 text-left shadow-sm transition hover:-translate-y-0.5 hover:shadow-md"
        >
            <div className="flex items-center gap-3">
                <div className={`rounded-xl p-3 ${className}`}>{icon}</div>
                <span className="font-semibold text-slate-800">{label}</span>
            </div>
            <span className="text-slate-400">›</span>
        </button>
    );
}

function MiniMetric({
    label,
    value,
    className = "text-slate-950",
}: {
    label: string;
    value: string;
    className?: string;
}) {
    return (
        <div className="flex items-center justify-between rounded-2xl bg-slate-50 p-4">
            <span className="text-sm text-slate-500">{label}</span>
            <span className={`font-bold ${className}`}>{value}</span>
        </div>
    );
}

function InlineError({ message }: { message: string }) {
    return (
        <div className="mt-5 flex items-start gap-2 rounded-2xl bg-amber-50 p-4 text-sm text-amber-700">
            <AlertCircle className="mt-0.5 shrink-0" size={16} />
            <span>{message}</span>
        </div>
    );
}
