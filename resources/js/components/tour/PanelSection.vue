<script setup lang="ts">
import type { Component } from 'vue';
import { accents } from '@/lib/accent';
import type { Accent } from '@/lib/accent';

/**
 * One clearly bounded group of settings in an editor panel: a card with a
 * coloured icon, a title and an optional hint in its header.
 */
withDefaults(
    defineProps<{
        title: string;
        icon: Component;
        tone?: Accent;
        description?: string;
    }>(),
    { tone: 'sky', description: undefined },
);
</script>

<template>
    <section
        class="min-w-0 overflow-hidden rounded-xl border bg-card shadow-xs"
    >
        <header
            class="flex items-center gap-2.5 border-b bg-muted/40 px-3 py-2.5"
        >
            <span
                class="grid size-7 shrink-0 place-items-center rounded-lg [&>svg]:size-3.5"
                :class="accents[tone]"
            >
                <component :is="icon" />
            </span>
            <div class="min-w-0">
                <h3 class="truncate text-sm leading-tight font-semibold">
                    {{ title }}
                </h3>
                <p
                    v-if="description"
                    class="truncate text-xs text-muted-foreground"
                >
                    {{ description }}
                </p>
            </div>
        </header>
        <div class="space-y-3 p-3">
            <slot />
        </div>
    </section>
</template>
