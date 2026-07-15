export type User = {
    id: number;
    name: string;
    email: string;
    timezone?: string;
    base_currency?: string;
};

export type LoginPayload = {
    email: string;
    password: string;
};

export type RegisterPayload = {
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
};

export type AuthResponse = {
    message: string;
    token: string;
    user: User;
};