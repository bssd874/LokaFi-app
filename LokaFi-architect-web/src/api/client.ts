import axios from "axios";

export const apiClient = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL,
    headers: {
        Accept: "application/json",
    },
});

const MUTATING_METHODS = new Set(["post", "put", "patch", "delete"]);
const DASHBOARD_MUTATION_PATHS = [
    "/transactions",
    "/transaction-imports/commit",
    "/budgets",
    "/invoices",
    "/public/invoices",
    "/stellar/wallet",
];

function shouldNotifyDashboard(method?: string, url?: string) {
    if (!MUTATING_METHODS.has((method ?? "get").toLowerCase())) {
        return false;
    }

    const target = url ?? "";

    return DASHBOARD_MUTATION_PATHS.some((path) => target.includes(path));
}

apiClient.interceptors.request.use((config) => {
    const token = localStorage.getItem("auth_token");

    if (token) {
        config.headers.Authorization = `Bearer ${token}`;
    }

    return config;
});

apiClient.interceptors.response.use(
    (response) => {
        if (
            typeof window !== "undefined" &&
            shouldNotifyDashboard(response.config.method, response.config.url)
        ) {
            window.dispatchEvent(new Event("lokafi:data-mutated"));
        }

        return response;
    },
    (error) => {
        if (error.response?.status === 401) {
            localStorage.removeItem("auth_token");
            localStorage.removeItem("auth_user");
            window.location.href = "/login";
        }

        return Promise.reject(error);
    }
);
