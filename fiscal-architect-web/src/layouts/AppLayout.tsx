import { NavLink, Outlet, useNavigate } from "react-router-dom";
import {
    LayoutDashboard,
    Wallet,
    Tags,
    ReceiptText,
    PiggyBank,
    LogOut,
    Landmark,
    LineChart,
    Database,
} from "lucide-react";
import { useAuthStore } from "../store/authStore";
import { logout as logoutApi } from "../features/auth/authApi";

const navItems = [
    { label: "Dashboard", path: "/dashboard", icon: LayoutDashboard },
    { label: "Wallets", path: "/wallets", icon: Wallet },
    { label: "Categories", path: "/categories", icon: Tags },
    { label: "Transactions", path: "/transactions", icon: ReceiptText },
    { label: "Budgets", path: "/budgets", icon: PiggyBank },
    { label: "Bank Connections", path: "/bank-connections", icon: Landmark },
    { label: "Investments", path: "/investments", icon: LineChart },
    { label: "Dataset", path: "/dataset", icon: Database },
];

export function AppLayout() {
    const navigate = useNavigate();
    const user = useAuthStore((state) => state.user);
    const clearAuth = useAuthStore((state) => state.clearAuth);

    async function handleLogout() {
        try {
            await logoutApi();
        } catch {
            // tetap logout lokal meskipun request gagal
        }

        clearAuth();
        navigate("/login");
    }

    return (
        <div className="min-h-screen bg-slate-50 text-slate-900">
            <aside className="border-b border-slate-200 bg-white px-4 py-4 lg:fixed lg:left-0 lg:top-0 lg:h-screen lg:w-64 lg:border-b-0 lg:border-r lg:py-6">
                <div className="mb-4 flex items-center justify-between gap-4 lg:mb-8 lg:block">
                    <div>
                        <h1 className="text-lg font-bold text-slate-900 lg:text-xl">
                            Fiscal Architect
                        </h1>
                        <p className="text-sm text-slate-500">Finance Dashboard</p>
                    </div>

                    <button
                        onClick={handleLogout}
                        className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 lg:hidden"
                    >
                        <LogOut size={18} />
                        Logout
                    </button>
                </div>

                <nav className="flex gap-2 overflow-x-auto pb-1 lg:block lg:space-y-1 lg:overflow-visible lg:pb-0">
                    {navItems.map((item) => {
                        const Icon = item.icon;

                        return (
                            <NavLink
                                key={item.path}
                                to={item.path}
                                className={({ isActive }) =>
                                    [
                                        "flex shrink-0 items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition",
                                        isActive
                                            ? "bg-blue-50 text-blue-700 ring-1 ring-blue-100"
                                            : "text-slate-600 hover:bg-slate-50 hover:text-slate-900",
                                    ].join(" ")
                                }
                            >
                                <Icon size={18} />
                                {item.label}
                            </NavLink>
                        );
                    })}
                </nav>

                <button
                    onClick={handleLogout}
                    className="absolute bottom-6 left-4 right-4 hidden items-center justify-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium text-slate-600 hover:bg-slate-100 lg:flex"
                >
                    <LogOut size={18} />
                    Logout
                </button>
            </aside>

            <main className="min-h-screen lg:ml-64">
                <header className="sticky top-0 z-10 flex h-16 items-center justify-between border-b border-slate-200 bg-white px-4 lg:px-8">
                    <div>
                        <p className="text-sm text-slate-500">Welcome back,</p>
                        <h2 className="font-semibold text-slate-900">{user?.name ?? "User"}</h2>
                    </div>
                </header>

                <div className="p-4 sm:p-6 lg:p-8">
                    <Outlet />
                </div>
            </main>
        </div>
    );
}
