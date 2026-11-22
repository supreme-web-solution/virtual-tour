<script setup lang="ts">
import '@photo-sphere-viewer/core/index.css';
import '@photo-sphere-viewer/markers-plugin/index.css';
import '@photo-sphere-viewer/virtual-tour-plugin/index.css';
import { Viewer } from '@photo-sphere-viewer/core';
import { EquirectangularTilesAdapter } from '@photo-sphere-viewer/equirectangular-tiles-adapter';
import { MarkersPlugin } from '@photo-sphere-viewer/markers-plugin';
import type {
    VirtualTourLink,
    VirtualTourNode,
} from '@photo-sphere-viewer/virtual-tour-plugin';
import { VirtualTourPlugin } from '@photo-sphere-viewer/virtual-tour-plugin';
import {
    Check,
    ChevronLeft,
    ChevronRight,
    Crosshair,
    LayoutGrid,
    Pause,
    Play,
    SlidersHorizontal,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    shallowRef,
    watch,
} from 'vue';
import {
    LAYOUT_OPTIONS,
    QUALITY_OPTIONS,
    SPEED_OPTIONS,
    SPEED_STEP,
    useViewerPrefs,
} from '@/composables/useViewerPrefs';
import type { RotateSpeed } from '@/composables/useViewerPrefs';
import { flatPhotoPanoData } from '@/lib/flatPhoto';
import { tilePanorama, tourUsesTiles } from '@/lib/tiles';
import { toRadians } from '@/lib/sphere';
import {
    hotspotMarker,
    infoPanelHtml,
    mediaPanelHtml,
    pinElement,
    prefersMobilePanorama,
} from '@/lib/tourMarkers';
import type { PublicTour, TourHotspot, TourScene } from '@/types';

const props = defineProps<{
    tour: PublicTour;
    compact?: boolean;
}>();

const emit = defineEmits<{
    sceneChange: [sceneId: string];
    hotspotSelect: [hotspot: TourHotspot, sceneId: string | null];
}>();

const container = ref<HTMLDivElement | null>(null);
const viewer = shallowRef<Viewer | null>(null);
const virtualTour = shallowRef<VirtualTourPlugin | null>(null);
const currentSceneId = ref<string | null>(null);
const galleryOpen = ref(!props.compact);

const scenes = computed(() => props.tour.scenes);
const settings = computed(() => props.tour.viewer_settings);
const usesTiles = computed(() =>
    tourUsesTiles(scenes.value.map((scene) => scene.media)),
);
/**
 * The scene the name label shows. It changes the moment the new image starts
 * to blend in, not when the blend ends, so text and picture move together.
 */
const labelSceneId = ref<string | null>(null);
const labelScene = computed(() => sceneById(labelSceneId.value) ?? null);
const labelIndex = computed(() =>
    scenes.value.findIndex((scene) => scene.id === labelSceneId.value),
);
const currentIndex = computed(() =>
    scenes.value.findIndex((scene) => scene.id === currentSceneId.value),
);
const startSceneId = computed(() => {
    const configured = settings.value.start_scene_id;

    return scenes.value.some((scene) => scene.id === configured)
        ? configured
        : (scenes.value[0]?.id ?? null);
});

const warmed = new Set<string>();

/**
 * Fetch the previews of the scenes either side of this one ahead of time, so
 * the previous/next buttons switch without waiting on the network.
 */
function warmNeighbours(sceneId: string): void {
    const index = scenes.value.findIndex((scene) => scene.id === sceneId);

    if (index === -1 || scenes.value.length < 2) {
        return;
    }

    for (const offset of [1, -1, 2]) {
        const scene =
            scenes.value[
                (index + offset + scenes.value.length) % scenes.value.length
            ];
        const url = scene.media.panorama_preview_url;

        if (!warmed.has(url)) {
            warmed.add(url);
            void fetch(url, { mode: 'cors' }).catch(() => warmed.delete(url));
        }
    }
}

function sceneById(id: string | null): TourScene | undefined {
    return scenes.value.find((scene) => scene.id === id);
}

/**
 * Info and media pins open here, centred over the tour.
 */
const popover = ref<TourHotspot | null>(null);
/** The pin whose panel is open keeps a white ring until the panel closes. */
let popoverPin: Element | null = null;

watch(popover, (open) => {
    if (open === null) {
        popoverPin?.classList.remove('is-active');
        popoverPin = null;
    }
});

const popoverHtml = computed(() => {
    const hotspot = popover.value;

    if (!hotspot) {
        return '';
    }

    return hotspot.type === 'media'
        ? mediaPanelHtml(hotspot)
        : infoPanelHtml(hotspot);
});

function toNode(scene: TourScene): VirtualTourNode {
    // Tiled tours open on the small preview too; tiles load once the scene upgrades.
    const panorama = usesTiles.value
        ? tilePanorama(scene.media, scene.media.panorama_preview_url, false)
        : scene.media.panorama_preview_url;

    const links: VirtualTourLink[] = scene.hotspots
        .filter(
            (hotspot) =>
                hotspot.type === 'navigation' &&
                hotspot.target_scene_id &&
                sceneById(hotspot.target_scene_id),
        )
        .map((hotspot) => ({
            nodeId: hotspot.target_scene_id as string,
            position: toRadians(hotspot),
            data: { label: hotspot.title },
            arrowStyle: {
                element: () => pinElement(hotspot),
                size: {
                    width: hotspot.settings.size,
                    height: hotspot.settings.size,
                },
            },
        }));

    const markers = scene.hotspots
        .filter((hotspot) => hotspot.type !== 'navigation')
        .map((hotspot) => ({
            ...hotspotMarker(hotspot),
            data: { hotspot },
        }));

    return {
        id: scene.id,
        panorama,
        panoData: flatPhotoPanoData(scene.media),
        name: scene.title,
        caption: '',
        thumbnail: scene.media.thumbnail_url ?? undefined,
        links,
        markers,
    };
}

const prefs = useViewerPrefs();
const layout = computed(() => prefs.layoutFor(settings.value.layout));
const menu = ref<'quality' | 'layout' | 'speed' | null>(null);

/**
 * The full-size panorama for the visitor's quality choice. Auto picks by
 * device; Fast always uses the lighter file; Best the largest available.
 */
function fullUrl(scene: TourScene): string {
    switch (prefs.quality.value) {
        case 'fast':
            return scene.media.panorama_mobile_url;
        case 'best':
            return scene.media.panorama_url;
        default:
            return prefersMobilePanorama()
                ? scene.media.panorama_mobile_url
                : scene.media.panorama_url;
    }
}

watch(prefs.quality, () => {
    if (currentSceneId.value) {
        void upgradePanorama(currentSceneId.value);
    }
});

function toggleMenu(name: 'quality' | 'layout' | 'speed'): void {
    menu.value = menu.value === name ? null : name;
}

/**
 * Scenes open with a small preview so they appear quickly. Once the
 * full-size image has downloaded it replaces the preview in place, with the
 * camera and markers unchanged.
 */
async function upgradePanorama(sceneId: string): Promise<void> {
    const scene = sceneById(sceneId);
    const instance = viewer.value;

    if (!scene || !instance) {
        return;
    }

    const full = fullUrl(scene);

    await new Promise<void>((resolve) => {
        const image = new Image();
        image.onload = () => resolve();
        image.onerror = () => resolve();
        image.src = full;
    });

    if (currentSceneId.value !== sceneId || viewer.value !== instance) {
        return;
    }

    await instance.setPanorama(
        usesTiles.value ? tilePanorama(scene.media, full, wantsTiles()) : full,
        {
            position: instance.getPosition(),
            zoom: instance.getZoomLevel(),
            transition: false,
            showLoader: false,
            panoData: flatPhotoPanoData(scene.media),
        },
    );
}

/**
 * Sharp tiles are for big screens: the Fast choice and phones in Auto keep
 * to the lighter single image.
 */
function wantsTiles(): boolean {
    return (
        prefs.quality.value === 'best' ||
        (prefs.quality.value === 'auto' && !prefersMobilePanorama())
    );
}

function goTo(sceneId: string): void {
    void virtualTour.value?.setCurrentNode(sceneId);
}

function step(offset: number): void {
    if (!scenes.value.length) {
        return;
    }

    const next =
        (currentIndex.value + offset + scenes.value.length) %
        scenes.value.length;
    goTo(scenes.value[next].id);
}

defineExpose({ goTo });

// --- Auto-rotation: play or pause, three speeds, recenter -------------------

const rotating = ref(false);
const speed = ref<RotateSpeed>(settings.value.auto_rotate_speed ?? 'normal');
let rotationFrame: number | null = null;

function stopAutoRotate(): void {
    if (rotationFrame !== null) {
        cancelAnimationFrame(rotationFrame);
        rotationFrame = null;
    }

    rotating.value = false;
}

function beginRotation(): void {
    stopAutoRotate();
    rotating.value = true;

    const tick = (): void => {
        const instance = viewer.value;

        if (instance) {
            const position = instance.getPosition();
            instance.rotate({
                yaw: position.yaw + SPEED_STEP[speed.value],
                pitch: position.pitch,
            });
        }

        rotationFrame = requestAnimationFrame(tick);
    };

    rotationFrame = requestAnimationFrame(tick);
}

function toggleRotation(): void {
    if (rotating.value) {
        stopAutoRotate();
    } else {
        beginRotation();
    }
}

function chooseSpeed(value: RotateSpeed): void {
    speed.value = value;
    menu.value = null;

    if (!rotating.value) {
        beginRotation();
    }
}

/**
 * Only the tour's own setting starts rotation on its own, and never for
 * visitors who prefer reduced motion.
 */
function startAutoRotate(): void {
    if (
        !settings.value.auto_rotate ||
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return;
    }

    beginRotation();
}

/**
 * Turn back to where this scene opens.
 */
function recenter(): void {
    const scene = sceneById(currentSceneId.value);

    if (!scene || !viewer.value) {
        return;
    }

    stopAutoRotate();
    void viewer.value.animate({
        ...toRadians(scene.initial_view),
        zoom: scene.initial_view.zoom,
        speed: '30rpm',
    });
}
onMounted(() => {
    if (!container.value || !scenes.value.length) {
        return;
    }

    const effect = settings.value.transition;
    const start = sceneById(startSceneId.value) ?? scenes.value[0];

    const instance = new Viewer({
        container: container.value,
        ...(usesTiles.value ? { adapter: EquirectangularTilesAdapter } : {}),
        navbar: ['zoom', 'move', 'caption', 'fullscreen'],
        // No "Loading…" text in the caption bar while the next scene arrives.
        lang: { loading: '' },
        defaultYaw: toRadians(start.initial_view).yaw,
        defaultPitch: toRadians(start.initial_view).pitch,
        defaultZoomLvl: start.initial_view.zoom,
        touchmoveTwoFingers: Boolean(props.compact),
        mousewheelCtrlKey: Boolean(props.compact),
        plugins: [
            MarkersPlugin,
            VirtualTourPlugin.withConfig({
                renderMode: '2d',
                positionMode: 'manual',
                nodes: scenes.value.map(toNode),
                startNodeId: start.id,
                preload: true,
                transitionOptions: (toNode: VirtualTourNode) => {
                    const scene = sceneById(toNode.id);

                    return {
                        // The current scene stays visible until the next one is ready, then they blend.
                        showLoader: false,
                        effect: effect === 'none' ? 'none' : effect,
                        speed: 900,
                        rotation: true,
                        rotateTo: scene
                            ? toRadians(scene.initial_view)
                            : undefined,
                        zoomTo: scene?.initial_view.zoom,
                    };
                },
            }),
        ],
    });

    viewer.value = instance;
    virtualTour.value =
        instance.getPlugin<VirtualTourPlugin>(VirtualTourPlugin);
    const markers = instance.getPlugin<MarkersPlugin>(MarkersPlugin);

    instance.addEventListener('panorama-loaded', ({ data }) => {
        const loaded = data.panorama as
            | string
            | { baseUrl?: string; path?: string };
        const url =
            typeof loaded === 'string'
                ? loaded
                : (loaded.baseUrl ?? loaded.path);
        const scene = scenes.value.find(
            (item) => item.media.panorama_preview_url === url,
        );

        if (scene) {
            labelSceneId.value = scene.id;
        }
    });

    virtualTour.value.addEventListener('node-changed', ({ node }) => {
        currentSceneId.value = node.id;
        labelSceneId.value = node.id;
        emit('sceneChange', node.id);
        warmNeighbours(node.id);
        void upgradePanorama(node.id);
        // A link tooltip shown while hovering can outlive the scene it belonged to.
        markers.hideAllTooltips();
    });

    const hotspotOf = (marker: { data?: unknown }): TourHotspot | undefined =>
        (marker.data as { hotspot?: TourHotspot } | undefined)?.hotspot;

    const openPanel = (
        marker: { domElement: Element },
        hotspot: TourHotspot,
    ) => {
        popoverPin?.classList.remove('is-active');
        popoverPin = marker.domElement.querySelector('.pin');
        popoverPin?.classList.add('is-active');
        popover.value = hotspot;
    };

    // Info panels open as soon as the pointer is on the pin and stay until closed.
    markers.addEventListener('enter-marker', ({ marker }) => {
        const hotspot = hotspotOf(marker);

        if (hotspot?.type === 'info' && popover.value?.id !== hotspot.id) {
            openPanel(marker, hotspot);
        }
    });

    markers.addEventListener('select-marker', ({ marker }) => {
        const hotspot = hotspotOf(marker);

        if (hotspot) {
            emit('hotspotSelect', hotspot, currentSceneId.value);
        }

        if (hotspot?.type === 'info' || hotspot?.type === 'media') {
            openPanel(marker, hotspot);
        }

        if (hotspot?.type === 'link' && hotspot.external_url) {
            window.open(
                hotspot.external_url,
                hotspot.settings.open_in_new_tab ? '_blank' : '_top',
                'noopener',
            );
        }
    });

    instance.addEventListener('ready', startAutoRotate, { once: true });
    instance.addEventListener(
        'ready',
        () => {
            if (currentSceneId.value) {
                warmNeighbours(currentSceneId.value);
            }
        },
        { once: true },
    );

    for (const type of ['pointerdown', 'wheel', 'keydown', 'touchstart']) {
        container.value.addEventListener(type, stopAutoRotate, {
            passive: true,
        });
    }
});

onBeforeUnmount(() => {
    stopAutoRotate();
    viewer.value?.destroy();
});
</script>

<template>
    <div class="relative size-full overflow-hidden bg-black">
        <div ref="container" class="size-full" />

        <Transition name="scene-fade" mode="out-in">
            <div
                v-if="settings.show_scene_label && labelScene"
                :key="labelScene.id"
                class="scene-label pointer-events-none absolute top-3 left-1/2 z-10 -translate-x-1/2"
            >
                <span class="scene-label__name">{{ labelScene.title }}</span>
                <span v-if="scenes.length > 1" class="scene-label__count"
                    >{{ labelIndex + 1 }} / {{ scenes.length }}</span
                >
            </div>
        </Transition>

        <Transition name="scene-fade" mode="out-in">
            <p
                v-if="labelScene"
                :key="labelScene.id"
                class="scene-caption pointer-events-none absolute bottom-0 left-1/2 z-10 -translate-x-1/2"
            >
                {{ labelScene.title }}
            </p>
        </Transition>

        <div
            v-if="popover"
            class="hotspot-popover-backdrop"
            :class="{
                'hotspot-popover-backdrop--light': popover.type === 'info',
            }"
            role="dialog"
            aria-modal="true"
            :aria-label="popover.title ?? 'Details'"
            @click.self="popover = null"
            @keydown.esc="popover = null"
        >
            <div class="hotspot-popover">
                <button
                    type="button"
                    class="hotspot-popover__close"
                    aria-label="Close"
                    @click="popover = null"
                >
                    ×
                </button>
                <!-- eslint-disable-next-line vue/no-v-html -- built from escaped text by tourMarkers -->
                <div v-html="popoverHtml" />
            </div>
        </div>

        <template v-if="scenes.length > 1 && layout !== 'minimal'">
            <template v-if="settings.show_prev_next">
                <button
                    type="button"
                    class="absolute top-1/2 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur transition hover:bg-black/70"
                    :class="layout === 'sidebar' ? 'left-[17rem]' : 'left-3'"
                    aria-label="Previous scene"
                    @click="step(-1)"
                >
                    <ChevronLeft class="size-5" />
                </button>
                <button
                    type="button"
                    class="absolute top-1/2 z-10 flex size-10 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 text-white backdrop-blur transition hover:bg-black/70"
                    :class="layout === 'classic' ? 'right-[15rem]' : 'right-3'"
                    aria-label="Next scene"
                    @click="step(1)"
                >
                    <ChevronRight class="size-5" />
                </button>
            </template>

            <!-- Carousel and gallery: a strip of scenes along the bottom -->
            <div
                v-if="
                    settings.show_scene_gallery &&
                    (layout === 'carousel' || layout === 'gallery')
                "
                class="absolute inset-x-0 bottom-12 z-10 flex flex-col items-center gap-2 px-3"
            >
                <button
                    type="button"
                    class="rounded-full bg-black/55 px-3 py-1 text-xs text-white backdrop-blur hover:bg-black/75"
                    :aria-expanded="galleryOpen"
                    @click="galleryOpen = !galleryOpen"
                >
                    {{
                        galleryOpen
                            ? 'Hide scenes'
                            : `Scenes (${scenes.length})`
                    }}
                </button>
                <div
                    v-show="galleryOpen"
                    class="flex max-w-full gap-2 overflow-x-auto rounded-lg bg-black/45 p-2 backdrop-blur"
                >
                    <button
                        v-for="scene in scenes"
                        :key="scene.id"
                        type="button"
                        class="group shrink-0 overflow-hidden rounded-md border-2 text-left transition"
                        :class="[
                            layout === 'gallery' ? 'w-44' : 'w-28',
                            scene.id === currentSceneId
                                ? 'border-white'
                                : 'border-transparent opacity-80 hover:opacity-100',
                        ]"
                        :aria-current="scene.id === currentSceneId"
                        @click="goTo(scene.id)"
                    >
                        <div class="aspect-[16/9] bg-white/10">
                            <img
                                v-if="scene.media.thumbnail_url"
                                :src="scene.media.thumbnail_url"
                                :alt="scene.title"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                        </div>
                        <div class="bg-black/60 px-1.5 py-1 text-white">
                            <p class="truncate text-[11px]">
                                {{ scene.title }}
                            </p>
                            <p
                                v-if="
                                    layout === 'gallery' &&
                                    scene.id === currentSceneId &&
                                    scene.description
                                "
                                class="mt-0.5 line-clamp-2 text-[10px] text-white/75"
                            >
                                {{ scene.description }}
                            </p>
                        </div>
                    </button>
                </div>
            </div>

            <!-- Classic (right) and sidebar (left): a scene list down the side -->
            <aside
                v-if="
                    settings.show_scene_gallery &&
                    (layout === 'classic' || layout === 'sidebar')
                "
                class="absolute top-0 bottom-10 z-10 flex flex-col gap-2 overflow-y-auto bg-black/60 p-2 text-white backdrop-blur"
                :class="layout === 'classic' ? 'right-0 w-56' : 'left-0 w-64'"
                aria-label="Scenes"
            >
                <button
                    v-for="scene in scenes"
                    :key="scene.id"
                    type="button"
                    class="shrink-0 overflow-hidden rounded-md border-2 text-left transition"
                    :class="
                        scene.id === currentSceneId
                            ? 'border-white'
                            : 'border-transparent opacity-80 hover:opacity-100'
                    "
                    :aria-current="scene.id === currentSceneId"
                    @click="goTo(scene.id)"
                >
                    <div class="aspect-[16/9] bg-white/10">
                        <img
                            v-if="scene.media.thumbnail_url"
                            :src="scene.media.thumbnail_url"
                            :alt="scene.title"
                            class="size-full object-cover"
                            loading="lazy"
                        />
                    </div>
                    <div class="bg-black/60 px-2 py-1">
                        <p class="truncate text-xs">{{ scene.title }}</p>
                        <p
                            v-if="
                                layout === 'sidebar' &&
                                scene.id === currentSceneId &&
                                scene.description
                            "
                            class="mt-0.5 line-clamp-3 text-[11px] text-white/75"
                        >
                            {{ scene.description }}
                        </p>
                    </div>
                </button>
            </aside>
        </template>

        <!-- View controls: auto-rotate (with speed), recenter, quality, layout -->
        <div
            class="absolute bottom-14 z-20"
            :class="layout === 'classic' ? 'right-[15rem]' : 'right-3'"
            @keydown.esc="menu = null"
        >
            <div
                v-if="menu"
                class="absolute right-0 bottom-full mb-2 w-60 rounded-lg border border-white/10 bg-slate-900/95 p-1 text-sm text-white shadow-xl backdrop-blur"
                role="menu"
            >
                <template v-if="menu === 'quality'">
                    <button
                        v-for="option in QUALITY_OPTIONS"
                        :key="option.value"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="prefs.quality.value === option.value"
                        class="flex w-full items-start gap-2 rounded-md px-3 py-2 text-left hover:bg-white/10"
                        @click="
                            prefs.setQuality(option.value);
                            menu = null;
                        "
                    >
                        <span class="flex-1">
                            <span
                                class="block"
                                :class="
                                    prefs.quality.value === option.value
                                        ? 'text-sky-400'
                                        : ''
                                "
                                >{{ option.label }}</span
                            >
                            <span class="block text-xs text-white/60">{{
                                option.hint
                            }}</span>
                        </span>
                        <Check
                            v-if="prefs.quality.value === option.value"
                            class="mt-0.5 size-4 text-sky-400"
                        />
                    </button>
                </template>
                <template v-else-if="menu === 'layout'">
                    <p
                        class="px-3 pt-2 pb-1 text-[11px] tracking-wide text-white/50 uppercase"
                    >
                        Layout
                    </p>
                    <button
                        v-for="option in LAYOUT_OPTIONS"
                        :key="option.value"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="layout === option.value"
                        class="flex w-full items-start gap-2 rounded-md px-3 py-2 text-left hover:bg-white/10"
                        @click="
                            prefs.setLayout(option.value, settings.layout);
                            menu = null;
                        "
                    >
                        <span class="flex-1">
                            <span
                                class="block"
                                :class="
                                    layout === option.value
                                        ? 'text-sky-400'
                                        : ''
                                "
                                >{{ option.label }}</span
                            >
                            <span class="block text-xs text-white/60">{{
                                option.hint
                            }}</span>
                        </span>
                        <Check
                            v-if="layout === option.value"
                            class="mt-0.5 size-4 text-sky-400"
                        />
                    </button>
                </template>
                <template v-else>
                    <p
                        class="px-3 pt-2 pb-1 text-[11px] tracking-wide text-white/50 uppercase"
                    >
                        Rotation speed
                    </p>
                    <button
                        v-for="option in SPEED_OPTIONS"
                        :key="option.value"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="speed === option.value"
                        class="flex w-full items-center gap-2 rounded-md px-3 py-2 text-left hover:bg-white/10"
                        @click="chooseSpeed(option.value)"
                    >
                        <span
                            class="flex-1"
                            :class="
                                speed === option.value ? 'text-sky-400' : ''
                            "
                            >{{ option.label }}</span
                        >
                        <Check
                            v-if="speed === option.value"
                            class="size-4 text-sky-400"
                        />
                    </button>
                </template>
            </div>

            <div
                class="flex items-center gap-0.5 rounded-full bg-black/55 p-1 text-white backdrop-blur"
                role="toolbar"
                aria-label="View controls"
            >
                <template v-if="layout !== 'minimal'">
                    <button
                        type="button"
                        class="flex size-9 items-center justify-center rounded-full hover:bg-white/15"
                        :aria-pressed="rotating"
                        :title="rotating ? 'Pause auto-rotate' : 'Auto-rotate'"
                        :aria-label="
                            rotating ? 'Pause auto-rotate' : 'Auto-rotate'
                        "
                        @click="toggleRotation"
                    >
                        <Pause v-if="rotating" class="size-4" />
                        <Play v-else class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="rounded-full px-2 py-1.5 text-xs hover:bg-white/15"
                        :class="{ 'bg-white/15': menu === 'speed' }"
                        title="Rotation speed"
                        aria-label="Rotation speed"
                        aria-haspopup="menu"
                        :aria-expanded="menu === 'speed'"
                        @click="toggleMenu('speed')"
                    >
                        {{
                            SPEED_OPTIONS.find(
                                (option) => option.value === speed,
                            )?.label
                        }}
                    </button>
                    <button
                        type="button"
                        class="flex size-9 items-center justify-center rounded-full hover:bg-white/15"
                        title="Recenter view"
                        aria-label="Recenter view"
                        @click="recenter"
                    >
                        <Crosshair class="size-4" />
                    </button>
                    <button
                        type="button"
                        class="flex items-center gap-1.5 rounded-full px-2.5 py-1.5 text-xs hover:bg-white/15"
                        :class="{ 'bg-white/15': menu === 'quality' }"
                        title="Image quality"
                        aria-label="Image quality"
                        aria-haspopup="menu"
                        :aria-expanded="menu === 'quality'"
                        @click="toggleMenu('quality')"
                    >
                        <SlidersHorizontal class="size-4" />
                        {{
                            QUALITY_OPTIONS.find(
                                (option) =>
                                    option.value === prefs.quality.value,
                            )?.label
                        }}
                    </button>
                </template>
                <button
                    type="button"
                    class="flex size-9 items-center justify-center rounded-full hover:bg-white/15"
                    :class="{ 'bg-white/15': menu === 'layout' }"
                    title="Change layout"
                    aria-label="Change layout"
                    aria-haspopup="menu"
                    :aria-expanded="menu === 'layout'"
                    @click="toggleMenu('layout')"
                >
                    <LayoutGrid class="size-4" />
                </button>
            </div>
        </div>
    </div>
</template>
