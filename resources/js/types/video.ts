export type VideoStatus =
    | 'planning'
    | 'draft'
    | 'generating'
    | 'ready'
    | 'rendering'
    | 'completed'
    | 'failed';

export type SoundtrackModeValue = 'none' | 'ai_music' | 'ambient' | 'own_audio';

export type VideoSummary = {
    id: string;
    public_id: string;
    title: string;
    style: string;
    style_label: string;
    aspect_ratio: string;
    requested_duration: number;
    status: VideoStatus;
    status_label: string;
    poster_url: string | null;
    property: { id: string; name: string } | null;
    is_published: boolean;
    updated_at: string | null;
    can: { delete: boolean };
};

export type VideoShotItem = {
    id: string;
    sort_order: number;
    duration: number;
    camera_movement: string;
    scene_name: string | null;
    prompt: string;
    transition: 'crossfade' | 'fade_black' | 'cut';
    status: 'pending' | 'generating' | 'completed' | 'failed';
    error: string | null;
    source: {
        id: string;
        name: string | null;
        is_panorama: boolean;
        thumbnail_url: string | null;
    };
    clip: { url: string; thumbnail_url: string | null } | null;
};

export type VideoSettings = {
    music_mood: string;
    ambience: string;
    music_volume: number;
    voiceover_volume: number;
    audio_trim_start: number;
    audio_fade_in: number;
    audio_fade_out: number;
    branded_outro: boolean;
    brief: string | null;
};

export type AudioRef = {
    id: string;
    name: string | null;
    url: string;
    duration?: number | null;
};

export type VideoProjectDetail = VideoSummary & {
    resolution: string;
    provider: string;
    model: string | null;
    storyboard: {
        video_title?: string;
        creative_direction?: string;
        music_prompt?: string;
    } | null;
    storyboard_error: string | null;
    settings: VideoSettings;
    soundtrack_mode: SoundtrackModeValue;
    soundtrack: AudioRef | null;
    voiceover: AudioRef | null;
    music_generation: { status: string; error: string | null } | null;
    tour: { id: string; title: string } | null;
    progress: { completed: number; total: number };
    total_seconds: number;
    shots: VideoShotItem[];
    final: {
        url: string;
        poster_url: string | null;
        duration: number | null;
    } | null;
    latest_export: {
        status: string;
        error: string | null;
        completed_at: string | null;
    } | null;
    visibility: 'public' | 'unlisted' | 'private';
    published_at: string | null;
    public_url: string;
    stats: {
        views: number;
        plays: number;
        completions: number;
        downloads: number;
        embed_views: number;
    };
    is_active: boolean;
};

export type VideoSource = {
    id: string;
    filename: string | null;
    thumbnail_url: string | null;
    is_panorama: boolean;
    property_id: string | null;
    property: string | null;
};

export type VideoLimits = {
    min_seconds: number;
    max_seconds: number;
    aspect_ratios: string[];
    resolution: string;
    negative_prompt: boolean;
    prompted_audio: boolean;
};
