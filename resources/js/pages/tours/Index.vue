<script setup lang="ts">
import { Orbit as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import {
    Building2,
    ExternalLink,
    Layers,
    Loader2,
    Orbit,
    Plus,
    TriangleAlert,
} from '@lucide/vue';
import { computed, onMounted } from 'vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { useCreateTour } from '@/composables/useCreateTour';
import { useGenerationStatus } from '@/composables/useGenerationStatus';
import { timeAgo } from '@/lib/format';
import tours from '@/routes/tours';
import type { Paginated, TourListItem } from '@/types';

const props = defineProps<{
    tours: Paginated<TourListItem>;
    filters: { q: string };
    properties: { id: string; name: string }[];
    defaultPropertyId: string | null;
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tours', href: tours.index() }],
    },
});

const { openCreateTour } = useCreateTour();

function newTour(): void {
    openCreateTour('describe', props.defaultPropertyId);
}

// Arriving from a property's "New tour" link opens the Create tour dialog.
onMounted(() => {
    if (props.defaultPropertyId && props.can.create) {
        newTour();
    }
});

// Refresh while any tour is still being built.
const building = computed(() =>
    props.tours.data.some(
        (tour) => tour.build_stage !== null && tour.build_stage !== 'failed',
    ),
);
useGenerationStatus(() => building.value, ['tours'], 5000);

const stageLabel: Record<string, string> = {
    planning: 'Planning scenes…',
    analysing: 'Reading photos…',
    importing: 'Importing photos…',
    generating: 'Generating 360° scenes…',
    failed: 'Needs attention',
};
</script>

<template>
    <Head title="Tours" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Tours"
            :icon="HeaderIcon"
            tone="sky"
            description="Your 360° virtual tours. Create one from a description, photos, 360° photos or a listing link."
        >
            <template v-if="can.create" #actions>
                <Button @click="newTour"><Plus /> Create tour</Button>
            </template>
        </PageHeader>

        <p
            v-if="filters.q"
            class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
        >
            Showing tours matching “{{ filters.q }}”.
            <Link
                :href="tours.index()"
                class="font-medium text-foreground underline underline-offset-4"
                >Clear search</Link
            >
        </p>

        <div
            v-if="props.tours.data.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <div
                v-for="tour in props.tours.data"
                :key="tour.id"
                class="group overflow-hidden rounded-lg border bg-card"
            >
                <Link :href="tours.edit(tour.id)" class="block">
                    <div class="aspect-[2/1] overflow-hidden bg-muted">
                        <img
                            v-if="tour.cover_url"
                            :src="tour.cover_url"
                            :alt="tour.title"
                            class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                            loading="lazy"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center"
                        >
                            <Orbit class="size-8 text-muted-foreground/50" />
                        </div>
                    </div>
                </Link>
                <div class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <Link
                            :href="tours.edit(tour.id)"
                            class="truncate font-medium hover:underline"
                            >{{ tour.title }}</Link
                        >
                        <span
                            v-if="tour.build_stage"
                            class="flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                tour.build_stage === 'failed'
                                    ? 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300'
                                    : 'bg-sky-50 text-sky-700 dark:bg-sky-950 dark:text-sky-300'
                            "
                        >
                            <TriangleAlert
                                v-if="tour.build_stage === 'failed'"
                                class="size-3"
                            />
                            <Loader2 v-else class="size-3 animate-spin" />
                            {{ stageLabel[tour.build_stage] }}
                        </span>
                        <span
                            v-else
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                tour.status === 'published'
                                    ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                                    : 'bg-muted text-muted-foreground'
                            "
                            >{{
                                tour.status === 'published'
                                    ? 'Published'
                                    : 'Draft'
                            }}</span
                        >
                    </div>
                    <p
                        v-if="tour.property"
                        class="flex items-center gap-1.5 truncate text-sm text-muted-foreground"
                    >
                        <Building2 class="size-3.5 shrink-0" />
                        {{ tour.property.name }}
                    </p>
                    <div
                        class="flex items-center justify-between pt-1 text-xs text-muted-foreground"
                    >
                        <span class="flex items-center gap-1">
                            <Layers class="size-3.5" />
                            {{ tour.scenes_count }} scene{{
                                tour.scenes_count === 1 ? '' : 's'
                            }}
                        </span>
                        <span class="flex items-center gap-3">
                            <a
                                v-if="tour.status === 'published'"
                                :href="tour.public_url"
                                target="_blank"
                                rel="noopener"
                                class="flex items-center gap-1 hover:text-foreground"
                            >
                                View <ExternalLink class="size-3" />
                            </a>
                            <span>{{ timeAgo(tour.updated_at) }}</span>
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <EmptyState
            v-else
            :icon="Orbit"
            title="No virtual tours yet"
            description="Describe a space, upload a few photos or your 360° shots, or paste a listing link. AI builds the scenes and links them."
        >
            <Button v-if="can.create" @click="newTour">
                <Plus /> Create your first tour
            </Button>
        </EmptyState>

        <PaginationLinks :paginator="props.tours" />
    </div>
</template>
