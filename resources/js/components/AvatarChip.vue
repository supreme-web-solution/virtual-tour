<script setup lang="ts">
import { computed } from 'vue';
import { accentFor, accents } from '@/lib/accent';

const props = withDefaults(
    defineProps<{
        name: string;
        seed?: string;
        size?: 'xs' | 'sm' | 'md';
    }>(),
    { seed: undefined, size: 'md' },
);

const initial = computed(
    () => props.name.trim().charAt(0).toUpperCase() || '?',
);
const tone = computed(() => accents[accentFor(props.seed ?? props.name)]);
</script>

<template>
    <span
        class="grid shrink-0 place-items-center rounded-full font-semibold"
        :class="[
            tone,
            size === 'xs'
                ? 'size-4 text-[9px]'
                : size === 'sm'
                  ? 'size-7 text-xs'
                  : 'size-9 text-sm',
        ]"
        aria-hidden="true"
        >{{ initial }}</span
    >
</template>
