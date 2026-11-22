<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ExternalLink, Orbit, RefreshCw, X } from '@lucide/vue';
import GenerationStatusBadge from '@/components/ai/GenerationStatusBadge.vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import generationRoutes from '@/routes/ai-studio/generations';
import type { GenerationSummary } from '@/types';

defineProps<{
    generations: GenerationSummary[];
    emptyText?: string;
}>();

function regenerate(generation: GenerationSummary): void {
    router.post(
        generationRoutes.regenerate.url(generation.id),
        {},
        { preserveScroll: true },
    );
}

function cancel(generation: GenerationSummary): void {
    router.post(
        generationRoutes.cancel.url(generation.id),
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <ul v-if="generations.length" class="divide-y rounded-lg border">
        <li
            v-for="generation in generations"
            :key="generation.id"
            class="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap"
        >
            <div
                class="flex h-12 w-20 shrink-0 items-center justify-center overflow-hidden rounded bg-muted"
            >
                <img
                    v-if="generation.thumbnail_url"
                    :src="generation.thumbnail_url"
                    :alt="generation.title"
                    class="size-full object-cover"
                    loading="lazy"
                />
                <Orbit v-else class="size-5 text-muted-foreground/60" />
            </div>
            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-medium">
                    {{ generation.title }}
                </p>
                <p class="truncate text-xs text-muted-foreground">
                    {{ generation.type_label }} ·
                    {{ generation.credits }} credit{{
                        generation.credits === 1 ? '' : 's'
                    }}
                    · {{ timeAgo(generation.created_at) }}
                </p>
                <p
                    v-if="generation.error"
                    class="mt-0.5 line-clamp-2 text-xs text-destructive"
                >
                    {{ generation.error }}
                </p>
            </div>
            <GenerationStatusBadge :status="generation.status" />
            <div class="flex shrink-0 items-center gap-1">
                <Button
                    v-if="generation.links.plan"
                    variant="ghost"
                    size="sm"
                    as-child
                >
                    <Link :href="generation.links.plan">Open plan</Link>
                </Button>
                <Button
                    v-if="generation.links.analysis"
                    variant="ghost"
                    size="sm"
                    as-child
                >
                    <Link :href="generation.links.analysis">Open</Link>
                </Button>
                <Button
                    v-if="generation.links.tour"
                    variant="ghost"
                    size="sm"
                    as-child
                >
                    <Link :href="generation.links.tour">
                        Tour <ExternalLink class="size-3" />
                    </Link>
                </Button>
                <Button
                    v-if="generation.can.regenerate"
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="`Regenerate ${generation.title}`"
                    title="Regenerate (uses credits)"
                    @click="regenerate(generation)"
                >
                    <RefreshCw />
                </Button>
                <Button
                    v-if="generation.can.cancel"
                    variant="ghost"
                    size="icon"
                    class="size-8"
                    :aria-label="`Cancel ${generation.title}`"
                    title="Cancel and release credits"
                    @click="cancel(generation)"
                >
                    <X />
                </Button>
            </div>
        </li>
    </ul>
    <p
        v-else
        class="rounded-lg border p-4 text-center text-sm text-muted-foreground"
    >
        {{ emptyText ?? 'Nothing generated yet.' }}
    </p>
</template>
