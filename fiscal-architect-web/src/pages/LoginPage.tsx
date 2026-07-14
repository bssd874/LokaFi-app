import { useState } from "react";
import type { FormEvent } from "react";
import { Link, useNavigate } from "react-router-dom";
import { login } from "../features/auth/authApi";
import { useAuthStore } from "../store/authStore";
import { getApiErrorMessage } from "../utils/apiError";

export function LoginPage() {
    const navigate = useNavigate();
    const setAuth = useAuthStore((state) => state.setAuth);

    const [email, setEmail] = useState("boni@example.com");
    const [password, setPassword] = useState("password123");
    const [error, setError] = useState("");
    const [loading, setLoading] = useState(false);

    async function handleSubmit(event: FormEvent) {
        event.preventDefault();

        setError("");
        setLoading(true);

        try {
            const data = await login({ email, password });
            setAuth(data.token, data.user);
            navigate("/dashboard");
        } catch (err: unknown) {
            setError(getApiErrorMessage(err, "Login gagal"));
        } finally {
            setLoading(false);
        }
    }

    return (
        <div className="flex min-h-screen items-center justify-center bg-slate-50 px-4">
            <div className="w-full max-w-md rounded-2xl bg-white p-8 shadow-sm">
                <h1 className="text-2xl font-bold text-slate-900">Login</h1>
                <p className="mt-1 text-sm text-slate-500">
                    Masuk ke dashboard finance kamu.
                </p>

                {error && (
                    <div className="mt-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-600">
                        {error}
                    </div>
                )}

                <form onSubmit={handleSubmit} className="mt-6 space-y-4">
                    <div>
                        <label className="text-sm font-medium text-slate-700">Email</label>
                        <input
                            type="email"
                            className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2 outline-none focus:border-blue-500"
                            value={email}
                            onChange={(event) => setEmail(event.target.value)}
                        />
                    </div>

                    <div>
                        <label className="text-sm font-medium text-slate-700">Password</label>
                        <input
                            type="password"
                            className="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2 outline-none focus:border-blue-500"
                            value={password}
                            onChange={(event) => setPassword(event.target.value)}
                        />
                    </div>

                    <button
                        type="submit"
                        disabled={loading}
                        className="w-full rounded-xl bg-blue-600 px-4 py-2 font-semibold text-white hover:bg-blue-700 disabled:opacity-60"
                    >
                        {loading ? "Loading..." : "Login"}
                    </button>
                </form>

                <p className="mt-4 text-center text-sm text-slate-500">
                    Belum punya akun?{" "}
                    <Link to="/register" className="font-medium text-blue-600">
                        Register
                    </Link>
                </p>
            </div>
        </div>
    );
}
