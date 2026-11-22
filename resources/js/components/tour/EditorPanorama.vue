<script setup lang="ts">
import '@photo-sphere-viewer/core/index.css';
import '@photo-sphere-viewer/markers-plugin/index.css';
import { Viewer } from '@photo-sphere-viewer/core';
import type { MarkerConfig } from '@photo-sphere-viewer/markers-plugin';
import { MarkersPlugin } from '@photo-sphere-viewer/markers-plugin';
import { onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';
import { flatPhotoPanoData } from '@/lib/flatPhoto';
import type { SphericalDegrees } from '@/lib/sphere';
import { toDegrees, toRadians } from '@/lib/sphere';
import {
    draftMarker,
    hotspotMarker,
    prefersMobilePanorama,
} from '@/lib/tourMarkers';
import type { PinPreview } from '@/lib/tourMarkers';
import type { InitialView, TourScene } from '@/types';

const props = defineProps<{
    scene: TourScene | null;
    sceneTitles: Record<string, string>;
    selectedHotspotId: string | null;
    draft: SphericalDegrees | null;
    /** The pin as the hotspot form currently describes it. */
    preview?: PinPreview | null;
    placing: boolean;
    /** Editors can drag existing hotspots to a new position. */
    draggable?: boolean;
}>();

const emit = defineEmits<{
    place: [position: SphericalDegrees];
    selectHotspot: [hotspotId: string];
    moveHotspot: [hotspotId: string, position: SphericalDegrees];
}>();

const container = ref<HTMLDivElement | null>(null);
const viewer = shallowRef<Viewer | null>(null);
const markers = shallowRef<MarkersPlugin | null>(null);
const loadedSceneId = ref<string | null>(null);
const loading = ref(false);

function panoramaUrl(scene: TourScene): string {
    return prefersMobilePanorama()
        ? scene.media.panorama_mobile_url
        : scene.media.panorama_url;
}

function buildMarkers(): MarkerConfig[] {
    if (!props.scene) {
        return [];
    }

    const list = props.scene.hotspots.map((raw) => {
        const hotspot =
            props.preview && props.preview.id === raw.id
                ? {
                      ...raw,
                      type: props.preview.type,
                      settings: props.preview.settings,
                  }
                : raw;

        return hotspotMarker(hotspot, {
            selected: hotspot.id === props.selectedHotspotId,
            label:
                hotspot.type === 'navigation'
                    ? (hotspot.title ??
                      (hotspot.target_scene_id
                          ? props.sceneTitles[hotspot.target_scene_id]
                          : null))
                    : props.preview?.id === hotspot.id
                      ? props.preview.label
                      : hotspot.title,
        });
    });

    if (props.draft) {
        list.push(draftMarker(props.draft, props.preview ?? null));
    }

    return list;
}

function renderMarkers(): void {
    markers.value?.setMarkers(buildMarkers());
}

/**
 * Swap the quick preview for the full-size image once it has downloaded,
 * keeping the camera where the user left it.
 */
async function upgradePanorama(scene: TourScene): Promise<void> {
    const full = panoramaUrl(scene);

    if (full === scene.media.panorama_preview_url || !viewer.value) {
        return;
    }

    await new Promise<void>((resolve) => {
        const image = new Image();
        image.onload = () => resolve();
        image.onerror = () => resolve();
        image.src = full;
    });

    if (!viewer.value || loadedSceneId.value !== scene.id) {
        return;
    }

    const view = viewer.value;
    await view.setPanorama(full, {
        position: view.getPosition(),
        zoom: view.getZoomLevel(),
        transition: false,
        showLoader: false,
        panoData: flatPhotoPanoData(scene.media),
    });
    renderMarkers();
}

async function loadScene(scene: TourScene | null): Promise<void> {
    if (!viewer.value) {
        return;
    }

    if (!scene) {
        loadedSceneId.value = null;
        markers.value?.clearMarkers();

        return;
    }

    loading.value = true;
    markers.value?.clearMarkers();

    try {
        await viewer.value.setPanorama(scene.media.panorama_preview_url, {
            position: toRadians(scene.initial_view),
            zoom: scene.initial_view.zoom,
            panoData: flatPhotoPanoData(scene.media),
            transition:
                loadedSceneId.value === null
                    ? false
                    : { speed: 400, rotation: false, effect: 'fade' },
            showLoader: true,
        });
        loadedSceneId.value = scene.id;
        renderMarkers();
        void upgradePanorama(scene);
    } finally {
        loading.value = false;
    }
}

/**
 * The current camera direction and zoom, in the units the backend stores.
 */
function currentView(): InitialView | null {
    if (!viewer.value) {
        return null;
    }

    return {
        ...toDegrees(viewer.value.getPosition()),
        zoom: Math.round(viewer.value.getZoomLevel()),
    };
}

/**
 * Animate the camera to a stored view (used for "preview start view").
 */
function goToView(view: InitialView): void {
    viewer.value?.animate({
        ...toRadians(view),
        zoom: view.zoom,
        speed: '8rpm',
    });
}

defineExpose({ currentView, goToView });

// --- Drag a hotspot to move it ------------------------------------------------

const DRAG_THRESHOLD_PX = 5;

type Drag = {
    hotspotId: string;
    startX: number;
    startY: number;
    moved: boolean;
    position: SphericalDegrees | null;
};

let drag: Drag | null = null;

function hotspotIdFromEvent(event: PointerEvent): string | null {
    const marker = (event.target as HTMLElement | null)?.closest<HTMLElement>(
        '.psv-marker',
    );
    const id = marker?.id.startsWith('psv-marker-')
        ? marker.id.slice('psv-marker-'.length)
        : null;

    return id && id !== 'draft' ? id : null;
}

function onPointerDown(event: PointerEvent): void {
    if (!props.draggable || props.placing || event.button !== 0) {
        return;
    }

    const hotspotId = hotspotIdFromEvent(event);

    if (!hotspotId) {
        return;
    }

    // Stop the viewer from panning while a hotspot is being dragged.
    event.stopPropagation();
    drag = {
        hotspotId,
        startX: event.clientX,
        startY: event.clientY,
        moved: false,
        position: null,
    };
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp, { once: true });
    window.addEventListener('pointercancel', onPointerUp, { once: true });
}

function onPointerMove(event: PointerEvent): void {
    if (!drag || !viewer.value || !container.value) {
        return;
    }

    if (
        !drag.moved &&
        Math.hypot(event.clientX - drag.startX, event.clientY - drag.startY) <
            DRAG_THRESHOLD_PX
    ) {
        return;
    }

    drag.moved = true;
    const bounds = container.value.getBoundingClientRect();
    const position = viewer.value.dataHelper.viewerCoordsToSphericalCoords({
        x: event.clientX - bounds.left,
        y: event.clientY - bounds.top,
    });

    drag.position = toDegrees({ yaw: position.yaw, pitch: position.pitch });
    markers.value?.updateMarker({
        id: drag.hotspotId,
        position: { yaw: position.yaw, pitch: position.pitch },
    });
}

function onPointerUp(): void {
    window.removeEventListener('pointermove', onPointerMove);
    window.removeEventListener('pointercancel', onPointerUp);

    const finished = drag;
    drag = null;

    if (!finished) {
        return;
    }

    if (finished.moved && finished.position) {
        emit('moveHotspot', finished.hotspotId, finished.position);
    } else {
        // A plain click selects the hotspot (the pointerdown was intercepted).
        emit('selectHotspot', finished.hotspotId);
    }
}

onMounted(() => {
    if (!container.value) {
        return;
    }

    const instance = new Viewer({
        container: container.value,
        navbar: ['zoom', 'move', 'fullscreen'],
        defaultZoomLvl: 50,
        touchmoveTwoFingers: false,
        mousewheelCtrlKey: false,
        plugins: [MarkersPlugin],
    });

    viewer.value = instance;
    markers.value = instance.getPlugin<MarkersPlugin>(MarkersPlugin);

    instance.addEventListener('click', ({ data }) => {
        if (!props.placing || data.rightclick) {
            return;
        }

        emit('place', toDegrees({ yaw: data.yaw, pitch: data.pitch }));
    });

    markers.value.addEventListener('select-marker', ({ marker }) => {
        const id = (marker.data as { hotspotId?: string } | undefined)
            ?.hotspotId;

        if (id && id !== 'draft' && !props.placing) {
            emit('selectHotspot', id);
        }
    });

    // Capture phase: runs before the viewer's own pan handling.
    container.value.addEventListener('pointerdown', onPointerDown, true);

    void loadScene(props.scene);
});

onBeforeUnmount(() => {
    container.value?.removeEventListener('pointerdown', onPointerDown, true);
    window.removeEventListener('pointermove', onPointerMove);
    viewer.value?.destroy();
    viewer.value = null;
});

// Reload only when the panorama itself changes; hotspot edits just redraw markers.
watch(
    () => [props.scene?.id, props.scene?.media.id] as const,
    ([sceneId, mediaId], previous) => {
        if (sceneId !== previous?.[0] || mediaId !== previous?.[1]) {
            void loadScene(props.scene);
        }
    },
);

watch(
    () => [
        props.scene?.hotspots,
        props.selectedHotspotId,
        props.draft,
        props.preview,
        props.sceneTitles,
    ],
    () => {
        if (loadedSceneId.value === props.scene?.id) {
            renderMarkers();
        }
    },
    { deep: true },
);
</script>

<template>
    <div class="relative size-full" :class="{ 'tour-placing': placing }">
        <div ref="container" class="size-full" />
        <div
            v-if="placing"
            class="pointer-events-none absolute top-3 left-1/2 -translate-x-1/2 rounded-full bg-black/75 px-4 py-1.5 text-sm text-white shadow"
        >
            Click where the hotspot should go
        </div>
        <div
            v-else-if="draggable && scene?.hotspots.length"
            class="pointer-events-none absolute bottom-14 left-1/2 -translate-x-1/2 rounded-full bg-black/60 px-3 py-1 text-xs text-white/90"
        >
            Drag a hotspot to move it
        </div>
    </div>
</template>
