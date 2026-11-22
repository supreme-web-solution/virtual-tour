<script setup lang="ts">
import type { Component } from 'vue';
import { accents } from '@/lib/accent';
import type { Accent } from '@/lib/accent';

withDefaults(
    defineProps<{
        title: string;
        description?: string;
        icon?: Component;
        tone?: Accent;
    }>(),
    { description: undefined, icon: undefined, tone: 'sky' },
);
</script>

<template>
    <div
        class="flex flex-col gap-3 xl:flex-row xl:items-start xl:justify-between"
    >
        <div class="flex min-w-0 flex-1 items-start gap-3">
            <span
                v-if="icon"
                class="grid size-10 shrink-0 place-items-center rounded-xl"
                :class="accents[tone]"
                aria-hidden="true"
            >
                <component :is="icon" class="size-5" />
            </span>
            <div class="min-w-0 space-y-1">
                <h1 class="truncate text-xl font-semibold tracking-tight">
                    {{ title }}
                </h1>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
                <slot name="meta" />
            </div>
        </div>
        <div
            v-if="$slots.actions"
            class="flex flex-wrap items-center gap-2 xl:shrink-0 xl:flex-nowrap xl:justify-end"
        >
            <slot name="actions" />
        </div>
    </div>
</template>
