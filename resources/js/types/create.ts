import type { AssetTypeOption, Option } from './models';

export type CreateMode = 'describe' | 'photos' | 'panoramas' | 'link';

export type PickableAsset = {
    id: string;
    name: string | null;
    thumbnail_url: string | null;
    width: number | null;
    height: number | null;
};

export type CreateOptions = {
    features: { ai: boolean; panoramas: boolean };
    prices: {
        plan: number;
        text_scene: number;
        photo_scene: number;
        analysis: number;
    };
    available: number;
    max_scenes: number;
    max_photos: number;
    property_types: Option[];
    asset_types: AssetTypeOption[];
    properties: Option[];
    recent: { photos: PickableAsset[]; panoramas: PickableAsset[] };
};

export type BuildStage =
    | 'planning'
    | 'analysing'
    | 'importing'
    | 'generating'
    | 'ready'
    | 'failed';

export type TourBuild = {
    id: string;
    title: string;
    mode: CreateMode | null;
    stage: BuildStage;
    total: number;
    done: number;
    scenes: {
        title: string;
        status: 'working' | 'done' | 'failed';
        thumbnail_url: string | null;
    }[];
    error: string | null;
    is_finished: boolean;
    scene_count: number;
    editor_url: string;
};

export type ListingImport = {
    id: string;
    url: string;
    source_host: string;
    status:
        | 'queued'
        | 'fetching'
        | 'ready'
        | 'importing'
        | 'completed'
        | 'failed';
    page_title: string | null;
    error: string | null;
    details: Record<string, unknown> | null;
    images: { url: string; alt: string | null }[];
    is_active: boolean;
    used_ai: boolean;
};
