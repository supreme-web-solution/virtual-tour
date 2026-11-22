<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import type { Floorplan, TourScene } from '@/types';

const props = defineProps<{
    floorplans: Floorplan[];
    scenes: TourScene[];
    currentSceneId: string | null;
}>();

const emit = defineEmits<{ go: [sceneId: string] }>();

const titles = computed(() =>
    Object.fromEntries(props.scenes.map((scene) => [scene.id, scene.title])),
);

/** Show the plan that contains the current scene, if any. */
function planForCurrent(): string | null {
    return (
        props.floorplans.find((plan) =>
            plan.markers.some(
                (marker) => marker.scene_id === props.currentSceneId,
            ),
        )?.id ??
        props.floorplans[0]?.id ??
        null
    );
}

const selectedId = ref<string | null>(planForCurrent());
const plan = computed(
    () => props.floorplans.find((item) => item.id === selectedId.value) ?? null,
);

watch(
    () => props.currentSceneId,
    () => {
        selectedId.value = planForCurrent();
    },
);
</script>

<template>
    <div class="space-y-3">
        <div v-if="floorplans.length > 1" class="flex flex-wrap gap-1.5">
            <button
                v-for="item in floorplans"
                :key="item.id"
                type="button"
                class="rounded-full px-3 py-1 text-xs transition"
                :class="
                    item.id === selectedId
                        ? 'bg-white text-black'
                        : 'bg-white/10 hover:bg-white/20'
                "
                @click="selectedId = item.id"
            >
                {{ item.title }}
            </button>
        </div>
        <div v-if="plan" class="relative overflow-hidden rounded-md bg-white">
            <img
                :src="plan.image_url"
                :alt="plan.title"
                class="block w-full"
                draggable="false"
            />
            <button
                v-for="marker in plan.markers"
                :key="marker.scene_id"
                type="button"
                class="absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white shadow transition hover:scale-110"
                :class="
                    marker.scene_id === currentSceneId
                        ? 'size-6 bg-[var(--tour-accent)] ring-4 ring-[var(--tour-accent)]/40'
                        : 'size-5 bg-[var(--tour-primary)]'
                "
                :style="{
                    left: `${marker.x * 100}%`,
                    top: `${marker.y * 100}%`,
                }"
                :title="titles[marker.scene_id]"
                :aria-label="`Go to ${titles[marker.scene_id]}`"
                :aria-current="marker.scene_id === currentSceneId"
                @click="emit('go', marker.scene_id)"
            />
        </div>
        <p class="text-xs text-white/60">Tap a dot to jump to that room.</p>
    </div>
</template>
