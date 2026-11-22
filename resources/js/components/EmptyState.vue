<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    icon: LucideIcon;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        class="relative flex flex-col items-center justify-center overflow-hidden rounded-2xl border bg-card px-6 py-16 text-center shadow-sm"
    >
        <div
            class="pointer-events-none absolute inset-x-0 top-0 h-32 bg-gradient-to-b from-primary/10 to-transparent"
            aria-hidden="true"
        />
        <div
            class="relative mb-5 flex size-14 items-center justify-center rounded-2xl border bg-background shadow-sm ring-4 ring-primary/10"
        >
            <component :is="icon" class="size-6 text-primary" />
        </div>
        <h3 class="relative text-base font-semibold tracking-tight">
            {{ title }}
        </h3>
        <p
            v-if="description"
            class="relative mt-1.5 max-w-sm text-sm text-muted-foreground"
        >
            {{ description }}
        </p>
        <div v-if="$slots.default" class="relative mt-6">
            <slot />
        </div>
    </div>
</template>
