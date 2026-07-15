import { useEffect, useMemo, useState } from "react";
import type { FormEvent, ReactNode } from "react";
import { Link } from "react-router-dom";
import {
    AlertTriangle,
    ArrowDownCircle,
    ArrowUpCircle,
    BarChart3,
    Loader2,
    Plus,
    Search,
    Star,
    Trash2,
    TrendingDown,
    TrendingUp,
    WalletCards,
} from "lucide-react";
import {
    addWatchlist,
    createInvestmentOrder,
    getAssets,
    getInvestmentOrders,
    getPortfolio,
    getWatchlists,
    removeWatchlist,
} from "../features/investments/investmentApi";
import { getWallets } from "../features/wallets/walletApi";
import type {
    Asset,
    AssetType,
    InvestmentOrder,
    InvestmentOrderType,
    PortfolioHolding,
    PortfolioSummary,
    Watchlist,
} from "../types/investment";
import type { Wallet } from "../types/wallet";

interface ApiErrorResponse {
    response?: {
        data?: {
            message?: string;
        };
    };
}

function getErrorMessage(err: unknown) {
    if (err && typeof err === "object" && "response" in err) {
        const apiError = err as ApiErrorResponse;
        return apiError.response?.data?.message;
    }

    return undefined;
}

function formatCurrency(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        style: "currency",
        currency: "IDR",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function formatNumber(value: number | string) {
    return new Intl.NumberFormat("id-ID", {
        maximumFractionDigits: 8,
    }).format(Number(value ?? 0));
}

function formatDateTime(date: string) {
    return new Intl.DateTimeFormat("id-ID", {
        day: "2-digit",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
    }).format(new Date(date));
}

function getAssetTypeLabel(type: AssetType) {
    const labels: Record<AssetType, string> = {
        us_stock: "US Stock",
        idx_stock: "IDX Stock",
        crypto: "Crypto",
        forex: "Forex",
        gold: "Gold",
        mutual_fund: "Mutual Fund",
    };

    return labels[type];
}

function getCurrentDateTimeLocal() {
    const date = new Date();
    const offset = date.getTimezoneOffset();
    const localDate = new Date(date.getTime() - offset * 60 * 1000);
    return localDate.toISOString().slice(0, 16);
}

const emptySummary: PortfolioSummary = {
    investment_cash_balance: 0,
    total_portfolio_value: 0,
    total_cost_basis: 0,
    total_unrealized_profit_loss: 0,
    total_unrealized_profit_loss_percentage: 0,
    total_assets: 0,
    total_equity: 0,
};

export function InvestmentPage() {
    const [assets, setAssets] = useState<Asset[]>([]);
    const [watchlists, setWatchlists] = useState<Watchlist[]>([]);
    const [orders, setOrders] = useState<InvestmentOrder[]>([]);
    const [wallets, setWallets] = useState<Wallet[]>([]);
    const [summary, setSummary] = useState<PortfolioSummary>(emptySummary);
    const [holdings, setHoldings] = useState<PortfolioHolding[]>([]);

    const [loading, setLoading] = useState(true);
    const [submittingOrder, setSubmittingOrder] = useState(false);
    const [watchlistLoadingId, setWatchlistLoadingId] = useState<number | null>(
        null,
    );
    const [error, setError] = useState("");
    const [successMessage, setSuccessMessage] = useState("");

    const [search, setSearch] = useState("");
    const [assetType, setAssetType] = useState<AssetType | "">("");

    const [orderType, setOrderType] = useState<InvestmentOrderType>("buy");
    const [assetId, setAssetId] = useState("");
    const [walletId, setWalletId] = useState("");
    const [quantity, setQuantity] = useState("");
    const [price, setPrice] = useState("");
    const [fee, setFee] = useState("0");
    const [note, setNote] = useState("");
    const [orderedAt, setOrderedAt] = useState(getCurrentDateTimeLocal());

    const investmentWallets = useMemo(() => {
        return wallets.filter((wallet) => wallet.type === "investment_cash");
    }, [wallets]);

    const hasInvestmentCashWallet = investmentWallets.length > 0;

    const selectedAsset = useMemo(() => {
        return assets.find((asset) => String(asset.id) === assetId);
    }, [assets, assetId]);

    const watchlistAssetIds = useMemo(() => {
        return new Set(watchlists.map((watchlist) => watchlist.asset_id));
    }, [watchlists]);

    const estimatedGross = useMemo(() => {
        const selectedPrice = Number(price || selectedAsset?.current_price || 0);
        return Number(quantity || 0) * selectedPrice;
    }, [price, quantity, selectedAsset]);

    const estimatedNet = useMemo(() => {
        const orderFee = Number(fee || 0);

        if (orderType === "buy") {
            return estimatedGross + orderFee;
        }

        return estimatedGross - orderFee;
    }, [estimatedGross, fee, orderType]);

    async function fetchData() {
        try {
            setLoading(true);
            setError("");

            const [assetData, watchlistData, orderPage, portfolioData, walletData] =
                await Promise.all([
                    getAssets({
                        search: search || undefined,
                        asset_type: assetType || undefined,
                    }),
                    getWatchlists(),
                    getInvestmentOrders(),
                    getPortfolio(),
                    getWallets(),
                ]);

            setAssets(assetData);
            setWatchlists(watchlistData);
            setOrders(orderPage.data);
            setSummary(portfolioData.summary);
            setHoldings(portfolioData.holdings);
            setWallets(walletData);
        } catch (err: unknown) {
            setError(getErrorMessage(err) ?? "Gagal mengambil data investasi");
        } finally {
            setLoading(false);
        }
    }

    async function refreshAfterAction() {
        const [watchlistData, orderPage, portfolioData, walletData] =
            await Promise.all([
                getWatchlists(),
                getInvestmentOrders(),
                getPortfolio(),
                getWallets(),
            ]);

        setWatchlists(watchlistData);
        setOrders(orderPage.data);
        setSummary(portfolioData.summary);
        setHoldings(portfolioData.holdings);
        setWallets(walletData);
    }

    async function refreshAssetsOnly() {
        try {
            const assetData = await getAssets({
                search: search || undefined,
                asset_type: assetType || undefined,
            });

            setAssets(assetData);
        } catch (err: unknown) {
            setError(getErrorMessage(err) ?? "Gagal refresh asset");
        }
    }

    function handleSelectAsset(id: string) {
        setAssetId(id);

        const asset = assets.find((item) => String(item.id) === id);

        if (asset) {
            setPrice(String(Number(asset.current_price)));
        }
    }

    async function handleToggleWatchlist(asset: Asset) {
        try {
            setWatchlistLoadingId(asset.id);
            setError("");
            setSuccessMessage("");

            const existingWatchlist = watchlists.find(
                (watchlist) => watchlist.asset_id === asset.id,
            );

            if (existingWatchlist) {
                await removeWatchlist(existingWatchlist.id);
                setSuccessMessage(`${asset.symbol} dihapus dari watchlist.`);
            } else {
                await addWatchlist(asset.id);
                setSuccessMessage(`${asset.symbol} ditambahkan ke watchlist.`);
            }

            await refreshAfterAction();
        } catch (err: unknown) {
            setError(getErrorMessage(err) ?? "Gagal update watchlist");
        } finally {
            setWatchlistLoadingId(null);
        }
    }

    async function handleSubmitOrder(event: FormEvent) {
        event.preventDefault();

        if (!assetId || !walletId) {
            setError("Asset dan investment wallet wajib dipilih");
            return;
        }

        if (!quantity || Number(quantity) <= 0) {
            setError("Quantity wajib lebih dari 0");
            return;
        }

        try {
            setSubmittingOrder(true);
            setError("");
            setSuccessMessage("");

            const order = await createInvestmentOrder({
                type: orderType,
                asset_id: Number(assetId),
                wallet_id: Number(walletId),
                quantity: Number(quantity),
                price: Number(price || selectedAsset?.current_price || 0),
                fee: Number(fee || 0),
                note,
                ordered_at: orderedAt.replace("T", " ") + ":00",
            });

            setSuccessMessage(
                `${order.type.toUpperCase()} ${order.asset.symbol} berhasil dieksekusi dalam mode simulasi.`,
            );

            setQuantity("");
            setFee("0");
            setNote("");
            setOrderedAt(getCurrentDateTimeLocal());

            await refreshAfterAction();
        } catch (err: unknown) {
            setError(getErrorMessage(err) ?? "Gagal membuat order investasi");
        } finally {
            setSubmittingOrder(false);
        }
    }

    useEffect(() => {
        // eslint-disable-next-line react-hooks/set-state-in-effect
        fetchData();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        const timeout = setTimeout(() => {
            refreshAssetsOnly();
        }, 350);

        return () => clearTimeout(timeout);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search, assetType]);

    return (
        <div className="space-y-7">
            <div className="flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                <div>
                    <h1 className="text-3xl font-bold tracking-tight text-slate-950">
                        Investments
                    </h1>
                    <p className="mt-1 text-slate-500">
                        Portfolio simulator untuk buy/sell asset tanpa transaksi real.
                    </p>
                </div>

                <div className="rounded-2xl bg-white px-5 py-4 shadow-sm ring-1 ring-slate-100">
                    <p className="text-sm text-slate-500">Simulation Mode</p>
                    <p className="mt-1 font-bold text-blue-600">Paper / Dummy Trading</p>
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

            {!loading && !hasInvestmentCashWallet && (
                <div className="flex items-start gap-3 rounded-2xl border border-amber-100 bg-amber-50 px-5 py-4 text-sm text-amber-800">
                    <AlertTriangle className="mt-0.5 shrink-0" size={18} />
                    <div>
                        <p className="font-semibold">Investment cash wallet belum tersedia.</p>
                        <p className="mt-1">
                            Buat wallet dengan type <b>Investment Cash</b> di{" "}
                            <Link to="/wallets" className="font-semibold text-amber-900 underline">
                                halaman Wallets
                            </Link>{" "}
                            sebelum menjalankan simulasi buy/sell.
                        </p>
                    </div>
                </div>
            )}

            <div className="grid gap-5 md:grid-cols-4">
                <SummaryCard
                    label="Total Equity"
                    value={formatCurrency(summary.total_equity)}
                    icon={<WalletCards size={22} />}
                    iconClass="bg-blue-100 text-blue-700"
                />

                <SummaryCard
                    label="Portfolio Value"
                    value={formatCurrency(summary.total_portfolio_value)}
                    icon={<BarChart3 size={22} />}
                    iconClass="bg-indigo-100 text-indigo-700"
                />

                <SummaryCard
                    label="Investment Cash"
                    value={formatCurrency(summary.investment_cash_balance)}
                    icon={<WalletCards size={22} />}
                    iconClass="bg-slate-100 text-slate-700"
                />

                <SummaryCard
                    label="Unrealized P/L"
                    value={formatCurrency(summary.total_unrealized_profit_loss)}
                    icon={
                        summary.total_unrealized_profit_loss >= 0 ? (
                            <TrendingUp size={22} />
                        ) : (
                            <TrendingDown size={22} />
                        )
                    }
                    iconClass={
                        summary.total_unrealized_profit_loss >= 0
                            ? "bg-emerald-100 text-emerald-700"
                            : "bg-red-100 text-red-700"
                    }
                    subValue={`${summary.total_unrealized_profit_loss_percentage}%`}
                />
            </div>

            <div className="grid gap-6 xl:grid-cols-[1.5fr_1fr]">
                <div className="space-y-6">
                    <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5 flex flex-col justify-between gap-4 lg:flex-row lg:items-center">
                            <div>
                                <h2 className="text-xl font-bold text-slate-950">
                                    Market Assets
                                </h2>
                                <p className="text-sm text-slate-500">
                                    Asset dummy untuk simulasi investasi.
                                </p>
                            </div>

                            <div className="flex flex-col gap-3 md:flex-row">
                                <div className="relative">
                                    <Search
                                        size={16}
                                        className="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400"
                                    />
                                    <input
                                        value={search}
                                        onChange={(event) => setSearch(event.target.value)}
                                        placeholder="Search asset..."
                                        className="w-full rounded-xl border border-slate-200 py-2 pl-9 pr-3 text-sm outline-none focus:border-blue-500 md:w-56"
                                    />
                                </div>

                                <select
                                    value={assetType}
                                    onChange={(event) =>
                                        setAssetType(event.target.value as AssetType | "")
                                    }
                                    className="rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none focus:border-blue-500"
                                >
                                    <option value="">All Type</option>
                                    <option value="us_stock">US Stock</option>
                                    <option value="idx_stock">IDX Stock</option>
                                    <option value="crypto">Crypto</option>
                                    <option value="forex">Forex</option>
                                    <option value="gold">Gold</option>
                                    <option value="mutual_fund">Mutual Fund</option>
                                </select>
                            </div>
                        </div>

                        {loading ? (
                            <LoadingState text="Loading investment data..." />
                        ) : assets.length === 0 ? (
                            <EmptyState text="Belum ada asset. Jalankan AssetSeeder dulu." />
                        ) : (
                            <div className="grid gap-4 md:grid-cols-2">
                                {assets.map((asset) => {
                                    const isWatchlisted = watchlistAssetIds.has(asset.id);
                                    const priceChange = Number(asset.price_change_percentage);

                                    return (
                                        <div
                                            key={asset.id}
                                            className="rounded-2xl border border-slate-100 bg-slate-50 p-5 transition hover:-translate-y-0.5 hover:bg-white hover:shadow-md"
                                        >
                                            <div className="flex items-start justify-between gap-3">
                                                <div>
                                                    <div className="flex items-center gap-2">
                                                        <h3 className="text-lg font-bold text-slate-950">
                                                            {asset.symbol}
                                                        </h3>
                                                        <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-bold text-blue-700">
                                                            {getAssetTypeLabel(asset.asset_type)}
                                                        </span>
                                                    </div>

                                                    <p className="mt-1 text-sm text-slate-500">
                                                        {asset.name}
                                                    </p>

                                                    <p className="mt-1 text-xs text-slate-400">
                                                        {asset.exchange ?? "-"}
                                                    </p>
                                                </div>

                                                <button
                                                    onClick={() => handleToggleWatchlist(asset)}
                                                    disabled={watchlistLoadingId === asset.id}
                                                    className={`rounded-xl p-2 ${isWatchlisted
                                                            ? "bg-yellow-50 text-yellow-600"
                                                            : "bg-white text-slate-400 hover:text-yellow-600"
                                                        }`}
                                                >
                                                    {watchlistLoadingId === asset.id ? (
                                                        <Loader2 className="animate-spin" size={18} />
                                                    ) : (
                                                        <Star
                                                            size={18}
                                                            fill={isWatchlisted ? "currentColor" : "none"}
                                                        />
                                                    )}
                                                </button>
                                            </div>

                                            <div className="mt-5 flex items-end justify-between">
                                                <div>
                                                    <p className="text-xs text-slate-500">
                                                        Current Price
                                                    </p>
                                                    <p className="mt-1 text-xl font-bold text-slate-950">
                                                        {formatCurrency(asset.current_price)}
                                                    </p>
                                                </div>

                                                <p
                                                    className={`rounded-full px-3 py-1 text-sm font-bold ${priceChange >= 0
                                                            ? "bg-emerald-50 text-emerald-700"
                                                            : "bg-red-50 text-red-700"
                                                        }`}
                                                >
                                                    {priceChange >= 0 ? "+" : ""}
                                                    {priceChange}%
                                                </p>
                                            </div>

                                            <button
                                                onClick={() => handleSelectAsset(String(asset.id))}
                                                className="mt-5 w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700"
                                            >
                                                Trade {asset.symbol}
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">
                                Portfolio Holdings
                            </h2>
                            <p className="text-sm text-slate-500">
                                Asset yang sedang dimiliki dari hasil buy/sell simulasi.
                            </p>
                        </div>

                        {loading ? (
                            <LoadingState text="Loading holdings..." />
                        ) : holdings.length === 0 ? (
                            <EmptyState text="Belum ada holding. Coba lakukan buy asset dulu." />
                        ) : (
                            <div className="overflow-x-auto rounded-2xl border border-slate-100">
                                <table className="min-w-[720px] w-full text-left text-sm">
                                    <thead className="bg-slate-50 text-xs uppercase tracking-wider text-slate-500">
                                        <tr>
                                            <th className="px-4 py-3">Asset</th>
                                            <th className="px-4 py-3">Quantity</th>
                                            <th className="px-4 py-3">Avg Price</th>
                                            <th className="px-4 py-3">Current Value</th>
                                            <th className="px-4 py-3 text-right">P/L</th>
                                        </tr>
                                    </thead>

                                    <tbody className="divide-y divide-slate-100">
                                        {holdings.map((holding) => (
                                            <tr key={holding.asset_id} className="hover:bg-slate-50">
                                                <td className="px-4 py-4">
                                                    <p className="font-bold text-slate-950">
                                                        {holding.symbol}
                                                    </p>
                                                    <p className="text-xs text-slate-500">
                                                        {holding.name}
                                                    </p>
                                                </td>

                                                <td className="px-4 py-4 text-slate-600">
                                                    {formatNumber(holding.quantity)}
                                                </td>

                                                <td className="px-4 py-4 text-slate-600">
                                                    {formatCurrency(holding.average_price)}
                                                </td>

                                                <td className="px-4 py-4 font-semibold text-slate-900">
                                                    {formatCurrency(holding.current_value)}
                                                </td>

                                                <td
                                                    className={`px-4 py-4 text-right font-bold ${holding.unrealized_profit_loss >= 0
                                                            ? "text-emerald-600"
                                                            : "text-red-600"
                                                        }`}
                                                >
                                                    {formatCurrency(holding.unrealized_profit_loss)}
                                                    <p className="text-xs">
                                                        {holding.unrealized_profit_loss_percentage}%
                                                    </p>
                                                </td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>

                    <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">
                                Recent Orders
                            </h2>
                            <p className="text-sm text-slate-500">
                                Riwayat order investasi simulasi.
                            </p>
                        </div>

                        {loading ? (
                            <LoadingState text="Loading recent orders..." />
                        ) : orders.length === 0 ? (
                            <EmptyState text="Belum ada order investasi." />
                        ) : (
                            <div className="space-y-3">
                                {orders.map((order) => (
                                    <div
                                        key={order.id}
                                        className="flex items-center justify-between gap-4 rounded-2xl border border-slate-100 bg-slate-50 p-4"
                                    >
                                        <div className="flex items-center gap-3">
                                            <div
                                                className={`rounded-xl p-3 ${order.type === "buy"
                                                        ? "bg-emerald-50 text-emerald-700"
                                                        : "bg-red-50 text-red-700"
                                                    }`}
                                            >
                                                {order.type === "buy" ? (
                                                    <ArrowDownCircle size={20} />
                                                ) : (
                                                    <ArrowUpCircle size={20} />
                                                )}
                                            </div>

                                            <div>
                                                <p className="font-bold text-slate-950">
                                                    {order.type.toUpperCase()} {order.asset.symbol}
                                                </p>
                                                <p className="text-xs text-slate-500">
                                                    {formatNumber(order.quantity)} ×{" "}
                                                    {formatCurrency(order.price)}
                                                </p>
                                                <p className="text-xs text-slate-400">
                                                    {formatDateTime(order.ordered_at)}
                                                </p>
                                            </div>
                                        </div>

                                        <div className="text-right">
                                            <p className="font-bold text-slate-950">
                                                {formatCurrency(order.net_amount)}
                                            </p>
                                            <p className="text-xs capitalize text-slate-500">
                                                {order.mode}
                                            </p>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                <div className="space-y-6">
                    <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <div className="mb-5">
                            <h2 className="text-xl font-bold text-slate-950">Place Order</h2>
                            <p className="text-sm text-slate-500">
                                Simulasi buy/sell menggunakan investment wallet.
                            </p>
                        </div>

                        <div className="mb-5 grid grid-cols-2 gap-2 rounded-2xl bg-slate-100 p-1">
                            <button
                                type="button"
                                onClick={() => setOrderType("buy")}
                                className={`rounded-xl px-3 py-2 text-sm font-semibold ${orderType === "buy"
                                        ? "bg-white text-emerald-600 shadow-sm"
                                        : "text-slate-500"
                                    }`}
                            >
                                Buy
                            </button>

                            <button
                                type="button"
                                onClick={() => setOrderType("sell")}
                                className={`rounded-xl px-3 py-2 text-sm font-semibold ${orderType === "sell"
                                        ? "bg-white text-red-600 shadow-sm"
                                        : "text-slate-500"
                                    }`}
                            >
                                Sell
                            </button>
                        </div>

                        <form onSubmit={handleSubmitOrder} className="space-y-4">
                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Investment Wallet
                                </label>
                                <select
                                    value={walletId}
                                    onChange={(event) => setWalletId(event.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                >
                                    <option value="">Pilih wallet investasi</option>
                                    {investmentWallets.map((wallet) => (
                                        <option key={wallet.id} value={wallet.id}>
                                            {wallet.name} — {formatCurrency(wallet.current_balance)}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Asset
                                </label>
                                <select
                                    value={assetId}
                                    onChange={(event) => handleSelectAsset(event.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                >
                                    <option value="">Pilih asset</option>
                                    {assets.map((asset) => (
                                        <option key={asset.id} value={asset.id}>
                                            {asset.symbol} — {asset.name}
                                        </option>
                                    ))}
                                </select>
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Quantity
                                </label>
                                <input
                                    type="number"
                                    step="0.00000001"
                                    min="0"
                                    value={quantity}
                                    onChange={(event) => setQuantity(event.target.value)}
                                    placeholder="Contoh: 1"
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Price
                                </label>
                                <input
                                    type="number"
                                    step="0.00000001"
                                    min="0"
                                    value={price}
                                    onChange={(event) => setPrice(event.target.value)}
                                    placeholder="Auto dari current price"
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Fee
                                </label>
                                <input
                                    type="number"
                                    min="0"
                                    value={fee}
                                    onChange={(event) => setFee(event.target.value)}
                                    placeholder="0"
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Ordered At
                                </label>
                                <input
                                    type="datetime-local"
                                    value={orderedAt}
                                    onChange={(event) => setOrderedAt(event.target.value)}
                                    className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>

                            <div>
                                <label className="text-sm font-semibold text-slate-700">
                                    Note
                                </label>
                                <textarea
                                    value={note}
                                    onChange={(event) => setNote(event.target.value)}
                                    rows={3}
                                    placeholder="Catatan opsional"
                                    className="mt-1 w-full resize-none rounded-xl border border-slate-200 px-4 py-2.5 outline-none focus:border-blue-500"
                                />
                            </div>

                            <div className="rounded-2xl bg-slate-50 p-4 text-sm">
                                <div className="flex justify-between">
                                    <span className="text-slate-500">Gross Amount</span>
                                    <span className="font-bold text-slate-950">
                                        {formatCurrency(estimatedGross)}
                                    </span>
                                </div>

                                <div className="mt-2 flex justify-between">
                                    <span className="text-slate-500">Estimated Net</span>
                                    <span className="font-bold text-slate-950">
                                        {formatCurrency(estimatedNet)}
                                    </span>
                                </div>
                            </div>

                            <button
                                type="submit"
                                disabled={submittingOrder}
                                className={`flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3 font-semibold text-white disabled:opacity-60 ${orderType === "buy"
                                        ? "bg-emerald-600 hover:bg-emerald-700"
                                        : "bg-red-600 hover:bg-red-700"
                                    }`}
                            >
                                {submittingOrder ? (
                                    <>
                                        <Loader2 className="animate-spin" size={18} />
                                        Executing...
                                    </>
                                ) : (
                                    <>
                                        <Plus size={18} />
                                        {orderType === "buy" ? "Buy Asset" : "Sell Asset"}
                                    </>
                                )}
                            </button>
                        </form>

                        {!hasInvestmentCashWallet && (
                            <div className="mt-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-700">
                                Belum ada wallet investment_cash. Buat dulu di halaman Wallets:
                                type <b>Investment Cash</b>.{" "}
                                <Link
                                    to="/wallets"
                                    className="font-semibold text-amber-900 underline"
                                >
                                    Buka Wallets
                                </Link>
                            </div>
                        )}
                    </div>

                    <div className="rounded-3xl bg-white p-6 shadow-sm ring-1 ring-slate-100">
                        <h2 className="text-xl font-bold text-slate-950">Watchlist</h2>

                        {loading ? (
                            <LoadingState text="Loading watchlist..." />
                        ) : watchlists.length === 0 ? (
                            <p className="mt-4 rounded-2xl bg-slate-50 p-4 text-sm text-slate-500">
                                Belum ada watchlist. Klik icon bintang di asset.
                            </p>
                        ) : (
                            <div className="mt-5 space-y-3">
                                {watchlists.map((watchlist) => (
                                    <div
                                        key={watchlist.id}
                                        className="flex items-center justify-between gap-3 rounded-2xl border border-slate-100 bg-slate-50 p-4"
                                    >
                                        <div>
                                            <p className="font-bold text-slate-950">
                                                {watchlist.asset.symbol}
                                            </p>
                                            <p className="text-xs text-slate-500">
                                                {watchlist.asset.name}
                                            </p>
                                            <p className="mt-1 text-sm font-semibold text-slate-900">
                                                {formatCurrency(watchlist.asset.current_price)}
                                            </p>
                                        </div>

                                        <button
                                            onClick={() => handleToggleWatchlist(watchlist.asset)}
                                            className="rounded-xl p-2 text-slate-400 hover:bg-red-50 hover:text-red-600"
                                        >
                                            <Trash2 size={18} />
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}

type SummaryCardProps = {
    label: string;
    value: string;
    icon: ReactNode;
    iconClass: string;
    subValue?: string;
};

function SummaryCard({
    label,
    value,
    icon,
    iconClass,
    subValue,
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

            {subValue && <p className="mt-1 text-sm text-slate-500">{subValue}</p>}
        </div>
    );
}

function LoadingState({ text }: { text: string }) {
    return (
        <div className="flex min-h-65 items-center justify-center">
            <div className="flex items-center gap-3 text-slate-500">
                <Loader2 className="animate-spin" size={20} />
                {text}
            </div>
        </div>
    );
}

function EmptyState({ text }: { text: string }) {
    return (
        <div className="flex min-h-45 items-center justify-center rounded-2xl bg-slate-50 p-6 text-center text-sm text-slate-500">
            {text}
        </div>
    );
}
