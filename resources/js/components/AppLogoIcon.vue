<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- A 360° mark: a viewing lens ringed by a tilted orbit. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        fill="none"
        :class="className"
        v-bind="$attrs"
    >
        <circle
            cx="24"
            cy="24"
            r="14"
            fill="none"
            stroke="currentColor"
            stroke-width="3.4"
        />
        <ellipse
            cx="24"
            cy="24"
            rx="20"
            ry="7.5"
            transform="rotate(-30 24 24)"
            fill="none"
            stroke="currentColor"
            stroke-width="2.8"
        />
        <circle cx="24" cy="24" r="3.6" fill="currentColor" />
    </svg>
</template>
