<script setup lang="ts">
import { Info } from '@lucide/vue';
import type { Component } from 'vue';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
        /** Plain-language explanation shown in a tooltip on the info icon. */
        help?: string;
    }>(),
    { tone: 'sky', description: undefined, help: undefined },
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
            <TooltipProvider v-if="help" :delay-duration="0">
                <Tooltip>
                    <TooltipTrigger as-child>
                        <button
                            type="button"
                            class="ml-auto shrink-0 rounded-full text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="`About ${title}`"
                        >
                            <Info class="size-4" />
                        </button>
                    </TooltipTrigger>
                    <TooltipContent side="left" class="max-w-64 text-left">
                        {{ help }}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </header>
        <div class="space-y-3 p-3">
            <slot />
        </div>
    </section>
</template>
