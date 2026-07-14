import { Navigate, Route, Routes } from "react-router-dom";
import { AppLayout } from "../layouts/AppLayout";
import { ProtectedRoute } from "./ProtectedRoute";
import { LoginPage } from "../pages/LoginPage";
import { RegisterPage } from "../pages/RegisterPage";
import { DashboardPage } from "../pages/DashboardPage";
import { WalletsPage } from "../pages/WalletsPage";
import { CategoriesPage } from "../pages/CategoriesPage";
import { TransactionsPage } from "../pages/TransactionsPage";
import { BudgetsPage } from "../pages/BudgetsPage";
import { BankConnectionsPage } from "../pages/BankConnectionsPage";
import { InvestmentPage } from "../pages/InvestmentPage";
import { DatasetManagementPage } from "../pages/DatasetManagementPage";

export function AppRouter() {
    return (
        <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />

            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />

            <Route element={<ProtectedRoute />}>
                <Route element={<AppLayout />}>
                    <Route path="/dashboard" element={<DashboardPage />} />
                    <Route path="/wallets" element={<WalletsPage />} />
                    <Route path="/categories" element={<CategoriesPage />} />
                    <Route path="/transactions" element={<TransactionsPage />} />
                    <Route path="/budgets" element={<BudgetsPage />} />
                    <Route path="/bank-connections" element={<BankConnectionsPage />} />
                    <Route path="/investments" element={<InvestmentPage />} />
                    <Route path="/dataset" element={<DatasetManagementPage />} />
                </Route>
            </Route>

            <Route path="*" element={<Navigate to="/dashboard" replace />} />
        </Routes>
    );
}
