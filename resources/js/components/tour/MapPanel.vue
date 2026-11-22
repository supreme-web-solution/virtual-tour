<script setup lang="ts">
import { Building2, Crosshair, MapPin, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import type { MapPinData } from '@/components/tour/TileMap.vue';
import TileMap from '@/components/tour/TileMap.vue';
import PanelSection from '@/components/tour/PanelSection.vue';
import { Button } from '@/components/ui/button';
import { Switch } from '@/components/ui/switch';
import type { TourEditor } from '@/composables/useTourEditor';

/**
 * Pin each scene on the map. In the published tour, visitors click a pin to
 * move to that scene, and the pin of the scene they are in is highlighted.
 */
const props = defineProps<{
    editor: TourEditor;
    canEdit: boolean;
}>();

const emit = defineEmits<{
    update: [data: Record<string, unknown>];
}>();

const tour = computed(() => props.editor.tour.value);
const currentSceneId = computed(
    () => props.editor.currentScene.value?.id ?? null,
);
const placingSceneId = ref<string | null>(null);

const propertyLocation = computed(
    () => tour.value.map_settings.property_location,
);
const pinned = computed(() =>
    tour.value.scenes.filter((scene) => scene.location !== null),
);

// Where the map opens: fixed when the panel opens, so it never jumps while pins are placed.
const start = (() => {
    const first = tour.value.scenes.find(
        (scene) => scene.location !== null,
    )?.location;
    const location = propertyLocation.value ?? first ?? null;

    return {
        center: location ?? { latitude: 20, longitude: 0 },
        zoom: location ? 16 : 2,
        fit: pinned.value.length > 0,
    };
})();

const pins = computed<MapPinData[]>(() => [
    ...(propertyLocation.value
        ? [
              {
                  id: 'property',
                  latitude: propertyLocation.value.latitude,
                  longitude: propertyLocation.value.longitude,
                  label: tour.value.property?.name ?? 'Property',
                  property: true,
              },
          ]
        : []),
    ...pinned.value.map((scene) => ({
        id: scene.id,
        latitude: scene.location?.latitude ?? 0,
        longitude: scene.location?.longitude ?? 0,
        label: scene.title,
        active: scene.id === currentSceneId.value,
    })),
]);

const placingTitle = computed(
    () =>
        tour.value.scenes.find((scene) => scene.id === placingSceneId.value)
            ?.title ?? null,
);

function round(value: number): number {
    return Math.round(value * 1e7) / 1e7;
}

function place(latitude: number, longitude: number): void {
    if (!placingSceneId.value) {
        return;
    }

    void props.editor.updateScene(placingSceneId.value, {
        latitude: round(latitude),
        longitude: round(longitude),
    });
    placingSceneId.value = null;
}

function move(sceneId: string, latitude: number, longitude: number): void {
    void props.editor.updateScene(sceneId, {
        latitude: round(latitude),
        longitude: round(longitude),
    });
}

function remove(sceneId: string): void {
    void props.editor.updateScene(sceneId, { latitude: null, longitude: null });
}

function usePropertyLocation(sceneId: string): void {
    if (propertyLocation.value) {
        move(
            sceneId,
            propertyLocation.value.latitude,
            propertyLocation.value.longitude,
        );
    }
}

function onPinClick(id: string): void {
    placingSceneId.value = null;
    props.editor.selectScene(id);
}
</script>

<template>
    <div class="space-y-4 p-3">
        <PanelSection
            title="Map"
            :icon="MapPin"
            tone="emerald"
            help="Shows visitors where the property is on an OpenStreetMap map, with a pin per scene. Visitors tap a pin to jump to that scene. No API key needed."
        >
            <label
                class="flex items-center justify-between gap-3 rounded-lg border bg-muted/30 px-3 py-2 text-sm"
                ><span
                    >Show the map in the published tour
                    <span
                        v-if="!tour.map_settings.has_coordinates"
                        class="block text-xs text-muted-foreground"
                    >
                        Place at least one pin to turn this on.
                    </span></span
                ><Switch
                    :model-value="tour.map_settings.enabled"
                    :disabled="!canEdit || !tour.map_settings.has_coordinates"
                    @update:model-value="
                        emit('update', {
                            map_settings: { enabled: $event === true },
                        })
                    "
            /></label>
        </PanelSection>

        <PanelSection
            title="Scene map"
            :icon="Crosshair"
            tone="sky"
            description="Pin each scene where it is"
        >
            <div
                class="relative aspect-[4/3] overflow-hidden rounded-lg border"
            >
                <TileMap
                    :center="start.center"
                    :zoom="start.zoom"
                    :fit="start.fit"
                    :pins="pins"
                    :placing="placingSceneId !== null"
                    :draggable-pins="canEdit"
                    @map-click="place"
                    @pin-move="move"
                    @pin-click="onPinClick"
                />
                <div
                    v-if="placingTitle"
                    class="pointer-events-none absolute top-2 left-1/2 z-30 -translate-x-1/2 rounded-full bg-black/75 px-3 py-1 text-xs text-white shadow"
                >
                    Click the map to place “{{ placingTitle }}”
                </div>
            </div>

            <p
                v-if="canEdit && pinned.length"
                class="text-xs text-muted-foreground"
            >
                Drag a pin to move it.
            </p>
        </PanelSection>

        <PanelSection title="Scenes" :icon="MapPin" tone="emerald">
            <ul class="divide-y rounded-lg border bg-card">
                <li
                    v-for="scene in tour.scenes"
                    :key="scene.id"
                    class="flex items-center gap-2 p-2 text-sm"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center rounded-full"
                        :class="
                            scene.location
                                ? 'bg-primary text-primary-foreground'
                                : 'bg-muted text-muted-foreground'
                        "
                    >
                        <MapPin class="size-3.5" />
                    </span>
                    <button
                        type="button"
                        class="min-w-0 flex-1 truncate text-left hover:underline"
                        :class="{ 'font-medium': scene.id === currentSceneId }"
                        @click="editor.selectScene(scene.id)"
                    >
                        {{ scene.title }}
                    </button>
                    <template v-if="canEdit">
                        <Button
                            v-if="propertyLocation && !scene.location"
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            title="Use the property location"
                            aria-label="Use the property location"
                            @click="usePropertyLocation(scene.id)"
                        >
                            <Building2 />
                        </Button>
                        <Button
                            variant="outline"
                            size="sm"
                            class="h-7 px-2 text-xs"
                            :class="{
                                'border-primary': placingSceneId === scene.id,
                            }"
                            @click="
                                placingSceneId =
                                    placingSceneId === scene.id
                                        ? null
                                        : scene.id
                            "
                        >
                            <Crosshair />
                            {{ scene.location ? 'Move' : 'Place' }}
                        </Button>
                        <Button
                            v-if="scene.location"
                            variant="ghost"
                            size="icon"
                            class="size-7 text-destructive hover:text-destructive"
                            :aria-label="`Remove the pin for ${scene.title}`"
                            @click="remove(scene.id)"
                        >
                            <Trash2 />
                        </Button>
                    </template>
                </li>
            </ul>
        </PanelSection>
    </div>
</template>
