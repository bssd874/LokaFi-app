import { apiClient } from "../../api/client";
import type { AuthResponse, LoginPayload, RegisterPayload, User } from "../../types/auth";

export async function login(payload: LoginPayload): Promise<AuthResponse> {
    const response = await apiClient.post<AuthResponse>("/auth/login", payload);
    return response.data;
}

export async function register(payload: RegisterPayload): Promise<AuthResponse> {
    const response = await apiClient.post<AuthResponse>("/auth/register", payload);
    return response.data;
}

export async function getMe(): Promise<{ user: User }> {
    const response = await apiClient.get<{ user: User }>("/auth/me");
    return response.data;
}

export async function logout(): Promise<{ message: string }> {
    const response = await apiClient.post<{ message: string }>("/auth/logout");
    return response.data;
}