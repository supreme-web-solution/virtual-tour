export type HotspotType = 'navigation' | 'info' | 'link' | 'media';

/** Angles are degrees, as stored by the backend. */
export type TourHotspot = {
    id: string;
    type: HotspotType;
    yaw: number;
    pitch: number;
    title: string | null;
    description: string | null;
    external_url: string | null;
    target_scene_id: string | null;
    media: {
        id: string;
        kind: 'image' | 'video';
        url: string;
        thumbnail_url: string | null;
        name: string | null;
    } | null;
    settings: HotspotSettings;
};

export type HotspotSettings = {
    open_in_new_tab: boolean;
    placement: 'sphere' | 'floor' | 'wall';
    size: number;
    color: string | null;
    opacity: number;
    rotation: number;
    label_mode: 'always' | 'hover';
    /** A glyph key from lib/pinIcons; null uses the default for the pin's type. */
    icon: string | null;
};

export type SceneMedia = {
    id: string;
    filename: string | null;
    thumbnail_url: string | null;
    width: number | null;
    height: number | null;
    is_equirectangular: boolean;
    panorama_url: string;
    panorama_mobile_url: string;
    panorama_preview_url: string;
    /** Tile layout for very large panoramas; null when one image is enough. */
    tiles: {
        width: number;
        height: number;
        cols: number;
        rows: number;
        url_template: string;
    } | null;
};

export type InitialView = { yaw: number; pitch: number; zoom: number };

export type TourScene = {
    id: string;
    title: string;
    description: string | null;
    sort_order: number;
    is_visible: boolean;
    initial_view: InitialView;
    media: SceneMedia;
    hotspots: TourHotspot[];
    audio: SceneAudio;
    /** Map pin, when the scene has been placed on the map. */
    location: { latitude: number; longitude: number } | null;
};

export type ViewerSettings = {
    start_scene_id: string | null;
    show_scene_gallery: boolean;
    show_prev_next: boolean;
    show_scene_label: boolean;
    auto_rotate: boolean;
    auto_rotate_speed: 'slow' | 'normal' | 'fast';
    transition: 'fade' | 'black' | 'none';
    layout: 'classic' | 'carousel' | 'minimal' | 'gallery' | 'sidebar';
};

export type TourStatus = 'draft' | 'published';
export type TourVisibility = 'public' | 'unlisted' | 'private';

export type EditorTour = {
    id: string;
    public_id: string;
    title: string;
    slug: string;
    description: string | null;
    status: TourStatus;
    visibility: TourVisibility;
    published_at: string | null;
    updated_at: string | null;
    property: { id: string; name: string } | null;
    cover_url: string | null;
    viewer_settings: ViewerSettings;
    allowed_embed_domains: string[];
    public_url: string;
    scenes: TourScene[];
    branding: Branding & { logo_url: string | null };
    audio: TourAudioSettings & { background_name: string | null };
    lead_settings: LeadSettings;
    map_settings: {
        enabled: boolean;
        has_coordinates: boolean;
        property_location: { latitude: number; longitude: number } | null;
    };
    analytics_enabled: boolean;
    floorplans: Floorplan[];
};

export type PublicTour = {
    id: string;
    title: string;
    description: string | null;
    public_url: string;
    viewer_settings: ViewerSettings;
    property: { name: string; location: string | null } | null;
    cover_url: string | null;
    scenes: TourScene[];
    branding: PublicBranding;
    audio: { url: string; volume: number; loop: boolean } | null;
    lead: PublicLeadForm | null;
    map: {
        latitude: number;
        longitude: number;
        label: string | null;
        property: { latitude: number; longitude: number } | null;
        pins: {
            scene_id: string;
            title: string;
            latitude: number;
            longitude: number;
            thumbnail_url: string | null;
        }[];
    } | null;
    floorplans: Floorplan[];
    analytics_enabled: boolean;
};

export type LibraryPanorama = {
    id: string;
    filename: string | null;
    thumbnail_url: string | null;
    width: number | null;
    height: number | null;
    is_equirectangular: boolean;
    property: string | null;
    from_tour_property: boolean;
};

export type TourListItem = {
    id: string;
    title: string;
    status: TourStatus;
    visibility: TourVisibility;
    scenes_count: number;
    property: { id: string; name: string } | null;
    cover_url: string | null;
    public_url: string;
    build_stage:
        | 'planning'
        | 'analysing'
        | 'importing'
        | 'generating'
        | 'failed'
        | null;
    updated_at: string | null;
    can: { delete: boolean };
};

export type SceneAudio = {
    asset_id: string | null;
    name: string | null;
    url: string | null;
    volume: number;
    loop: boolean;
    pause_background: boolean;
};

export type Branding = {
    logo_asset_id: string | null;
    primary_color: string;
    accent_color: string;
    show_platform_branding: boolean;
    intro: {
        enabled: boolean;
        title: string | null;
        text: string | null;
        button_label: string | null;
    };
    contact: {
        enabled: boolean;
        name: string | null;
        email: string | null;
        phone: string | null;
        website: string | null;
    };
};

export type PublicBranding = {
    logo_url: string | null;
    primary_color: string;
    accent_color: string;
    show_platform_branding: boolean;
    intro: Branding['intro'];
    contact: Omit<Branding['contact'], 'enabled'> | null;
};

export type TourAudioSettings = {
    background_asset_id: string | null;
    volume: number;
    loop: boolean;
};

export type LeadSettings = {
    enabled: boolean;
    title: string;
    button_label: string;
    require_phone: boolean;
    thank_you: string;
    notify: boolean;
};

export type PublicLeadForm = {
    title: string;
    button_label: string;
    require_phone: boolean;
    thank_you: string;
    submit_url: string;
    form_token: string;
};

export type Floorplan = {
    id: string;
    title: string;
    media_id: string;
    image_url: string;
    markers: { scene_id: string; x: number; y: number }[];
};

export type LibraryAsset = {
    id: string;
    name: string | null;
    type: string;
    thumbnail_url: string | null;
    url: string;
};
