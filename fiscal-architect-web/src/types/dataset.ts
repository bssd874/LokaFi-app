export type DatasetCategorySummary = {
    category_id: number;
    category_name: string;
    category_type?: string | null;
    total: number;
};

export type DatasetSourceSummary = {
    source: string;
    total: number;
};

export type DatasetSummary = {
    total_transactions: number;
    total_labeled: number;
    total_unclassified: number;
    total_verified: number;
    per_category: DatasetCategorySummary[];
    by_source: DatasetSourceSummary[];
    label_completion_percentage: number;
};

export type DatasetSummaryResponse = {
    message: string;
    data: DatasetSummary;
};
