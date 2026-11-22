import { ref } from 'vue';

export type ViewerQuality = 'auto' | 'fast' | 'best';
export type ViewerLayout =
    | 'classic'
    | 'carousel'
    | 'minimal'
    | 'gallery'
    | 'sidebar';
export type RotateSpeed = 'slow' | 'normal' | 'fast';

export const QUALITY_OPTIONS: {
    value: ViewerQuality;
    label: string;
    hint: string;
}[] = [
    { value: 'auto', label: 'Auto', hint: 'Best fit for this device' },
    { value: 'fast', label: 'Fast', hint: 'Lower data use' },
    { value: 'best', label: 'Best', hint: 'Highest available detail' },
];

export const LAYOUT_OPTIONS: {
    value: ViewerLayout;
    label: string;
    hint: string;
}[] = [
    {
        value: 'classic',
        label: 'Classic',
        hint: 'Scene list on the right sidebar',
    },
    {
        value: 'carousel',
        label: 'Carousel',
        hint: 'Scene thumbnails as a bottom strip',
    },
    {
        value: 'minimal',
        label: 'Minimal',
        hint: 'Clean, distraction-free viewing with hidden controls',
    },
    {
        value: 'gallery',
        label: 'Gallery',
        hint: 'Large thumbnails at the bottom with scene info',
    },
    {
        value: 'sidebar',
        label: 'Sidebar',
        hint: 'Left sidebar with scene list and details',
    },
];

export const SPEED_OPTIONS: { value: RotateSpeed; label: string }[] = [
    { value: 'slow', label: 'Slow' },
    { value: 'normal', label: 'Normal' },
    { value: 'fast', label: 'Fast' },
];

/**
 * Radians per frame for each auto-rotate speed.
 */
export const SPEED_STEP: Record<RotateSpeed, number> = {
    slow: 0.0006,
    normal: 0.0012,
    fast: 0.0024,
};

const STORAGE_KEY = 'viewer-prefs';

type LayoutChoice = { value: ViewerLayout; base: ViewerLayout };

function read(): { quality?: ViewerQuality; layout?: LayoutChoice } {
    try {
        return JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') as {
            quality?: ViewerQuality;
            layout?: LayoutChoice;
        };
    } catch {
        return {};
    }
}

const saved = typeof window === 'undefined' ? {} : read();

/** The visitor's own choices, remembered in their browser. */
const quality = ref<ViewerQuality>(saved.quality ?? 'auto');
/**
 * The layout the visitor picked, with the tour layout it was picked against.
 * When the owner later changes the tour's layout, the old pick is dropped so
 * the new setting shows.
 */
const layoutChoice = ref<LayoutChoice | null>(
    saved.layout && typeof saved.layout === 'object' ? saved.layout : null,
);

function persist(): void {
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                quality: quality.value,
                layout: layoutChoice.value,
            }),
        );
    } catch {
        // Private mode or blocked storage: the choice just lasts for this visit.
    }
}

export function useViewerPrefs() {
    function setQuality(value: ViewerQuality): void {
        quality.value = value;
        persist();
    }

    function setLayout(value: ViewerLayout, base: ViewerLayout): void {
        layoutChoice.value = { value, base };
        persist();
    }

    /** The layout to show, given the tour's own layout setting. */
    function layoutFor(base: ViewerLayout): ViewerLayout {
        return layoutChoice.value?.base === base
            ? layoutChoice.value.value
            : base;
    }

    return { quality, layoutFor, setQuality, setLayout };
}
