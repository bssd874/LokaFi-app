import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import {
    AlertTriangle,
    CheckCircle2,
    Database,
    FileText,
    Loader2,
    RefreshCw,
    ShieldCheck,
    Upload,
    XCircle,
} from "lucide-react";
import { getWallets } from "../features/wallets/walletApi";
import {
    commitTransactionImport,
    previewTransactionImport,
} from "../features/transactionImports/transactionImportApi";
import { getApiErrorMessage, getFirstValidationError } from "../utils/apiError";
import type { Wallet } from "../types/wallet";
import type {
    TransactionImportMapping,
    TransactionImportResult,
    TransactionImportRow,
    TransactionImportSourceType,
} from "../types/transactionImport";

const mappingFields: Array<{
    field: keyof TransactionImportMapping;
    label: string;
    required?: boolean;
    helper: string;
}> = [
    {
        field: "happened_at",
        label: "Transaction Date",
        required: true,
        helper: "Tanggal atau waktu transaksi.",
    },
    {
        field: "amount",
        label: "Signed Amount",
        helper: "Nominal tunggal. Negatif dibaca sebagai expense.",
    },
    {
        field: "debit_amount",
        label: "Debit Amount",
        helper: "Kolom uang keluar jika statement memisahkan debit/credit.",
    },
    {
        field: "credit_amount",
        label: "Credit Amount",
        helper: "Kolom uang masuk jika statement memisahkan debit/credit.",
    },
    {
        field: "type",
        label: "Type",
        helper: "Opsional: income/expense, debit/credit, masuk/keluar.",
    },
    {
        field: "description",
        label: "Description",
        helper: "Keterangan transaksi yang akan disanitasi.",
    },
    {
        field: "merchant",
        label: "Merchant",
        helper: "Nama merchant atau counterparty.",
    },
    {
        field: "reference_code",
        label: "Reference",
        helper: "Nomor referensi aman untuk pencarian.",
    },
    {
        field: "external_transaction_id",
        label: "External ID",
        helper: "ID transaksi statement jika tersedia.",
    },
    {
        field: "fee",
        label: "Fee",
        helper: "Biaya admin opsional.",
    },
    {
        field: "currency",
        label: "Currency",
        helper: "Default memakai currency wallet.",
    },
];

const sourceOptions: Array<{
    value: TransactionImportSourceType;
    label: string;
    description: string;
}> = [
    {
        value: "bank_csv",
        label: "Bank CSV",
        description: "Mutasi rekening dari file statement bank.",
    },
    {
        value: "ewallet_csv",
        label: "E-wallet CSV",
        description: "Riwayat transaksi dompet digital dari file statement.",
    },
];

function formatBytes(value: number) {
    if (value < 1024) return `${value} B`;
    if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
    return `${(value / 1024 / 1024).toFixed(1)} MB`;
}

function getStatusStyle(status: TransactionImportRow["status"]) {
    if (status === "imported") {
        return {
            label: "Imported",
            className: "bg-emerald-50 text-emerald-700",
            icon: <CheckCircle2 size={14} />,
        };
    }

    if (status === "duplicate") {
        return {
            label: "Duplicate",
            className: "bg-amber-50 text-amber-700",
            icon: <AlertTriangle size={14} />,
        };
    }

    if (status === "invalid" || status === "failed") {
        return {
            label: status === "invalid" ? "Invalid" : "Failed",
            className: "bg-red-50 text-red-700",
            icon: <XCircle size={14} />,
        };
    }

    return {
        label: "Pending",
        className: "bg-slate-100 text-slate-700",
        icon: <RefreshCw size={14} />,
    };
}

function mappedValue(row: TransactionImportRow, mapping: TransactionImportMapping, field: keyof TransactionImportMapping) {
    const column = mapping[field];
    if (!column) return "-";
    return row.raw_payload[column] || "-";
}

function isRequestTimeout(error: unknown) {
    return Boolean(
        error
        && typeof error === "object"
        && "code" in error
        && error.code === "ECONNABORTED",
    );
}

export function TransactionImportPage() {
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [sourceType, setSourceType] = useState<TransactionImportSourceType>("bank_csv");
    const [walletId, setWalletId] = useState("");
    const [providerCode, setProviderCode] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [mapping, setMapping] = useState<TransactionImportMapping>({});
    const [result, setResult] = useState<TransactionImportResult | null>(null);

    const [loadingWallets, setLoadingWallets] = useState(true);
    const [previewing, setPreviewing] = useState(false);
    const [committing, setCommitting] = useState(false);
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const importableWallets = useMemo(() => {
        if (sourceType === "bank_csv") {
            return wallets.filter((wallet) => wallet.type === "bank" || wallet.is_active);
        }

        return wallets.filter((wallet) => wallet.type === "ewallet" || wallet.is_active);
    }, [sourceType, wallets]);

    const hasAmountMapping = Boolean(
        mapping.amount || mapping.debit_amount || mapping.credit_amount,
    );
    const canCommit = Boolean(result?.batch.id && mapping.happened_at && hasAmountMapping);
    const resultRowsToReview = result?.rows.filter((row) => row.status !== "imported") ?? [];

    async function fetchWallets() {
        try {
            setLoadingWallets(true);
            setError("");
            setWallets(await getWallets());
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data wallet"));
        } finally {
            setLoadingWallets(false);
        }
    }

    async function handlePreview(event: FormEvent) {
        event.preventDefault();

        if (!walletId) {
            setError("Pilih wallet tujuan import terlebih dahulu.");
            return;
        }

        if (!file) {
            setError("Pilih file CSV statement terlebih dahulu.");
            return;
        }

        try {
            setPreviewing(true);
            setError("");
            setSuccessMessage("");

            const preview = await previewTransactionImport({
                source_type: sourceType,
                wallet_id: Number(walletId),
                provider_code: providerCode || undefined,
                file,
            });

            setResult(preview);
            setMapping(preview.batch.column_mapping ?? {});
            setSuccessMessage(
                preview.duplicate_file
                    ? "File ini sudah pernah dipreview atau diimport. Data batch lama ditampilkan."
                    : "Preview CSV berhasil dibuat. Cek mapping sebelum import.",
            );
        } catch (err: unknown) {
            setError(
                getFirstValidationError(err) ??
                getApiErrorMessage(err, "Gagal membuat preview CSV"),
            );
        } finally {
            setPreviewing(false);
        }
    }

    async function handleCommit() {
        if (!result) return;

        if (!mapping.happened_at || !hasAmountMapping) {
            setError("Mapping tanggal dan amount/debit/credit wajib diisi.");
            return;
        }

        try {
            setCommitting(true);
            setError("");
            setSuccessMessage("");

            const committed = await commitTransactionImport({
                batch_id: result.batch.id,
                mapping,
            });

            setResult(committed);
            setMapping(committed.batch.column_mapping ?? mapping);
            setSuccessMessage(
                committed.idempotent
                    ? "Batch ini sudah pernah diimport. Tidak ada transaksi baru dibuat."
                    : "Import CSV selesai diproses.",
            );
        } catch (err: unknown) {
            setError(
                isRequestTimeout(err)
                    ? "Import melewati batas waktu 90 detik. Data aman untuk dicoba ulang karena commit bersifat idempotent."
                    : getFirstValidationError(err) ??
                        getApiErrorMessage(err, "Gagal commit import CSV"),
            );
        } finally {
            setCommitting(false);
        }
    }

    function handleMappingChange(field: keyof TransactionImportMapping, value: string) {
        setMapping((current) => ({
            ...current,
            [field]: value || undefined,
        }));
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
                        Transaction Import
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Import mutasi bank atau e-wallet dari CSV tanpa login ke akun finansial.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">CSV Row Limit</p>
                    <p className="mt-1 text-2xl font-bold text-slate-950">500</p>
                </div>
            </div>

            <section className="rounded-3xl border border-blue-100 bg-blue-50 p-5 text-blue-800">
                <div className="flex items-start gap-3">
                    <ShieldCheck className="mt-0.5 shrink-0" size={20} />
                    <div>
                        <p className="font-semibold">Statement import only</p>
                        <p className="mt-1 text-sm">
                            Upload ini hanya membaca file CSV statement. LokaFi tidak meminta login bank,
                            PIN, OTP, password, token, recovery phrase, atau secret wallet. Deskripsi transaksi
                            disanitasi sebelum disimpan.
                        </p>
                    </div>
                </div>
            </section>

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

            <div className="grid gap-6 xl:grid-cols-[0.9fr_1.4fr]">
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5">
                        <h2 className="text-xl font-bold text-slate-950">Upload CSV</h2>
                        <p className="text-sm text-slate-500">
                            Pilih source, wallet tujuan, lalu upload CSV untuk preview.
                        </p>
                    </div>

                    <form onSubmit={handlePreview} className="space-y-5">
                        <div className="grid gap-3 sm:grid-cols-2">
                            {sourceOptions.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    onClick={() => setSourceType(option.value)}
                                    className={`rounded-2xl border p-4 text-left transition ${
                                        sourceType === option.value
                                            ? "border-blue-200 bg-blue-50 text-blue-800"
                                            : "border-slate-100 bg-slate-50 text-slate-700 hover:bg-white"
                                    }`}
                                >
                                    <p className="font-bold">{option.label}</p>
                                    <p className="mt-1 text-xs">{option.description}</p>
                                </button>
                            ))}
                        </div>

                        <Field label="Wallet">
                            <select
                                value={walletId}
                                onChange={(event) => setWalletId(event.target.value)}
                                disabled={loadingWallets}
                                className="w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500 disabled:opacity-60"
                            >
                                <option value="">Pilih wallet tujuan</option>
                                {importableWallets.map((wallet) => (
                                    <option key={wallet.id} value={wallet.id}>
                                        {wallet.name} - {wallet.type} - {wallet.currency}
                                    </option>
                                ))}
                            </select>
                        </Field>

                        <Field label="Provider Code">
                            <input
                                value={providerCode}
                                onChange={(event) => setProviderCode(event.target.value)}
                                placeholder="Opsional: bca, mandiri, ovo, gopay"
                                className="w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </Field>

                        <Field label="CSV File">
                            <label className="flex min-h-36 cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-slate-300 bg-slate-50 p-6 text-center hover:bg-white">
                                <Upload className="mb-3 text-blue-600" size={28} />
                                <span className="font-semibold text-slate-900">
                                    {file ? file.name : "Choose CSV file"}
                                </span>
                                <span className="mt-1 text-sm text-slate-500">
                                    {file ? formatBytes(file.size) : "Max 2 MB, max 500 rows"}
                                </span>
                                <input
                                    type="file"
                                    accept=".csv,text/csv,text/plain"
                                    onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                                    className="hidden"
                                />
                            </label>
                        </Field>

                        <button
                            type="submit"
                            disabled={previewing}
                            className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-blue-600 px-5 py-3 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                        >
                            {previewing ? (
                                <>
                                    <Loader2 className="animate-spin" size={18} />
                                    Creating preview...
                                </>
                            ) : (
                                <>
                                    <FileText size={18} />
                                    Preview CSV
                                </>
                            )}
                        </button>
                    </form>
                </section>

                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-3 md:flex-row md:items-center">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">Column Mapping</h2>
                            <p className="text-sm text-slate-500">
                                Cocokkan kolom CSV ke struktur transaksi LokaFi sebelum commit.
                            </p>
                        </div>

                        {result && (
                            <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                                {result.batch.original_filename} - {result.batch.total_rows} rows
                            </span>
                        )}
                    </div>

                    {!result ? (
                        <EmptyState
                            icon={<Database size={28} />}
                            title="Belum ada preview"
                            description="Upload CSV terlebih dahulu untuk melihat kolom dan mapping."
                        />
                    ) : (
                        <div className="space-y-6">
                            <div className="grid gap-3 md:grid-cols-2">
                                {mappingFields.map((item) => (
                                    <div key={item.field} className="rounded-2xl bg-slate-50 p-4">
                                        <div className="mb-2 flex items-center justify-between gap-3">
                                            <label className="text-sm font-bold text-slate-900">
                                                {item.label}
                                                {item.required && (
                                                    <span className="ml-1 text-red-500">*</span>
                                                )}
                                            </label>
                                        </div>
                                        <select
                                            value={mapping[item.field] ?? ""}
                                            onChange={(event) =>
                                                handleMappingChange(item.field, event.target.value)
                                            }
                                            className="w-full rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm outline-none focus:border-blue-500"
                                        >
                                            <option value="">Tidak dipakai</option>
                                            {result.batch.detected_columns.map((column) => (
                                                <option key={`${item.field}-${column}`} value={column}>
                                                    {column}
                                                </option>
                                            ))}
                                        </select>
                                        <p className="mt-2 text-xs text-slate-500">{item.helper}</p>
                                    </div>
                                ))}
                            </div>

                            {!hasAmountMapping && (
                                <div className="rounded-2xl border border-amber-100 bg-amber-50 p-4 text-sm text-amber-700">
                                    Mapping amount wajib memakai Signed Amount atau kolom Debit/Credit.
                                </div>
                            )}

                            <div>
                                <h3 className="mb-3 font-bold text-slate-950">
                                    Transaction Preview
                                </h3>
                                <div className="overflow-x-auto rounded-2xl border border-slate-100">
                                    <table className="min-w-full text-left text-sm">
                                        <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                            <tr>
                                                <th className="px-4 py-3">Row</th>
                                                <th className="px-4 py-3">Date</th>
                                                <th className="px-4 py-3">Description</th>
                                                <th className="px-4 py-3">Merchant</th>
                                                <th className="px-4 py-3">Amount</th>
                                                <th className="px-4 py-3">Type</th>
                                            </tr>
                                        </thead>
                                        <tbody className="divide-y divide-slate-100">
                                            {result.preview_rows.map((row) => (
                                                <tr key={row.id}>
                                                    <td className="px-4 py-3 font-semibold text-slate-700">
                                                        {row.row_number}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        {mappedValue(row, mapping, "happened_at")}
                                                    </td>
                                                    <td className="max-w-xs truncate px-4 py-3 text-slate-600">
                                                        {mappedValue(row, mapping, "description")}
                                                    </td>
                                                    <td className="max-w-xs truncate px-4 py-3 text-slate-600">
                                                        {mappedValue(row, mapping, "merchant")}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        {mappedValue(row, mapping, "amount") !== "-"
                                                            ? mappedValue(row, mapping, "amount")
                                                            : `${mappedValue(row, mapping, "debit_amount")} / ${mappedValue(
                                                                row,
                                                                mapping,
                                                                "credit_amount",
                                                            )}`}
                                                    </td>
                                                    <td className="px-4 py-3 text-slate-600">
                                                        {mappedValue(row, mapping, "type")}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            </div>

                            <button
                                type="button"
                                onClick={handleCommit}
                                disabled={!canCommit || committing || result.batch.status === "imported"}
                                className="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-5 py-3 font-semibold text-white hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                {committing ? (
                                    <>
                                        <Loader2 className="animate-spin" size={18} />
                                        Importing...
                                    </>
                                ) : result.batch.status === "imported" ? (
                                    <>
                                        <CheckCircle2 size={18} />
                                        Batch Imported
                                    </>
                                ) : (
                                    <>
                                        <Upload size={18} />
                                        Confirm Import
                                    </>
                                )}
                            </button>
                        </div>
                    )}
                </section>
            </div>

            {result && (
                <section className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">Import Result</h2>
                            <p className="text-sm text-slate-500">
                                Ringkasan batch, duplikat, dan row yang perlu diperiksa.
                            </p>
                        </div>

                        <span className="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-600">
                            {result.batch.status}
                        </span>
                    </div>

                    <div className="grid gap-4 md:grid-cols-5">
                        <Metric label="Total Rows" value={result.summary.total_rows} />
                        <Metric label="Imported" value={result.summary.imported_count} tone="success" />
                        <Metric label="Duplicate" value={result.summary.duplicate_count} tone="warning" />
                        <Metric label="Invalid" value={result.summary.invalid_count} tone="danger" />
                        <Metric label="Failed" value={result.summary.failed_count} tone="danger" />
                    </div>

                    <div className="mt-6 overflow-x-auto rounded-2xl border border-slate-100">
                        <table className="min-w-full text-left text-sm">
                            <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th className="px-4 py-3">Row</th>
                                    <th className="px-4 py-3">Status</th>
                                    <th className="px-4 py-3">Message</th>
                                    <th className="px-4 py-3">External ID</th>
                                    <th className="px-4 py-3">Description</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-slate-100">
                                {(resultRowsToReview.length > 0 ? resultRowsToReview : result.rows).map((row) => {
                                    const status = getStatusStyle(row.status);

                                    return (
                                        <tr key={row.id}>
                                            <td className="px-4 py-3 font-semibold text-slate-700">
                                                {row.row_number}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-bold ${status.className}`}
                                                >
                                                    {status.icon}
                                                    {status.label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {row.error_message ?? "-"}
                                            </td>
                                            <td className="px-4 py-3 text-slate-600">
                                                {row.external_transaction_id ?? "-"}
                                            </td>
                                            <td className="max-w-md truncate px-4 py-3 text-slate-600">
                                                {row.normalized_payload?.sanitized_description
                                                    ?? row.raw_payload.description
                                                    ?? row.raw_payload.keterangan
                                                    ?? "-"}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </div>
    );
}

function Field({ label, children }: { label: string; children: React.ReactNode }) {
    return (
        <div>
            <label className="text-sm font-semibold text-slate-700">{label}</label>
            <div className="mt-1">{children}</div>
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
        <div className="flex min-h-96 flex-col items-center justify-center rounded-2xl bg-slate-50 p-6 text-center">
            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                {icon}
            </div>
            <h3 className="font-semibold text-slate-900">{title}</h3>
            <p className="mt-1 max-w-sm text-sm text-slate-500">{description}</p>
        </div>
    );
}

function Metric({
    label,
    value,
    tone = "neutral",
}: {
    label: string;
    value: number;
    tone?: "neutral" | "success" | "warning" | "danger";
}) {
    const className =
        tone === "success"
            ? "text-emerald-600"
            : tone === "warning"
                ? "text-amber-600"
                : tone === "danger"
                    ? "text-red-600"
                    : "text-slate-950";

    return (
        <div className="rounded-2xl bg-slate-50 p-5">
            <p className="text-sm text-slate-500">{label}</p>
            <p className={`mt-1 text-2xl font-bold ${className}`}>{value}</p>
        </div>
    );
}
