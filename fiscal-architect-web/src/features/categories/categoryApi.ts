import { apiClient } from "../../api/client";
import type {
    CategoryListResponse,
    CategoryResponse,
    CategoryType,
    CreateCategoryPayload,
} from "../../types/category";

export async function getCategories(type?: CategoryType) {
    const response = await apiClient.get<CategoryListResponse>("/categories", {
        params: type ? { type } : undefined,
    });

    return response.data.data;
}

export async function createCategory(payload: CreateCategoryPayload) {
    const response = await apiClient.post<CategoryResponse>("/categories", payload);
    return response.data.data;
}

export async function deleteCategory(id: number) {
    const response = await apiClient.delete<{ message: string }>(
        `/categories/${id}`
    );

    return response.data;
}