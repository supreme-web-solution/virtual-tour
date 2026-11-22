export type MetricComparison = {
    current: number;
    previous: number;
    change: number | null;
};

export type AdvancedReport = {
    comparison: Record<
        | 'views'
        | 'unique_visitors'
        | 'average_duration_seconds'
        | 'leads'
        | 'conversion_rate',
        MetricComparison
    >;
    tours: {
        id: string;
        title: string;
        views: number;
        unique_visitors: number;
        average_duration_seconds: number;
        completion_rate: number;
        leads: number;
        conversion_rate: number;
    }[];
    funnel: { label: string; visits: number; percent: number }[] | null;
    hotspots: {
        label: string;
        scene: string | null;
        type: string;
        clicks: number;
    }[];
    heatmap: number[][];
    timezone: string;
};

export type VideoMetrics = {
    totals: {
        views: number;
        embed_views: number;
        plays: number;
        completions: number;
        downloads: number;
    };
    items: {
        id: string;
        title: string;
        published: boolean;
        views: number;
        embed_views: number;
        plays: number;
        completions: number;
        downloads: number;
        completion_rate: number | null;
    }[];
};
