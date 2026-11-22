import type { MarkerConfig } from '@photo-sphere-viewer/markers-plugin';
import { glyphFor, glyphSvg } from '@/lib/pinIcons';
import { toRadians } from '@/lib/sphere';
import type { HotspotSettings, HotspotType, TourHotspot } from '@/types';

export function escapeHtml(value: string): string {
    return value
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#39;');
}

/**
 * Pin colours by type, used when a pin has no colour of its own.
 */
const TYPE_COLORS: Record<HotspotType | 'draft', string> = {
    navigation: '#3b82f6',
    info: '#f59e0b',
    link: '#10b981',
    media: '#ef4444',
    draft: '#ea580c',
};

export const DEFAULT_PIN: HotspotSettings = {
    open_in_new_tab: true,
    placement: 'sphere',
    size: 36,
    color: null,
    opacity: 1,
    rotation: 0,
    label_mode: 'always',
    icon: null,
};

export function pinColor(
    kind: HotspotType | 'draft',
    settings?: Partial<HotspotSettings>,
): string {
    return settings?.color ?? TYPE_COLORS[kind];
}

/**
 * One pin: an icon (a flat ring on the floor, a rounded square on a wall, or
 * a round pin) with its label beside it. Look comes from CSS variables, so a
 * pin's size, colour, opacity and rotation are all set per pin.
 */
function markerHtml(
    kind: HotspotType | 'draft',
    options: {
        selected?: boolean;
        label?: string | null;
        settings?: Partial<HotspotSettings>;
    } = {},
): string {
    const pin = { ...DEFAULT_PIN, ...options.settings };
    const style = [
        `--pin-size:${pin.size}px`,
        `--pin-color:${pinColor(kind, pin)}`,
        `--pin-opacity:${pin.opacity}`,
        `--pin-rotation:${pin.rotation}deg`,
    ].join(';');
    const label = options.label
        ? `<span class="pin__label">${escapeHtml(options.label)}</span>`
        : '';
    const classes = [
        'pin',
        `pin--${kind}`,
        `pin--${pin.placement}`,
        pin.label_mode === 'hover' ? 'pin--label-hover' : '',
        options.selected ? 'is-selected' : '',
    ]
        .filter(Boolean)
        .join(' ');

    return `<div class="${classes}" style="${style}"><div class="pin__icon">${glyphSvg(glyphFor(kind, pin.icon))}</div>${label}</div>`;
}
/**
 * Convert a hotspot (degrees) into a Photo Sphere Viewer marker (radians).
 */
export function hotspotMarker(
    hotspot: TourHotspot,
    options: { label?: string | null; selected?: boolean } = {},
): MarkerConfig {
    const label = options.label ?? hotspot.title;

    return {
        id: hotspot.id,
        position: toRadians(hotspot),
        html: markerHtml(hotspot.type, {
            selected: options.selected,
            label,
            settings: hotspot.settings,
        }),
        size: { width: hotspot.settings.size, height: hotspot.settings.size },
        anchor: 'center center',
        data: { hotspotId: hotspot.id, type: hotspot.type },
    };
}

/**
 * The pin as a DOM element, for the virtual-tour plugin's scene links.
 */
export function pinElement(
    hotspot: TourHotspot,
    label?: string | null,
): HTMLElement {
    const holder = document.createElement('div');
    holder.innerHTML = markerHtml(hotspot.type, {
        label: label ?? hotspot.title,
        settings: hotspot.settings,
    });

    return holder.firstElementChild as HTMLElement;
}

/**
 * What the hotspot form currently describes, so the pin can be redrawn on the
 * panorama while it is being edited.
 */
export type PinPreview = {
    id: string;
    type: HotspotType;
    label: string | null;
    settings: HotspotSettings;
};

export function draftMarker(
    position: {
        yaw: number;
        pitch: number;
    },
    preview: PinPreview | null = null,
): MarkerConfig {
    const size = preview?.settings.size ?? 40;

    return {
        id: 'draft',
        position: toRadians(position),
        html: preview
            ? markerHtml(preview.type, {
                  selected: true,
                  label: preview.label,
                  settings: preview.settings,
              })
            : markerHtml('draft', { selected: true }),
        size: { width: size, height: size },
        anchor: 'center center',
        data: { hotspotId: 'draft' },
    };
}

/**
 * Panel HTML for an information hotspot (all text escaped).
 */
export function infoPanelHtml(hotspot: TourHotspot): string {
    const title = hotspot.title
        ? `<h2 class="tour-panel__title">${escapeHtml(hotspot.title)}</h2>`
        : '';
    const body = hotspot.description
        ? `<p class="tour-panel__body">${escapeHtml(hotspot.description).replaceAll('\n', '<br>')}</p>`
        : '';

    return `<div class="tour-panel">${title}${body}</div>`;
}

/**
 * Panel HTML for a media hotspot: an image or a playable video, with its
 * caption. The URLs come from our own presenter and are escaped anyway.
 */
export function mediaPanelHtml(hotspot: TourHotspot): string {
    const media = hotspot.media;

    if (!media) {
        return infoPanelHtml(hotspot);
    }

    const title = hotspot.title
        ? `<h2 class="tour-panel__title">${escapeHtml(hotspot.title)}</h2>`
        : '';
    const body = hotspot.description
        ? `<p class="tour-panel__body">${escapeHtml(hotspot.description).replaceAll('\n', '<br>')}</p>`
        : '';
    const element =
        media.kind === 'video'
            ? `<video class="tour-panel__media" src="${escapeHtml(media.url)}" poster="${escapeHtml(media.thumbnail_url ?? '')}" controls playsinline preload="metadata"></video>`
            : `<img class="tour-panel__media" src="${escapeHtml(media.url)}" alt="${escapeHtml(hotspot.title ?? media.name ?? '')}" loading="lazy">`;

    return `<div class="tour-panel">${title}${element}${body}</div>`;
}

/**
 * Pick the lighter panorama on small/touch screens to stay within GPU limits.
 */
export function prefersMobilePanorama(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    return (
        window.matchMedia('(pointer: coarse)').matches &&
        Math.min(window.screen.width, window.screen.height) < 900
    );
}
