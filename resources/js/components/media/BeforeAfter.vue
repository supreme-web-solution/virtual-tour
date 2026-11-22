<script setup lang="ts">
import { ref } from 'vue';

/**
 * Compare two versions of a photo: drag the divider (or use the slider with
 * the keyboard). Both images are shown whole, in the same frame.
 */
defineProps<{
    before: string;
    after: string;
    beforeLabel?: string;
    afterLabel?: string;
}>();

const position = ref(50);
</script>

<template>
    <div
        class="relative aspect-[3/2] w-full touch-none overflow-hidden rounded-lg bg-black select-none"
    >
        <img
            :src="after"
            alt="Enhanced photo"
            class="absolute inset-0 size-full object-contain"
            draggable="false"
        />
        <img
            :src="before"
            alt="Original photo"
            class="absolute inset-0 size-full object-contain"
            :style="{ clipPath: `inset(0 ${100 - position}% 0 0)` }"
            draggable="false"
        />

        <span
            class="pointer-events-none absolute top-2 left-2 rounded bg-black/65 px-2 py-0.5 text-xs text-white"
            >{{ beforeLabel ?? 'Original' }}</span
        >
        <span
            class="pointer-events-none absolute top-2 right-2 rounded bg-black/65 px-2 py-0.5 text-xs text-white"
            >{{ afterLabel ?? 'Enhanced' }}</span
        >

        <div
            class="pointer-events-none absolute inset-y-0 w-0.5 bg-white shadow"
            :style="{ left: `${position}%` }"
        >
            <span
                class="absolute top-1/2 left-1/2 flex size-8 -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full bg-white text-xs font-bold text-gray-800 shadow"
                >⇆</span
            >
        </div>

        <input
            v-model.number="position"
            type="range"
            min="0"
            max="100"
            step="0.5"
            class="absolute inset-0 size-full cursor-ew-resize opacity-0"
            aria-label="Compare original and enhanced photo"
        />
    </div>
</template>
