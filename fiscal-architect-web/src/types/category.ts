export type CategoryType = "income" | "expense";

export type Category = {
    id: number;
    user_id: number;
    name: string;
    type: CategoryType;
    icon?: string | null;
    color?: string | null;
    is_default: boolean;
    created_at: string;
    updated_at: string;
};

export type CreateCategoryPayload = {
    name: string;
    type: CategoryType;
    icon?: string;
    color?: string;
};

export type CategoryResponse = {
    message: string;
    data: Category;
};

export type CategoryListResponse = {
    message: string;
    data: Category[];
};