import { create } from "zustand";
import type { User } from "../types/auth";

type AuthState = {
    user: User | null;
    token: string | null;
    isAuthenticated: boolean;
    setAuth: (token: string, user: User) => void;
    clearAuth: () => void;
    loadAuthFromStorage: () => void;
};

export const useAuthStore = create<AuthState>((set) => ({
    user: null,
    token: null,
    isAuthenticated: false,

    setAuth: (token, user) => {
        localStorage.setItem("auth_token", token);
        localStorage.setItem("auth_user", JSON.stringify(user));

        set({
            token,
            user,
            isAuthenticated: true,
        });
    },

    clearAuth: () => {
        localStorage.removeItem("auth_token");
        localStorage.removeItem("auth_user");

        set({
            token: null,
            user: null,
            isAuthenticated: false,
        });
    },

    loadAuthFromStorage: () => {
        const token = localStorage.getItem("auth_token");
        const userRaw = localStorage.getItem("auth_user");

        if (!token || !userRaw) {
            set({
                token: null,
                user: null,
                isAuthenticated: false,
            });
            return;
        }

        try {
            const user = JSON.parse(userRaw) as User;

            set({
                token,
                user,
                isAuthenticated: true,
            });
        } catch {
            localStorage.removeItem("auth_token");
            localStorage.removeItem("auth_user");

            set({
                token: null,
                user: null,
                isAuthenticated: false,
            });
        }
    },
}));