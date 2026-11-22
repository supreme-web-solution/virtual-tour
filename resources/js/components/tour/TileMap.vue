<script setup lang="ts">
import { Building2, MapPin, Minus, Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * A small interactive map on OpenStreetMap tiles: drag to pan, wheel or
 * buttons to zoom, pins that can be clicked or (in the editor) dragged.
 * No map library: Web Mercator maths and positioned tile images.
 */
export type MapPinData = {
    id: string;
    latitude: number;
    longitude: number;
    label: string;
    /** The scene the visitor is in now. */
    active?: boolean;
    /** The property itself: shown differently and not a scene. */
    property?: boolean;
};

const props = withDefaults(
    defineProps<{
        center: { latitude: number; longitude: number };
        zoom?: number;
        pins: MapPinData[];
        /** Frame all pins when the map first appears. */
        fit?: boolean;
        /** Pins can be dragged to a new place (editor). */
        draggablePins?: boolean;
        /** Clicking empty map emits a position (editor placing a pin). */
        placing?: boolean;
        color?: string;
    }>(),
    {
        zoom: 15,
        fit: false,
        draggablePins: false,
        placing: false,
        color: '#2563eb',
    },
);

const emit = defineEmits<{
    pinClick: [id: string];
    pinMove: [id: string, latitude: number, longitude: number];
    mapClick: [latitude: number, longitude: number];
}>();

const TILE = 256;
const MIN_ZOOM = 2;
const MAX_ZOOM = 19;

const element = ref<HTMLDivElement | null>(null);
const width = ref(400);
const height = ref(400);
const view = ref({
    latitude: props.center.latitude,
    longitude: props.center.longitude,
    zoom: props.zoom,
});
/** Positions of pins while they are being dragged. */
const dragged = ref<Record<string, { latitude: number; longitude: number }>>(
    {},
);
let resizeObserver: ResizeObserver | null = null;
let pan: {
    x: number;
    y: number;
    moved: boolean;
    startLat: number;
    startLng: number;
} | null = null;
let pinDrag: { id: string; x: number; y: number; moved: boolean } | null = null;
let lastWheel = 0;

const size = (zoom: number): number => TILE * 2 ** zoom;
const toX = (longitude: number, zoom: number): number =>
    ((longitude + 180) / 360) * size(zoom);
const toY = (latitude: number, zoom: number): number => {
    const sine = Math.min(
        0.9999,
        Math.max(-0.9999, Math.sin((latitude * Math.PI) / 180)),
    );

    return (
        (0.5 - Math.log((1 + sine) / (1 - sine)) / (4 * Math.PI)) * size(zoom)
    );
};
const toLongitude = (x: number, zoom: number): number =>
    (x / size(zoom)) * 360 - 180;
const toLatitude = (y: number, zoom: number): number => {
    const n = Math.PI - (2 * Math.PI * y) / size(zoom);

    return (180 / Math.PI) * Math.atan(0.5 * (Math.exp(n) - Math.exp(-n)));
};

const zoomLevel = computed(() => Math.round(view.value.zoom));
const originX = computed(
    () => toX(view.value.longitude, zoomLevel.value) - width.value / 2,
);
const originY = computed(
    () => toY(view.value.latitude, zoomLevel.value) - height.value / 2,
);

const tiles = computed(() => {
    const z = zoomLevel.value;
    const count = 2 ** z;
    const list: { key: string; url: string; left: number; top: number }[] = [];

    for (
        let x = Math.floor(originX.value / TILE);
        x <= Math.floor((originX.value + width.value) / TILE);
        x++
    ) {
        for (
            let y = Math.floor(originY.value / TILE);
            y <= Math.floor((originY.value + height.value) / TILE);
            y++
        ) {
            if (y < 0 || y >= count) {
                continue;
            }

            const wrapped = ((x % count) + count) % count;
            list.push({
                key: `${z}/${x}/${y}`,
                url: `https://tile.openstreetmap.org/${z}/${wrapped}/${y}.png`,
                left: Math.round(x * TILE - originX.value),
                top: Math.round(y * TILE - originY.value),
            });
        }
    }

    return list;
});

const placed = computed(() =>
    props.pins.map((pin) => {
        const position = dragged.value[pin.id] ?? pin;

        return {
            ...pin,
            left: toX(position.longitude, zoomLevel.value) - originX.value,
            top: toY(position.latitude, zoomLevel.value) - originY.value,
        };
    }),
);

function pointToCoordinates(
    clientX: number,
    clientY: number,
): { latitude: number; longitude: number } {
    const bounds = element.value?.getBoundingClientRect();
    const x = clientX - (bounds?.left ?? 0) + originX.value;
    const y = clientY - (bounds?.top ?? 0) + originY.value;

    return {
        latitude: toLatitude(y, zoomLevel.value),
        longitude: toLongitude(x, zoomLevel.value),
    };
}

function zoomBy(delta: number): void {
    view.value = {
        ...view.value,
        zoom: Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, zoomLevel.value + delta)),
    };
}

function onWheel(event: WheelEvent): void {
    const now = Date.now();

    if (now - lastWheel < 180) {
        return;
    }

    lastWheel = now;
    zoomBy(event.deltaY < 0 ? 1 : -1);
}

function onPointerDown(event: PointerEvent): void {
    if (event.button !== 0) {
        return;
    }

    pan = {
        x: event.clientX,
        y: event.clientY,
        moved: false,
        startLat: view.value.latitude,
        startLng: view.value.longitude,
    };
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp, { once: true });
}

function onPointerMove(event: PointerEvent): void {
    if (pinDrag) {
        if (!props.draggablePins) {
            return;
        }

        if (
            !pinDrag.moved &&
            Math.hypot(event.clientX - pinDrag.x, event.clientY - pinDrag.y) < 4
        ) {
            return;
        }

        pinDrag.moved = true;
        dragged.value = {
            ...dragged.value,
            [pinDrag.id]: pointToCoordinates(event.clientX, event.clientY),
        };

        return;
    }

    if (!pan) {
        return;
    }

    const dx = event.clientX - pan.x;
    const dy = event.clientY - pan.y;

    if (!pan.moved && Math.hypot(dx, dy) < 4) {
        return;
    }

    pan.moved = true;
    const z = zoomLevel.value;
    view.value = {
        ...view.value,
        latitude: Math.max(
            -85,
            Math.min(85, toLatitude(toY(pan.startLat, z) - dy, z)),
        ),
        longitude: toLongitude(toX(pan.startLng, z) - dx, z),
    };
}

function onPointerUp(event: PointerEvent): void {
    window.removeEventListener('pointermove', onPointerMove);

    if (pinDrag) {
        const finished = pinDrag;
        pinDrag = null;
        const position = dragged.value[finished.id];

        if (finished.moved && position) {
            // The dragged position stays until the saved pin arrives.
            emit('pinMove', finished.id, position.latitude, position.longitude);
        } else {
            emit('pinClick', finished.id);
        }

        return;
    }

    if (pan && !pan.moved) {
        const { latitude, longitude } = pointToCoordinates(
            event.clientX,
            event.clientY,
        );

        if (props.placing) {
            emit('mapClick', latitude, longitude);
        }
    }

    pan = null;
}

function onPinDown(event: PointerEvent, pin: MapPinData): void {
    if (event.button !== 0) {
        return;
    }

    event.stopPropagation();

    // The property marker is not a scene: it cannot be clicked or moved.
    if (pin.property) {
        return;
    }

    pinDrag = { id: pin.id, x: event.clientX, y: event.clientY, moved: false };
    window.addEventListener('pointermove', onPointerMove);
    window.addEventListener('pointerup', onPointerUp, { once: true });
}

/**
 * Zoom and centre so that every pin is visible.
 */
function fitToPins(): void {
    const points = props.pins;

    if (!points.length) {
        return;
    }

    const padding = 56;

    for (let z = MAX_ZOOM - 2; z >= MIN_ZOOM; z--) {
        const xs = points.map((pin) => toX(pin.longitude, z));
        const ys = points.map((pin) => toY(pin.latitude, z));

        if (
            Math.max(...xs) - Math.min(...xs) <= width.value - padding * 2 &&
            Math.max(...ys) - Math.min(...ys) <= height.value - padding * 2
        ) {
            view.value = {
                zoom: z,
                longitude: toLongitude(
                    (Math.max(...xs) + Math.min(...xs)) / 2,
                    z,
                ),
                latitude: toLatitude(
                    (Math.max(...ys) + Math.min(...ys)) / 2,
                    z,
                ),
            };

            return;
        }
    }
}

onMounted(() => {
    if (!element.value) {
        return;
    }

    width.value = element.value.clientWidth || 400;
    height.value = element.value.clientHeight || 400;

    if (props.fit) {
        fitToPins();
    }

    resizeObserver = new ResizeObserver(() => {
        width.value = element.value?.clientWidth || width.value;
        height.value = element.value?.clientHeight || height.value;
    });
    resizeObserver.observe(element.value);
});

onBeforeUnmount(() => {
    resizeObserver?.disconnect();
    window.removeEventListener('pointermove', onPointerMove);
});

// A saved pin position replaces its temporary drag position.
watch(
    () =>
        props.pins
            .map((pin) => `${pin.id}:${pin.latitude}:${pin.longitude}`)
            .join('|'),
    () => (dragged.value = {}),
);

defineExpose({
    focusOn(latitude: number, longitude: number): void {
        view.value = { ...view.value, latitude, longitude };
    },
});
</script>

<template>
    <div
        ref="element"
        class="relative size-full touch-none overflow-hidden bg-[#e5e3df] select-none"
        :class="
            placing ? 'cursor-crosshair' : 'cursor-grab active:cursor-grabbing'
        "
        role="application"
        aria-label="Map"
        @pointerdown="onPointerDown"
        @wheel.prevent="onWheel"
    >
        <img
            v-for="tile in tiles"
            :key="tile.key"
            :src="tile.url"
            alt=""
            width="256"
            height="256"
            draggable="false"
            class="pointer-events-none absolute max-w-none"
            :style="{ left: `${tile.left}px`, top: `${tile.top}px` }"
        />

        <button
            v-for="pin in placed"
            :key="pin.id"
            type="button"
            class="absolute z-10 flex -translate-x-1/2 -translate-y-full flex-col items-center"
            :class="[
                pin.property
                    ? 'cursor-default'
                    : draggablePins
                      ? 'cursor-move'
                      : 'cursor-pointer',
            ]"
            :style="{ left: `${pin.left}px`, top: `${pin.top}px` }"
            :aria-label="
                pin.property ? `Property: ${pin.label}` : `Go to ${pin.label}`
            "
            :title="pin.label"
            @pointerdown="onPinDown($event, pin)"
        >
            <span
                v-if="pin.active || pin.property"
                class="mb-1 max-w-36 truncate rounded bg-black/75 px-1.5 py-0.5 text-[11px] text-white"
                >{{ pin.label }}</span
            >
            <span
                class="flex items-center justify-center rounded-full border-2 border-white text-white shadow-md transition-transform"
                :class="[
                    pin.active
                        ? 'size-9 scale-110 ring-4 ring-white/60'
                        : 'size-7 hover:scale-110',
                ]"
                :style="{ backgroundColor: pin.property ? '#111827' : color }"
            >
                <Building2 v-if="pin.property" class="size-4" />
                <MapPin v-else class="size-4" />
            </span>
            <span
                class="-mt-1 size-2 rotate-45 border-r-2 border-b-2 border-white"
                :style="{ backgroundColor: pin.property ? '#111827' : color }"
            />
        </button>

        <div
            class="absolute top-2 right-2 z-20 flex flex-col overflow-hidden rounded-md bg-white shadow"
            @pointerdown.stop
        >
            <button
                type="button"
                class="flex size-8 items-center justify-center text-gray-800 hover:bg-gray-100"
                aria-label="Zoom in"
                @click="zoomBy(1)"
            >
                <Plus class="size-4" />
            </button>
            <button
                type="button"
                class="flex size-8 items-center justify-center border-t text-gray-800 hover:bg-gray-100"
                aria-label="Zoom out"
                @click="zoomBy(-1)"
            >
                <Minus class="size-4" />
            </button>
        </div>

        <a
            href="https://www.openstreetmap.org/copyright"
            target="_blank"
            rel="noopener"
            class="absolute right-0 bottom-0 z-20 bg-white/80 px-1.5 py-0.5 text-[10px] text-gray-700"
            @pointerdown.stop
            >© OpenStreetMap contributors</a
        >
    </div>
</template>
