import { useEffect, useState } from "react";
import {
    BarChart3,
    Database,
    Download,
    Loader2,
    ShieldCheck,
    Tags,
} from "lucide-react";
import { exportDatasetCsv, getDatasetSummary } from "../features/dataset/datasetApi";
import { getApiErrorMessage } from "../utils/apiError";
import type { DatasetSummary } from "../types/dataset";

function formatNumber(value: number) {
    return new Intl.NumberFormat("id-ID").format(value);
}

export function DatasetManagementPage() {
    const [summary, setSummary] = useState<DatasetSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const [exporting, setExporting] = useState(false);
    const [error, setError] = useState("");

    async function fetchSummary() {
        try {
            setLoading(true);
            setError("");

            setSummary(await getDatasetSummary());
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil ringkasan dataset"));
        } finally {
            setLoading(false);
        }
    }

    async function handleExport() {
        try {
            setExporting(true);
            setError("");

            const blob = await exportDatasetCsv();
            const url = window.URL.createObjectURL(blob);
            const link = document.createElement("a");
            link.href = url;
            link.download = `transaction_dataset_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(link);
            link.click();
            link.remove();
            window.URL.revokeObjectURL(url);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal export dataset CSV"));
        } finally {
            setExporting(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchSummary();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Dataset Management
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Pantau label transaksi manual dan export CSV terverifikasi untuk training notebook.
                    </p>
                </div>

                <button
                    onClick={handleExport}
                    disabled={exporting || loading || !summary?.total_verified}
                    className="inline-flex items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 text-sm font-semibold text-white hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
                >
                    {exporting ? (
                        <Loader2 className="animate-spin" size={18} />
                    ) : (
                        <Download size={18} />
                    )}
                    Export Dataset CSV
                </button>
            </div>

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            {loading ? (
                <div className="flex min-h-[360px] items-center justify-center rounded-3xl bg-white shadow-sm ring-1 ring-slate-100">
                    <div className="flex items-center gap-3 text-slate-500">
                        <Loader2 className="animate-spin" size={20} />
                        Loading dataset summary...
                    </div>
                </div>
            ) : !summary ? (
                <div className="rounded-3xl bg-white p-8 text-center shadow-sm ring-1 ring-slate-100">
                    <p className="font-semibold text-slate-900">Dataset belum tersedia</p>
                    <p className="mt-1 text-sm text-slate-500">
                        Kategorikan transaksi terlebih dahulu untuk membentuk dataset.
                    </p>
                </div>
            ) : (
                <>
                    <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                        <MetricCard
                            icon={<Database size={20} />}
                            label="Total Transaksi"
                            value={summary.total_transactions}
                        />
                        <MetricCard
                            icon={<Tags size={20} />}
                            label="Data Berlabel"
                            value={summary.total_labeled}
                        />
                        <MetricCard
                            icon={<BarChart3 size={20} />}
                            label="Belum Dikategorikan"
                            value={summary.total_unclassified}
                        />
                        <MetricCard
                            icon={<ShieldCheck size={20} />}
                            label="Terverifikasi"
                            value={summary.total_verified}
                        />
                        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-100">
                            <p className="text-sm text-slate-500">Kelengkapan Dataset</p>
                            <p className="mt-2 text-3xl font-bold text-blue-600">
                                {summary.label_completion_percentage}%
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <h2 className="text-xl font-bold text-slate-950">
                                Data Per Kategori
                            </h2>
                            <div className="mt-5 space-y-3">
                                {summary.per_category.length === 0 ? (
                                    <EmptyLine text="Belum ada label kategori." />
                                ) : (
                                    summary.per_category.map((item) => (
                                        <SummaryRow
                                            key={`${item.category_id}-${item.category_name}`}
                                            label={item.category_name}
                                            meta={item.category_type ?? ""}
                                            value={item.total}
                                        />
                                    ))
                                )}
                            </div>
                        </section>

                        <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                            <h2 className="text-xl font-bold text-slate-950">
                                Data Berdasarkan Source
                            </h2>
                            <div className="mt-5 space-y-3">
                                {summary.by_source.length === 0 ? (
                                    <EmptyLine text="Belum ada source dataset." />
                                ) : (
                                    summary.by_source.map((item) => (
                                        <SummaryRow
                                            key={item.source}
                                            label={item.source}
                                            meta="source"
                                            value={item.total}
                                        />
                                    ))
                                )}
                            </div>
                        </section>
                    </div>
                </>
            )}
        </div>
    );
}

function MetricCard({
    icon,
    label,
    value,
}: {
    icon: React.ReactNode;
    label: string;
    value: number;
}) {
    return (
        <div className="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-100">
            <div className="mb-4 inline-flex rounded-xl bg-blue-50 p-2 text-blue-600">
                {icon}
            </div>
            <p className="text-sm text-slate-500">{label}</p>
            <p className="mt-1 text-2xl font-bold text-slate-950">
                {formatNumber(value)}
            </p>
        </div>
    );
}

function SummaryRow({
    label,
    meta,
    value,
}: {
    label: string;
    meta: string;
    value: number;
}) {
    return (
        <div className="flex items-center justify-between gap-4 rounded-2xl bg-slate-50 px-4 py-3">
            <div>
                <p className="font-semibold text-slate-900">{label}</p>
                <p className="text-xs capitalize text-slate-500">{meta}</p>
            </div>
            <span className="rounded-full bg-white px-3 py-1 text-sm font-bold text-slate-700 shadow-sm">
                {formatNumber(value)}
            </span>
        </div>
    );
}

function EmptyLine({ text }: { text: string }) {
    return (
        <div className="rounded-2xl bg-slate-50 px-4 py-6 text-center text-sm text-slate-500">
            {text}
        </div>
    );
}
