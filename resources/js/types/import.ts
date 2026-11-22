export type ImportStatus =
    | 'queued'
    | 'fetching'
    | 'ready'
    | 'importing'
    | 'completed'
    | 'failed';

export type ImportSummary = {
    id: string;
    url: string;
    source_host: string;
    status: ImportStatus;
    page_title: string | null;
    error: string | null;
    imported_count: number;
    failed_count: number;
    selected_count: number;
    property: { id: string; name: string } | null;
    is_active: boolean;
    used_ai: boolean;
    created_at: string | null;
};

export type ImportDetails = {
    name: string;
    property_type: string;
    description: string | null;
    address: string | null;
    city: string | null;
    region: string | null;
    country: string | null;
    latitude: number | null;
    longitude: number | null;
    bedrooms: number | null;
    bathrooms: number | null;
    area: number | null;
    area_unit: string;
    price: number | null;
    currency: string | null;
};

export type ImportDetail = ImportSummary & {
    details: ImportDetails | null;
    images: { url: string; alt: string | null }[];
    selected_images: string[];
    imported_assets: {
        id: string;
        thumbnail_url: string | null;
        name: string | null;
    }[];
};
