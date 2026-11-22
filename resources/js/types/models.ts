export type Option = {
    value: string;
    label: string;
};

export type AssetTypeOption = Option & {
    formats: string[];
    max_bytes: number;
};

export type PropertyOptions = {
    types: Option[];
    statuses: Option[];
    area_units: string[];
};

export type PropertyStatus = 'draft' | 'active' | 'archived';

export type Property = {
    id: string;
    name: string;
    slug: string;
    property_type: string;
    property_type_label: string;
    status: PropertyStatus;
    status_label: string;
    description: string | null;
    address: string | null;
    city: string | null;
    region: string | null;
    country: string | null;
    location: string | null;
    latitude: string | null;
    longitude: string | null;
    bedrooms: number | null;
    bathrooms: string | null;
    area: string | null;
    area_unit: string;
    price: string | null;
    currency: string | null;
    cover?: { id: string; thumbnail_url: string | null } | null;
    media_count?: number;
    created_at: string | null;
    updated_at: string | null;
    can: { update: boolean; delete: boolean };
};

export type AssetType =
    | 'photo'
    | 'panorama'
    | 'video'
    | 'audio'
    | 'logo'
    | 'floor_plan';

export type PanoramaInspection = {
    is_equirectangular: boolean;
    ratio: number | null;
    warnings: string[];
};

export type MediaAsset = {
    id: string;
    asset_type: AssetType;
    asset_type_label: string;
    source_type: string;
    original_filename: string | null;
    mime_type: string | null;
    format: string | null;
    file_size: number;
    width: number | null;
    height: number | null;
    duration: number | null;
    resource_type: 'image' | 'video';
    secure_url: string;
    thumbnail_url: string | null;
    panorama: PanoramaInspection | null;
    property?: { id: string; name: string } | null;
    uploader?: string | null;
    /** Set while an AI photo edit of this file is still running. */
    enhancement_id?: string | null;
    created_at: string | null;
    can: { update: boolean; delete: boolean };
};

export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    from: number | null;
    to: number | null;
    total: number;
    per_page: number;
};

export type CurrentWorkspace = {
    id: string;
    name: string;
    role: 'owner' | 'admin' | 'editor' | 'viewer';
    credits: number;
    can: { edit: boolean; manage: boolean };
    plan: {
        name: string | null;
        features: Record<PlanFeature, boolean>;
    };
};

export type PlanFeature =
    | 'video_studio'
    | 'remove_branding'
    | 'advanced_analytics'
    | 'custom_domain'
    | 'api_access'
    | 'unlimited_credits'
    | 'tracking_scripts'
    | 'street_view';

export type Features = {
    media_uploads: boolean;
    ai_analysis: boolean;
    panorama_generation: boolean;
    video_generation: boolean;
};

export type TeamComments = {
    subject: { type: 'property' | 'tour' | 'video'; id: string };
    items: {
        id: string;
        body: string;
        author: string;
        is_mine: boolean;
        can_delete: boolean;
        edited: boolean;
        created_at: string | null;
    }[];
    can_comment: boolean;
};
