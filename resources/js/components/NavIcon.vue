<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';
import { onMounted, ref } from 'vue';

/**
 * A sidebar icon that redraws itself, stroke by stroke, when its menu item is
 * hovered (see .nav-icon in app.css). Every shape is given a normalised path
 * length so one animation fits all icons.
 */
defineProps<{ icon: LucideIcon }>();

const root = ref<HTMLElement | null>(null);

onMounted(() => {
    root.value
        ?.querySelectorAll(
            'path, circle, line, rect, polyline, polygon, ellipse',
        )
        .forEach((shape) => shape.setAttribute('pathLength', '1'));
});
</script>

<template>
    <span ref="root" class="nav-icon inline-flex size-4 shrink-0">
        <component :is="icon" class="size-4" />
    </span>
</template>
