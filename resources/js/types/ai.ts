export type GenerationStatus =
    | 'pending'
    | 'queued'
    | 'submitted'
    | 'processing'
    | 'downloading'
    | 'uploading'
    | 'assembling'
    | 'completed'
    | 'failed'
    | 'cancelled'
    | 'refunded';

export type GenerationType =
    | 'scene_plan'
    | 'photo_analysis'
    | 'text_to_panorama'
    | 'photo_to_panorama';

export type GenerationSummary = {
    id: string;
    type: GenerationType;
    type_label: string;
    status: GenerationStatus;
    is_active: boolean;
    title: string;
    error: string | null;
    credits: number;
    thumbnail_url: string | null;
    created_at: string | null;
    links: {
        plan: string | null;
        analysis: string | null;
        tour: string | null;
    };
    can: { regenerate: boolean; cancel: boolean };
};

export type Credits = { available: number; reserved: number; balance: number };

export type PanoramaStyle = {
    id: number;
    name: string;
    model: string;
    max_prompt_length: number;
    max_negative_length: number;
    supports_image_to_panorama: boolean;
    preview_url: string | null;
};

export type PlanScene = {
    key: string;
    title: string;
    room_type: string;
    description: string;
    panorama_prompt: string;
    negative_prompt: string;
    order: number;
    connections: string[];
};

export type ScenePlan = {
    tour_title: string;
    tour_description: string;
    scenes: PlanScene[];
};

export type AnalysedImage = {
    asset_id: string;
    room_type: string;
    indoor: boolean;
    title: string;
    description: string;
    features: string[];
    quality: 'good' | 'acceptable' | 'poor';
    quality_notes: string;
    duplicate_of: string | null;
    observed_facts: string[];
    inferred_suggestions: string[];
};

export type PhotoAnalysis = {
    property_type: string;
    summary: string;
    images: AnalysedImage[];
    connections: { from: string; to: string }[];
};

export type LibraryPhoto = {
    id: string;
    filename: string | null;
    thumbnail_url: string | null;
    property_id?: string | null;
    property?: string | null;
};

export type TourTarget = {
    tour_mode: 'none' | 'new' | 'existing';
    tour_id: string;
    tour_title: string;
};
