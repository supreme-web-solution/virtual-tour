<script setup lang="ts">
import { computed } from 'vue';
import type { MapPinData } from '@/components/tour/TileMap.vue';
import TileMap from '@/components/tour/TileMap.vue';
import type { PublicTour } from '@/types';

const props = defineProps<{
    map: NonNullable<PublicTour['map']>;
    currentSceneId: string | null;
}>();

const emit = defineEmits<{ go: [sceneId: string] }>();

const pins = computed<MapPinData[]>(() => [
    ...(props.map.property
        ? [
              {
                  id: 'property',
                  latitude: props.map.property.latitude,
                  longitude: props.map.property.longitude,
                  label: props.map.label ?? 'Property',
                  property: true,
              },
          ]
        : []),
    ...props.map.pins.map((pin) => ({
        id: pin.scene_id,
        latitude: pin.latitude,
        longitude: pin.longitude,
        label: pin.title,
        active: pin.scene_id === props.currentSceneId,
    })),
]);

function onPinClick(id: string): void {
    if (id !== 'property') {
        emit('go', id);
    }
}
</script>

<template>
    <div class="space-y-3">
        <p v-if="map.label" class="text-sm text-white/85">{{ map.label }}</p>
        <div class="relative aspect-square overflow-hidden rounded-md">
            <TileMap
                :center="{ latitude: map.latitude, longitude: map.longitude }"
                :zoom="16"
                :fit="map.pins.length > 0"
                :pins="pins"
                color="#2563eb"
                @pin-click="onPinClick"
            />
        </div>
        <p v-if="map.pins.length" class="text-xs text-white/60">
            Tap a pin to jump to that scene.
        </p>
    </div>
</template>
