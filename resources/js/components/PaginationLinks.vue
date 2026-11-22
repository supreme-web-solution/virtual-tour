<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { Paginated } from '@/types';

defineProps<{
    paginator: Paginated<unknown>;
}>();

function cleanLabel(label: string): string {
    return label
        .replace('&laquo;', '‹')
        .replace('&raquo;', '›')
        .replace('Previous', '')
        .replace('Next', '')
        .trim();
}
</script>

<template>
    <nav
        v-if="paginator.last_page > 1"
        class="flex flex-col items-center justify-between gap-3 sm:flex-row"
        aria-label="Pagination"
    >
        <p class="text-sm text-muted-foreground">
            Showing {{ paginator.from }}–{{ paginator.to }} of
            {{ paginator.total }}
        </p>
        <div class="flex flex-wrap items-center gap-1">
            <template v-for="(link, index) in paginator.links" :key="index">
                <Link
                    v-if="link.url"
                    :href="link.url"
                    preserve-scroll
                    class="inline-flex h-8 min-w-8 items-center justify-center rounded-md border px-2 text-sm transition-colors hover:bg-accent"
                    :class="
                        link.active
                            ? 'border-primary bg-primary text-primary-foreground hover:bg-primary/90'
                            : 'border-border'
                    "
                    >{{ cleanLabel(link.label) }}</Link
                >
                <span
                    v-else
                    class="inline-flex h-8 min-w-8 items-center justify-center px-2 text-sm text-muted-foreground"
                    >{{ cleanLabel(link.label) }}</span
                >
            </template>
        </div>
    </nav>
</template>
