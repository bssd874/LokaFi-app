import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import {
    ArrowDownCircle,
    ArrowUpCircle,
    Loader2,
    Plus,
    Tags,
    Trash2,
} from "lucide-react";
import {
    createCategory,
    deleteCategory,
    getCategories,
} from "../features/categories/categoryApi";
import { getApiErrorMessage } from "../utils/apiError";
import type { Category, CategoryType } from "../types/category";

const defaultColors = {
    expense: "#EF4444",
    income: "#22C55E",
};

export function CategoriesPage() {
    const [categories, setCategories] = useState<Category[]>([]);
    const [activeType, setActiveType] = useState<CategoryType>("expense");
    const [loading, setLoading] = useState(true);
    const [submitting, setSubmitting] = useState(false);
    const [deletingId, setDeletingId] = useState<number | null>(null);
    const [error, setError] = useState("");

    const [name, setName] = useState("");
    const [icon, setIcon] = useState("tag");
    const [color, setColor] = useState(defaultColors.expense);

    const filteredCategories = useMemo(() => {
        return categories.filter((category) => category.type === activeType);
    }, [categories, activeType]);

    const incomeCount = useMemo(() => {
        return categories.filter((category) => category.type === "income").length;
    }, [categories]);

    const expenseCount = useMemo(() => {
        return categories.filter((category) => category.type === "expense").length;
    }, [categories]);

    async function fetchCategories() {
        try {
            setLoading(true);
            setError("");

            const data = await getCategories();
            setCategories(data);
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal mengambil data kategori"));
        } finally {
            setLoading(false);
        }
    }

    function handleChangeType(type: CategoryType) {
        setActiveType(type);
        setColor(defaultColors[type]);
    }

    async function handleCreateCategory(event: FormEvent) {
        event.preventDefault();

        if (!name.trim()) {
            setError("Nama kategori wajib diisi");
            return;
        }

        try {
            setSubmitting(true);
            setError("");

            await createCategory({
                name,
                type: activeType,
                icon,
                color,
            });

            setName("");
            setIcon("tag");
            setColor(defaultColors[activeType]);

            await fetchCategories();
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Gagal membuat kategori"));
        } finally {
            setSubmitting(false);
        }
    }

    async function handleDeleteCategory(category: Category) {
        const confirmed = window.confirm(
            `Yakin mau hapus kategori "${category.name}"?`
        );

        if (!confirmed) return;

        try {
            setDeletingId(category.id);
            setError("");

            await deleteCategory(category.id);
            await fetchCategories();
        } catch (err: unknown) {
            setError(
                getApiErrorMessage(
                    err,
                    "Gagal menghapus kategori. Pastikan kategori belum dipakai transaksi.",
                )
            );
        } finally {
            setDeletingId(null);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchCategories();
    }, []);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Categories
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Kelola kategori pemasukan dan pengeluaran agar laporan lebih rapi.
                    </p>
                </div>

                <div className="grid grid-cols-2 gap-3">
                    <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                        <p className="text-sm text-slate-500">Income Categories</p>
                        <p className="mt-1 text-2xl font-bold text-emerald-600">
                            {incomeCount}
                        </p>
                    </div>

                    <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                        <p className="text-sm text-slate-500">Expense Categories</p>
                        <p className="mt-1 text-2xl font-bold text-red-600">
                            {expenseCount}
                        </p>
                    </div>
                </div>
            </div>

            {error && (
                <div className="rounded-2xl border border-red-100 bg-red-50 px-5 py-4 text-sm text-red-700">
                    {error}
                </div>
            )}

            <div className="grid gap-6 xl:grid-cols-[1.4fr_1fr]">
                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5 flex flex-col justify-between gap-4 md:flex-row md:items-center">
                        <div>
                            <h2 className="text-xl font-bold text-slate-950">
                                Category List
                            </h2>
                            <p className="text-sm text-slate-500">
                                Pisahkan kategori income dan expense.
                            </p>
                        </div>

                        <div className="flex rounded-2xl bg-slate-100 p-1">
                            <button
                                onClick={() => handleChangeType("expense")}
                                className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${activeType === "expense"
                                        ? "bg-white text-red-600 shadow-sm"
                                        : "text-slate-500"
                                    }`}
                            >
                                Expense
                            </button>

                            <button
                                onClick={() => handleChangeType("income")}
                                className={`rounded-xl px-4 py-2 text-sm font-semibold transition ${activeType === "income"
                                        ? "bg-white text-emerald-600 shadow-sm"
                                        : "text-slate-500"
                                    }`}
                            >
                                Income
                            </button>
                        </div>
                    </div>

                    {loading ? (
                        <div className="flex min-h-[260px] items-center justify-center">
                            <div className="flex items-center gap-3 text-slate-500">
                                <Loader2 className="animate-spin" size={20} />
                                Loading categories...
                            </div>
                        </div>
                    ) : filteredCategories.length === 0 ? (
                        <div className="flex min-h-[260px] flex-col items-center justify-center rounded-2xl bg-slate-50 text-center">
                            <div className="mb-3 rounded-2xl bg-white p-4 text-blue-600 shadow-sm">
                                <Tags size={28} />
                            </div>
                            <h3 className="font-semibold text-slate-900">
                                Belum ada kategori {activeType}
                            </h3>
                            <p className="mt-1 text-sm text-slate-500">
                                Tambahkan kategori dari form di kanan.
                            </p>
                        </div>
                    ) : (
                        <div className="grid gap-4 md:grid-cols-2">
                            {filteredCategories.map((category) => (
                                <div
                                    key={category.id}
                                    className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="flex items-center gap-3">
                                            <div
                                                className="rounded-2xl p-3 text-white"
                                                style={{
                                                    backgroundColor:
                                                        category.color ||
                                                        defaultColors[category.type],
                                                }}
                                            >
                                                {category.type === "income" ? (
                                                    <ArrowDownCircle size={20} />
                                                ) : (
                                                    <ArrowUpCircle size={20} />
                                                )}
                                            </div>

                                            <div>
                                                <h3 className="font-bold text-slate-950">
                                                    {category.name}
                                                </h3>
                                                <p className="text-sm capitalize text-slate-500">
                                                    {category.type} · {category.icon || "tag"}
                                                </p>
                                            </div>
                                        </div>

                                        <button
                                            onClick={() => handleDeleteCategory(category)}
                                            disabled={deletingId === category.id}
                                            className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-50"
                                        >
                                            {deletingId === category.id ? (
                                                <Loader2 className="animate-spin" size={18} />
                                            ) : (
                                                <Trash2 size={18} />
                                            )}
                                        </button>
                                    </div>

                                    <div className="mt-5 flex items-center justify-between rounded-xl bg-white px-4 py-3 text-sm">
                                        <span className="text-slate-500">Color</span>
                                        <span className="flex items-center gap-2 font-semibold text-slate-700">
                                            <span
                                                className="h-3 w-3 rounded-full"
                                                style={{
                                                    backgroundColor:
                                                        category.color ||
                                                        defaultColors[category.type],
                                                }}
                                            />
                                            {category.color || defaultColors[category.type]}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </div>

                <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                    <div className="mb-5">
                        <h2 className="text-xl font-bold text-slate-950">Add Category</h2>
                        <p className="text-sm text-slate-500">
                            Kategori baru akan masuk ke tab aktif:{" "}
                            <span className="font-semibold capitalize text-slate-800">
                                {activeType}
                            </span>
                            .
                        </p>
                    </div>

                    <form onSubmit={handleCreateCategory} className="space-y-4">
                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Category Type
                            </label>
                            <select
                                value={activeType}
                                onChange={(event) =>
                                    handleChangeType(event.target.value as CategoryType)
                                }
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            >
                                <option value="expense">Expense</option>
                                <option value="income">Income</option>
                            </select>
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Category Name
                            </label>
                            <input
                                value={name}
                                onChange={(event) => setName(event.target.value)}
                                placeholder={
                                    activeType === "expense"
                                        ? "Contoh: Makanan"
                                        : "Contoh: Gaji"
                                }
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Icon Name
                            </label>
                            <input
                                value={icon}
                                onChange={(event) => setIcon(event.target.value)}
                                placeholder="wallet / utensils / tag"
                                className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                            />
                        </div>

                        <div>
                            <label className="text-sm font-semibold text-slate-700">
                                Color
                            </label>
                            <div className="mt-1 flex gap-3">
                                <input
                                    type="color"
                                    value={color}
                                    onChange={(event) => setColor(event.target.value)}
                                    className="h-11 w-16 rounded-xl border border-slate-200 bg-white p-1"
                                />
                                <input
                                    value={color}
                                    onChange={(event) => setColor(event.target.value)}
                                    className="w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>
                        </div>

                        <button
                            type="submit"
                            disabled={submitting}
                            className={`flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 font-semibold text-white disabled:opacity-60 ${activeType === "income"
                                    ? "bg-emerald-600 hover:bg-emerald-700"
                                    : "bg-red-600 hover:bg-red-700"
                                }`}
                        >
                            {submitting ? (
                                <>
                                    <Loader2 className="animate-spin" size={18} />
                                    Saving...
                                </>
                            ) : (
                                <>
                                    <Plus size={18} />
                                    Add {activeType === "income" ? "Income" : "Expense"} Category
                                </>
                            )}
                        </button>
                    </form>

                    <div className="mt-6 rounded-2xl bg-slate-50 p-4 text-sm text-slate-600">
                        <p className="font-semibold text-slate-800">Tips</p>
                        <p className="mt-1">
                            Hindari membuat kategori dengan nama sama berulang kali, supaya
                            chart dashboard tidak terpecah.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    );
}
