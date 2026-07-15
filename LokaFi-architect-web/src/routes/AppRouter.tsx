import { Navigate, Route, Routes } from "react-router-dom";
import { AppLayout } from "../layouts/AppLayout";
import { ProtectedRoute } from "./ProtectedRoute";
import { LoginPage } from "../pages/LoginPage";
import { RegisterPage } from "../pages/RegisterPage";
import { DashboardPage } from "../pages/DashboardPage";
import { FinancialAnalyticsPage } from "../pages/FinancialAnalyticsPage";
import { AccountsPage } from "../pages/AccountsPage";
import { WalletsPage } from "../pages/WalletsPage";
import { CategoriesPage } from "../pages/CategoriesPage";
import { TransactionsPage } from "../pages/TransactionsPage";
import { TransactionImportPage } from "../pages/TransactionImportPage";
import { BudgetsPage } from "../pages/BudgetsPage";
import { BankConnectionsPage } from "../pages/BankConnectionsPage";
import { InvestmentPage } from "../pages/InvestmentPage";
import { DatasetManagementPage } from "../pages/DatasetManagementPage";
import { StellarWalletPage } from "../pages/StellarWalletPage";
import { StellarPaymentHistoryPage } from "../pages/StellarPaymentHistoryPage";
import { InvoiceListPage } from "../pages/InvoiceListPage";
import { CreateInvoicePage } from "../pages/CreateInvoicePage";
import { InvoiceDetailPage } from "../pages/InvoiceDetailPage";
import { PublicInvoicePaymentPage } from "../pages/PublicInvoicePaymentPage";

export function AppRouter() {
    return (
        <Routes>
            <Route path="/" element={<Navigate to="/dashboard" replace />} />

            <Route path="/login" element={<LoginPage />} />
            <Route path="/register" element={<RegisterPage />} />
            <Route path="/pay/:uuid" element={<PublicInvoicePaymentPage />} />

            <Route element={<ProtectedRoute />}>
                <Route element={<AppLayout />}>
                    <Route path="/dashboard" element={<DashboardPage />} />
                    <Route path="/financial-analytics" element={<FinancialAnalyticsPage />} />
                    <Route path="/accounts" element={<AccountsPage />} />
                    <Route path="/wallets" element={<WalletsPage />} />
                    <Route path="/stellar-wallet" element={<StellarWalletPage />} />
                    <Route path="/stellar-payments" element={<StellarPaymentHistoryPage />} />
                    <Route path="/invoices" element={<InvoiceListPage />} />
                    <Route path="/invoices/create" element={<CreateInvoicePage />} />
                    <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
                    <Route path="/categories" element={<CategoriesPage />} />
                    <Route path="/transactions" element={<TransactionsPage />} />
                    <Route path="/transaction-imports" element={<TransactionImportPage />} />
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
