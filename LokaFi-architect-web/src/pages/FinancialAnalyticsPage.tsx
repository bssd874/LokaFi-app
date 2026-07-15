import { useEffect, useMemo, useState } from "react";
import {
    AlertTriangle,
    Activity,
    CalendarDays,
    Loader2,
    RefreshCw,
    Sparkles,
    TrendingDown,
    TrendingUp,
    WalletCards,
} from "lucide-react";
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from "recharts";
import {
    getBudgetAlerts,
    generateFinancialInsight,
    getFinancialAnomalies,
    getFinancialInsight,
    getFinancialSummary,
    getFinancialTrends,
    regenerateFinancialInsight,
} from "../features/financialIntelligence/financialIntelligenceApi";
import { getApiErrorMessage } from "../utils/apiError";
import type {
    AnomalyItem,
    BudgetAlertItem,
    BudgetAlerts,
    FinancialAnomalies,
    FinancialInsightAction,
    FinancialInsightResult,
    FinancialSummary,
    FinancialTrends,
    Severity,
    SupportingMetric,
    SourceDistributionItem,
} from "../types/financialIntelligence";

const SOURCE_OPTIONS = [
    { label: "All Sources", value: "" },
    { label: "Manual", value: "manual" },
    { label: "Bank CSV", value: "bank_csv" },
    { label: "E-Wallet CSV", value: "ewallet_csv" },
    { label: "Stellar", value: "stellar" },
];

const CHART_COLORS = ["#2563EB", "#059669", "#F59E0B", "#DC2626", "#7C3AED", "#475569"];

function localDateInput(date: Date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, "0");
    const day = String(date.getDate()).padStart(2, "0");

    return `${year}-${month}-${day}`;
}

function currentMonthRange() {
    const today = new Date();
    const start = new Date(today.getFullYear(), today.getMonth(), 1);
    const end = new Date(today.getFullYear(), today.getMonth() + 1, 0);

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
    if (value === null || value === undefined) return "N/A";

    return `${Number(value).toFixed(2)}%`;
}

function formatDate(date?: string | null) {
    if (!date) return "N/A";

    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
    }).format(new Date(date));
}

function humanize(value: string) {
    return value
        .split("_")
        .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
        .join(" ");
}

function translateInsightType(value: string) {
    const labels: Record<string, string> = {
        positive: "Positif",
        warning: "Perlu dicek",
        critical: "Prioritas",
        neutral: "Netral",
    };

    return labels[value] ?? humanize(value);
}

function translatePriority(value: string) {
    const labels: Record<string, string> = {
        high: "Tinggi",
        medium: "Sedang",
        low: "Rendah",
    };

    return labels[value] ?? humanize(value);
}

function severityClass(severity: Severity | string) {
    if (severity === "exceeded" || severity === "critical") {
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

export function FinancialAnalyticsPage() {
    const defaultRange = useMemo(() => currentMonthRange(), []);
    const [startDate, setStartDate] = useState(defaultRange.start);
    const [endDate, setEndDate] = useState(defaultRange.end);
    const [source, setSource] = useState("");
    const [summary, setSummary] = useState<FinancialSummary | null>(null);
    const [trends, setTrends] = useState<FinancialTrends | null>(null);
    const [budgets, setBudgets] = useState<BudgetAlerts | null>(null);
    const [anomalies, setAnomalies] = useState<FinancialAnomalies | null>(null);
    const [insight, setInsight] = useState<FinancialInsightResult | null>(null);
    const [loading, setLoading] = useState(true);
    const [insightLoading, setInsightLoading] = useState(false);
    const [error, setError] = useState("");
    const [insightError, setInsightError] = useState("");

    async function fetchAnalytics() {
        try {
            setLoading(true);
            setError("");

            const params = {
                start_date: startDate,
                end_date: endDate,
                source: source || undefined,
                per_page: 50,
            };

            const [summaryData, trendsData, budgetData, anomalyData, insightData] =
                await Promise.all([
                    getFinancialSummary(params),
                    getFinancialTrends(params),
                    getBudgetAlerts(params),
                    getFinancialAnomalies(params),
                    getFinancialInsight(params),
                ]);

            setSummary(summaryData);
            setTrends(trendsData);
            setBudgets(budgetData);
            setAnomalies(anomalyData);
            setInsight(insightData);
            setInsightError("");
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal menghitung financial analytics"));
        } finally {
            setLoading(false);
        }
    }

    async function handleGenerateInsight(force = false) {
        try {
            setInsightLoading(true);
            setInsightError("");

            const params = {
                start_date: startDate,
                end_date: endDate,
                source: source || undefined,
                per_page: 50,
            };
            const data = force
                ? await regenerateFinancialInsight(params)
                : await generateFinancialInsight(params);

            setInsight(data);

            if (!data.insight) {
                setInsightError(data.user_message);
            }
        } catch (err: unknown) {
            setInsightError(getApiErrorMessage(err, "Gagal membuat AI explanation"));
        } finally {
            setInsightLoading(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchAnalytics();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const hasNoData = !loading && summary?.summary.transaction_count === 0;
    const topTrends = trends?.category_trends.slice(0, 8) ?? [];
    const sourceData = summary?.source_distribution.filter((item) => item.count > 0) ?? [];

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 xl:flex-row xl:items-end">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Financial Analytics
                    </h1>
                    <p className="mt-1 max-w-3xl text-sm text-slate-500">
                        Deterministic analysis from your transactions and budgets. Values are
                        calculated by the backend for the selected period.
                    </p>
                </div>

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        fetchAnalytics();
                    }}
                    className="flex flex-col gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-slate-100 md:flex-row md:items-end"
                >
                    <Field label="Start Date" htmlFor="fi-start-date">
                        <input
                            id="fi-start-date"
                            type="date"
                            value={startDate}
                            onChange={(event) => setStartDate(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 md:w-40"
                        />
                    </Field>

                    <Field label="End Date" htmlFor="fi-end-date">
                        <input
                            id="fi-end-date"
                            type="date"
                            value={endDate}
                            onChange={(event) => setEndDate(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 md:w-40"
                        />
                    </Field>

                    <Field label="Source" htmlFor="fi-source">
                        <select
                            id="fi-source"
                            value={source}
                            onChange={(event) => setSource(event.target.value)}
                            className="w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500 md:w-44"
                        >
                            {SOURCE_OPTIONS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <button
                        type="submit"
                        disabled={loading}
                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {loading ? <Loader2 className="animate-spin" size={17} /> : <RefreshCw size={17} />}
                        Refresh
                    </button>
                </form>
            </div>

            {error && (
                <StatePanel
                    icon={<AlertTriangle size={22} />}
                    title="Financial analytics gagal dimuat"
                    description={error}
                    tone="danger"
                />
            )}

            {loading && (
                <StatePanel
                    icon={<Loader2 className="animate-spin" size={24} />}
                    title="Calculating financial analytics"
                    description="Backend sedang menghitung summary, trend, budget alert, dan unusual activity."
                />
            )}

            {!loading && !error && hasNoData && (
                <StatePanel
                    icon={<CalendarDays size={24} />}
                    title="Belum ada data pada periode ini"
                    description="Ubah date range atau import transaksi supaya analytics bisa dihitung."
                />
            )}

            {!loading && !error && summary && (
                <>
                    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                        <MetricCard
                            label="Total Income"
                            value={formatCurrency(summary.summary.total_income)}
                            detail={comparisonText(summary.comparison.income)}
                            icon={<TrendingUp size={20} />}
                            iconClass="bg-emerald-100 text-emerald-700"
                        />
                        <MetricCard
                            label="Total Expense"
                            value={formatCurrency(summary.summary.total_expense)}
                            detail={comparisonText(summary.comparison.expense)}
                            icon={<TrendingDown size={20} />}
                            iconClass="bg-red-100 text-red-700"
                        />
                        <MetricCard
                            label="Net Cashflow"
                            value={formatCurrency(summary.summary.net_cashflow)}
                            detail={comparisonText(summary.comparison.net_cashflow)}
                            icon={<Activity size={20} />}
                            iconClass="bg-blue-100 text-blue-700"
                        />
                        <MetricCard
                            label="Savings Rate"
                            value={formatPercent(summary.summary.savings_rate)}
                            detail={summary.summary.savings_rate_status}
                            icon={<WalletCards size={20} />}
                            iconClass="bg-slate-100 text-slate-700"
                        />
                    </div>

                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-start">
                            <SectionHeader
                                title="Rekomendasi Keuangan AI"
                                description="Saran praktis berbahasa Indonesia berdasarkan metric yang dihitung backend."
                            />

                            <div className="flex flex-wrap gap-2">
                                {insight?.cached && (
                                    <Badge label="Insight cache" className="bg-blue-50 text-blue-700 ring-blue-100" />
                                )}
                                {insight?.record?.generated_at && (
                                    <Badge
                                        label={`Dibuat ${formatDate(insight.record.generated_at)}`}
                                        className="bg-slate-50 text-slate-700 ring-slate-100"
                                    />
                                )}
                                <button
                                    type="button"
                                    onClick={() => handleGenerateInsight(false)}
                                    disabled={insightLoading}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                                >
                                    {insightLoading ? <Loader2 className="animate-spin" size={16} /> : <Sparkles size={16} />}
                                    {insight?.insight ? "Refresh AI" : "Buat AI"}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => handleGenerateInsight(true)}
                                    disabled={insightLoading}
                                    className="inline-flex items-center justify-center gap-2 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-60"
                                >
                                    <RefreshCw size={16} />
                                    Regenerate
                                </button>
                            </div>
                        </div>

                        {insightLoading ? (
                            <div className="mt-5 flex min-h-40 items-center justify-center rounded-2xl bg-slate-50 text-sm text-slate-500">
                                <Loader2 className="mr-2 animate-spin" size={18} />
                                AI sedang menyusun rekomendasi...
                            </div>
                        ) : insight?.insight ? (
                            <div className="mt-5 grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
                                <div className="space-y-5">
                                    <div className="rounded-2xl bg-blue-50 p-5">
                                        <p className="text-sm font-semibold uppercase tracking-wide text-blue-700">
                                            Penjelasan AI
                                        </p>
                                        <h3 className="mt-2 text-xl font-bold text-slate-950">
                                            {insight.insight.headline}
                                        </h3>
                                        <p className="mt-2 text-sm leading-6 text-slate-700">
                                            {insight.insight.summary}
                                        </p>
                                    </div>

                                    <div className="grid gap-3 md:grid-cols-2">
                                        {insight.insight.highlights.map((item) => (
                                            <InsightCard
                                                key={`${item.type}-${item.title}`}
                                                title={item.title}
                                                description={item.description}
                                                badge={translateInsightType(item.type)}
                                                badgeClass={severityClass(item.type)}
                                                metricKeys={item.evidence_keys}
                                                metrics={insight.supporting_metrics}
                                            />
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-4">
                                    <div>
                                        <h3 className="font-bold text-slate-950">Saran Praktis</h3>
                                        <div className="mt-3 space-y-3">
                                            {insight.insight.recommended_actions.map((action) => (
                                                <InsightAction
                                                    key={`${action.priority}-${action.title}`}
                                                    action={action}
                                                    metrics={insight.supporting_metrics}
                                                />
                                            ))}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4 text-sm text-slate-600">
                                        <p className="font-semibold text-slate-800">Catatan</p>
                                        <p className="mt-1">{insight.insight.disclaimer}</p>
                                    </div>
                                </div>
                            </div>
                        ) : (
                            <div className="mt-5 rounded-2xl bg-slate-50 p-5">
                                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                                    <div>
                                        <p className="font-semibold text-slate-900">
                                            {insight?.validation_status === "invalid_response"
                                                ? "Response AI tidak valid"
                                                : insight?.validation_status === "provider_error" || insight?.validation_status === "disabled"
                                                    ? "Provider AI belum tersedia"
                                                    : "Belum ada rekomendasi AI"}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-500">
                                            {insightError || insight?.user_message || "Buat rekomendasi saat data sudah siap."}
                                        </p>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={() => handleGenerateInsight(false)}
                                        disabled={insightLoading}
                                        className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                                    >
                                        <Sparkles size={16} />
                                        Try Generate
                                    </button>
                                </div>
                            </div>
                        )}
                    </section>

                    <div className="grid gap-6 xl:grid-cols-[1.3fr_1fr]">
                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <SectionHeader
                                title="Spending Trend"
                                description={`Current period ${formatDate(summary.period.start_date)} - ${formatDate(summary.period.end_date)} versus previous equal period.`}
                            />

                            {topTrends.length > 0 ? (
                                <div className="mt-5 h-72">
                                    <ResponsiveContainer width="100%" height="100%">
                                        <BarChart data={topTrends}>
                                            <CartesianGrid strokeDasharray="3 3" stroke="#E2E8F0" />
                                            <XAxis
                                                dataKey="category_name"
                                                tick={{ fontSize: 11, fill: "#64748B" }}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <YAxis
                                                tickFormatter={(value) => `${Number(value) / 1000}k`}
                                                tick={{ fontSize: 11, fill: "#64748B" }}
                                                tickLine={false}
                                                axisLine={false}
                                            />
                                            <Tooltip
                                                formatter={(value: unknown) => formatCurrency(Number(value))}
                                            />
                                            <Bar dataKey="current_amount" radius={[6, 6, 0, 0]}>
                                                {topTrends.map((item, index) => (
                                                    <Cell
                                                        key={item.category_name}
                                                        fill={item.category_color || CHART_COLORS[index % CHART_COLORS.length]}
                                                    />
                                                ))}
                                            </Bar>
                                        </BarChart>
                                    </ResponsiveContainer>
                                </div>
                            ) : (
                                <EmptyBlock message="Belum ada expense category trend pada periode ini." />
                            )}
                        </section>

                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <SectionHeader
                                title="Transaction Source Distribution"
                                description="Manual, CSV import, and Stellar transaction volume."
                            />

                            <div className="mt-5 space-y-4">
                                {sourceData.length > 0 ? (
                                    sourceData.map((item) => <SourceRow key={item.source} item={item} />)
                                ) : (
                                    <EmptyBlock message="Belum ada transaksi untuk source terpilih." />
                                )}
                            </div>
                        </section>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <SectionHeader
                                title="Budget Alert"
                                description="Usage, projection, and estimated exhaustion from active budgets."
                            />

                            <div className="mt-5 space-y-3">
                                {budgets && budgets.items.length > 0 ? (
                                    budgets.items.map((item) => (
                                        <BudgetRow key={item.budget_id} item={item} />
                                    ))
                                ) : (
                                    <EmptyBlock message="Belum ada budget aktif pada periode ini." />
                                )}
                            </div>
                        </section>

                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <SectionHeader
                                title="Unusual Activity"
                                description="Deterministic alerts with structured evidence and thresholds."
                            />

                            <div className="mt-5 space-y-3">
                                {anomalies && anomalies.items.length > 0 ? (
                                    anomalies.items.map((item, index) => (
                                        <AnomalyRow key={`${item.type}-${index}`} item={item} />
                                    ))
                                ) : (
                                    <EmptyBlock message="Tidak ada unusual activity yang valid pada periode ini." />
                                )}
                            </div>

                            {anomalies && anomalies.insufficient_history.length > 0 && (
                                <div className="mt-5 rounded-2xl bg-slate-50 p-4">
                                    <p className="text-sm font-semibold text-slate-800">
                                        Insufficient History
                                    </p>
                                    <div className="mt-3 space-y-2">
                                        {anomalies.insufficient_history.map((item) => (
                                            <p
                                                key={`${item.type}-${item.category_id ?? "all"}`}
                                                className="text-sm text-slate-600"
                                            >
                                                {humanize(item.type)} needs {item.required_sample_size} samples;
                                                available {item.available_sample_size}.
                                            </p>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </section>
                    </div>

                    <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <SectionHeader
                            title="Largest Transactions"
                            description="Sorted by effective transaction amount in the selected period."
                        />

                        <div className="mt-5 overflow-x-auto">
                            <table className="w-full text-left text-sm">
                                <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th className="px-4 py-3">Transaction</th>
                                        <th className="px-4 py-3">Source</th>
                                        <th className="px-4 py-3">Date</th>
                                        <th className="px-4 py-3 text-right">Amount</th>
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-slate-100">
                                    {summary.largest_transactions.length > 0 ? (
                                        summary.largest_transactions.map((transaction) => (
                                            <tr key={transaction.id}>
                                                <td className="px-4 py-3">
                                                    <p className="font-semibold text-slate-900">
                                                        {transaction.description ?? transaction.category_name ?? transaction.type}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {transaction.category_name ?? "Uncategorized"}
                                                    </p>
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {humanize(transaction.source)}
                                                </td>
                                                <td className="px-4 py-3 text-slate-600">
                                                    {formatDate(transaction.happened_at)}
                                                </td>
                                                <td className="px-4 py-3 text-right font-bold text-slate-900">
                                                    {formatCurrency(transaction.effective_amount)}
                                                </td>
                                            </tr>
                                        ))
                                    ) : (
                                        <tr>
                                            <td colSpan={4} className="px-4 py-10 text-center text-slate-500">
                                                Belum ada transaksi.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                    </section>
                </>
            )}
        </div>
    );
}

function comparisonText(change: { percentage_change: number | null; absolute_change: number; direction: string; status: string }) {
    if (change.status === "unavailable") return "No comparable activity";
    if (change.percentage_change === null) return `${humanize(change.direction)} by ${formatCurrency(change.absolute_change)}`;

    return `${humanize(change.direction)} ${formatPercent(change.percentage_change)}`;
}

function Field({
    label,
    htmlFor,
    children,
}: {
    label: string;
    htmlFor: string;
    children: React.ReactNode;
}) {
    return (
        <label htmlFor={htmlFor} className="block">
            <span className="mb-1 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                {label}
            </span>
            {children}
        </label>
    );
}

function SectionHeader({ title, description }: { title: string; description: string }) {
    return (
        <div>
            <h2 className="text-xl font-bold text-slate-950">{title}</h2>
            <p className="mt-1 text-sm text-slate-500">{description}</p>
        </div>
    );
}

function MetricCard({
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
        <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
            <div className="flex items-start justify-between">
                <p className="text-sm font-medium text-slate-500">{label}</p>
                <div className={`rounded-2xl p-3 ${iconClass}`}>{icon}</div>
            </div>
            <p className="mt-5 text-2xl font-bold tracking-tight text-slate-950">{value}</p>
            <p className="mt-2 text-sm text-slate-500">{detail}</p>
        </div>
    );
}

function SourceRow({ item }: { item: SourceDistributionItem }) {
    return (
        <div>
            <div className="mb-2 flex items-center justify-between gap-4 text-sm">
                <span className="font-semibold text-slate-800">{item.label}</span>
                <span className="text-slate-500">
                    {formatCurrency(item.amount)} / {item.count} tx
                </span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-slate-100">
                <div className="h-full rounded-full bg-blue-600" style={{ width: `${Math.min(item.share, 100)}%` }} />
            </div>
        </div>
    );
}

function BudgetRow({ item }: { item: BudgetAlertItem }) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <p className="font-semibold text-slate-900">{item.category_name}</p>
                    <p className="mt-1 text-sm text-slate-500">
                        {formatCurrency(item.amount_spent)} spent from {formatCurrency(item.budget_amount)}
                    </p>
                </div>
                <Badge label={humanize(item.severity)} className={severityClass(item.severity)} />
            </div>

            <div className="mt-4 h-2.5 overflow-hidden rounded-full bg-white">
                <div
                    className="h-full rounded-full bg-blue-600"
                    style={{ width: `${Math.min(item.usage_percentage, 100)}%` }}
                />
            </div>

            <div className="mt-3 grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                <span>{formatPercent(item.usage_percentage)} used</span>
                <span>{item.days_remaining} days left</span>
                <span>
                    Exhaustion: {item.estimated_budget_exhaustion_date ? formatDate(item.estimated_budget_exhaustion_date) : "not projected"}
                </span>
            </div>
        </div>
    );
}

function AnomalyRow({ item }: { item: AnomalyItem }) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-slate-50 p-4">
            <div className="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                <div>
                    <p className="font-semibold text-slate-900">{humanize(item.type)}</p>
                    <p className="mt-1 text-sm text-slate-500">{humanize(item.explanation_code)}</p>
                </div>
                <Badge label={humanize(item.severity)} className={severityClass(item.severity)} />
            </div>

            <div className="mt-3 grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                <span>Observed: {formatMetric(item.metric, item.observed_value)}</span>
                <span>Baseline: {formatMetric(item.metric, item.baseline_value)}</span>
                <span>Threshold: {formatMetric(item.metric, item.threshold_value)}</span>
            </div>
        </div>
    );
}

function InsightCard({
    title,
    description,
    badge,
    badgeClass,
    metricKeys,
    metrics,
}: {
    title: string;
    description: string;
    badge: string;
    badgeClass: string;
    metricKeys: string[];
    metrics: Record<string, SupportingMetric>;
}) {
    return (
        <div className="rounded-2xl border border-slate-100 bg-white p-4">
            <div className="flex items-start justify-between gap-3">
                <h3 className="font-bold text-slate-950">{title}</h3>
                <Badge label={badge} className={badgeClass} />
            </div>
            <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
            <SupportingMetricList metricKeys={metricKeys} metrics={metrics} />
        </div>
    );
}

function InsightAction({
    action,
    metrics,
}: {
    action: FinancialInsightAction;
    metrics: Record<string, SupportingMetric>;
}) {
    const metricKeys = action.related_metric ? [action.related_metric] : [];

    return (
        <div className="rounded-2xl border border-slate-100 bg-white p-4">
            <div className="flex items-start justify-between gap-3">
                <h4 className="font-semibold text-slate-950">{action.title}</h4>
                <Badge
                    label={translatePriority(action.priority)}
                    className={
                        action.priority === "high"
                            ? "bg-red-50 text-red-700 ring-red-100"
                            : action.priority === "medium"
                                ? "bg-amber-50 text-amber-700 ring-amber-100"
                                : "bg-blue-50 text-blue-700 ring-blue-100"
                    }
                />
            </div>
            <p className="mt-2 text-sm leading-6 text-slate-600">{action.description}</p>
            <SupportingMetricList metricKeys={metricKeys} metrics={metrics} />
        </div>
    );
}

function SupportingMetricList({
    metricKeys,
    metrics,
}: {
    metricKeys: string[];
    metrics: Record<string, SupportingMetric>;
}) {
    const visibleMetrics = metricKeys
        .map((key) => ({ key, metric: metrics[key] }))
        .filter((item): item is { key: string; metric: SupportingMetric } => Boolean(item.metric));

    if (visibleMetrics.length === 0) {
        return null;
    }

    return (
        <div className="mt-3 grid gap-2">
            {visibleMetrics.map(({ key, metric }) => (
                <div
                    key={key}
                    className="flex items-center justify-between gap-3 rounded-xl bg-slate-50 px-3 py-2 text-sm"
                >
                    <span className="text-slate-500">
                        {metric.label}
                        {metric.status && (
                            <span className="ml-1 text-xs text-slate-400">({metric.status})</span>
                        )}
                    </span>
                    <span className="font-semibold text-slate-900">
                        {formatSupportingMetric(metric)}
                    </span>
                </div>
            ))}
        </div>
    );
}

function formatSupportingMetric(metric: SupportingMetric) {
    if (metric.value === null || metric.value === undefined) {
        return metric.status ?? "N/A";
    }

    if (metric.value_type === "currency") {
        return formatCurrency(metric.value);
    }

    if (metric.value_type === "percentage") {
        return formatPercent(Number(metric.value));
    }

    return String(metric.value);
}

function formatMetric(metric: string, value: number) {
    if (metric.includes("amount") || metric.includes("cashflow") || metric.includes("expense")) {
        return formatCurrency(value);
    }

    if (metric.includes("percentage")) {
        return formatPercent(value);
    }

    return String(value);
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
        <div className="flex min-h-40 items-center justify-center rounded-2xl bg-slate-50 px-4 text-center text-sm text-slate-500">
            {message}
        </div>
    );
}

function StatePanel({
    icon,
    title,
    description,
    tone = "default",
}: {
    icon: React.ReactNode;
    title: string;
    description: string;
    tone?: "default" | "danger";
}) {
    const danger = tone === "danger";

    return (
        <div className={`flex min-h-64 flex-col items-center justify-center rounded-3xl p-8 text-center ring-1 ${danger ? "bg-red-50 text-red-700 ring-red-100" : "bg-white text-slate-600 ring-slate-100"}`}>
            <div className={`mb-4 rounded-2xl p-4 ${danger ? "bg-white" : "bg-blue-50 text-blue-700"}`}>
                {icon}
            </div>
            <h2 className={`text-lg font-bold ${danger ? "text-red-800" : "text-slate-950"}`}>
                {title}
            </h2>
            <p className="mt-2 max-w-xl text-sm">{description}</p>
        </div>
    );
}
