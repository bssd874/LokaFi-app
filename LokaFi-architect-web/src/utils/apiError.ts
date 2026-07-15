type ApiErrorResponse = {
    response?: {
        data?: {
            message?: string;
            errors?: Record<string, string[]>;
        };
    };
};

function isApiError(error: unknown): error is ApiErrorResponse {
    return Boolean(error && typeof error === "object" && "response" in error);
}

export function getApiErrorMessage(error: unknown, fallback: string) {
    if (!isApiError(error)) return fallback;

    return error.response?.data?.message || fallback;
}

export function getFirstValidationError(error: unknown) {
    if (!isApiError(error)) return undefined;

    const errors = error.response?.data?.errors;
    if (!errors) return undefined;

    const firstError = Object.values(errors)[0];
    return firstError?.[0];
}
