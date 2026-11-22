<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import {
    Clapperboard,
    ExternalLink,
    Eye,
    MapPin,
    Orbit,
    Search,
} from '@lucide/vue';
import { ref, watch } from 'vue';

/**
 * A workspace's channel: a gallery of its public tours and videos, with
 * search and sorting. Served at /c/{slug} and as a custom domain's home page.
 */
const props = defineProps<{
    brand: {
        name: string;
        logo_url: string | null;
        primary_color: string | null;
        website: string | null;
        white_label: boolean;
        site_name: string;
    };
    tours: {
        id: string;
        title: string;
        description: string | null;
        location: string | null;
        cover_url: string | null;
        scenes_count: number;
        views: number;
        url: string;
    }[];
    videos: {
        id: string;
        title: string;
        poster_url: string | null;
        views: number;
        url: string;
    }[];
    totals: { tours: number; videos: number };
    filters: {
        q: string;
        sort: 'newest' | 'popular' | 'name';
        tab: 'tours' | 'videos';
    };
}>();

const page = usePage();
const search = ref(props.filters.q);
const sort = ref(props.filters.sort);
const tab = ref(props.filters.tab);

const sorts = [
    { value: 'newest', label: 'Newest' },
    { value: 'popular', label: 'Popular' },
    { value: 'name', label: 'A–Z' },
] as const;

let timer: ReturnType<typeof setTimeout> | undefined;

function load(): void {
    router.get(
        page.url.split('?')[0],
        {
            q: search.value || undefined,
            sort: sort.value === 'newest' ? undefined : sort.value,
            tab: tab.value === 'tours' ? undefined : tab.value,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch([sort, tab], load);

function formatViews(count: number): string {
    return count >= 1000
        ? `${(count / 1000).toFixed(1).replace(/\.0$/, '')}K`
        : String(count);
}
</script>

<template>
    <Head :title="brand.name" />

    <div class="min-h-dvh bg-background text-foreground">
        <header
            class="text-white"
            :style="{ backgroundColor: brand.primary_color || '#111827' }"
        >
            <div
                class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-6"
            >
                <div class="flex items-center gap-3">
                    <img
                        v-if="brand.logo_url"
                        :src="brand.logo_url"
                        :alt="brand.name"
                        class="h-10 w-auto max-w-40 rounded bg-white/90 object-contain p-1"
                    />
                    <div>
                        <h1 class="text-xl font-semibold">{{ brand.name }}</h1>
                        <p class="text-xs text-white/70">
                            {{ totals.tours }} tour{{
                                totals.tours === 1 ? '' : 's'
                            }}
                            <template v-if="totals.videos">
                                · {{ totals.videos }} video{{
                                    totals.videos === 1 ? '' : 's'
                                }}</template
                            >
                        </p>
                    </div>
                </div>
                <a
                    v-if="brand.website"
                    :href="brand.website"
                    target="_blank"
                    rel="noopener"
                    class="flex items-center gap-1 text-sm text-white/80 hover:text-white"
                    >Website <ExternalLink class="size-3.5"
                /></a>
            </div>
        </header>

        <main class="mx-auto max-w-6xl space-y-6 px-4 py-6">
            <div class="flex flex-wrap items-center gap-x-6 gap-y-3 border-b">
                <button
                    v-for="item in [
                        { value: 'tours', label: 'Tours', count: totals.tours },
                        {
                            value: 'videos',
                            label: 'Video tours',
                            count: totals.videos,
                        },
                    ] as const"
                    :key="item.value"
                    type="button"
                    class="-mb-px border-b-2 px-1 pb-3 text-sm"
                    :class="
                        tab === item.value
                            ? 'border-foreground font-medium'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                    @click="tab = item.value"
                >
                    {{ item.label }}
                    <span
                        class="ml-1 rounded-full bg-muted px-1.5 py-0.5 text-xs"
                        >{{ item.count }}</span
                    >
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <label class="relative min-w-60 flex-1">
                    <span class="sr-only">Search</span>
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                    />
                    <input
                        v-model="search"
                        type="search"
                        placeholder="Search tours…"
                        maxlength="80"
                        class="h-10 w-full rounded-md border bg-background pr-3 pl-9 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    />
                </label>
                <div
                    class="inline-flex rounded-full border p-0.5 text-sm"
                    role="radiogroup"
                    aria-label="Sort"
                >
                    <button
                        v-for="item in sorts"
                        :key="item.value"
                        type="button"
                        role="radio"
                        :aria-checked="sort === item.value"
                        class="rounded-full px-3 py-1"
                        :class="
                            sort === item.value
                                ? 'bg-primary text-primary-foreground'
                                : 'text-muted-foreground hover:text-foreground'
                        "
                        @click="sort = item.value"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </div>

            <section v-if="tab === 'tours' && tours.length">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <a
                        v-for="tour in tours"
                        :key="tour.id"
                        :href="tour.url"
                        class="group overflow-hidden rounded-lg border bg-card transition-colors hover:border-foreground/20"
                    >
                        <div
                            class="relative aspect-video overflow-hidden bg-muted"
                        >
                            <img
                                v-if="tour.cover_url"
                                :src="tour.cover_url"
                                :alt="tour.title"
                                class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                                loading="lazy"
                            />
                            <Orbit
                                v-else
                                class="m-auto size-8 text-muted-foreground/40"
                            />
                            <span
                                class="absolute bottom-2 left-2 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white"
                            >
                                {{ tour.scenes_count }} scene{{
                                    tour.scenes_count === 1 ? '' : 's'
                                }}
                            </span>
                            <span
                                class="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white"
                            >
                                <Eye class="size-3" />
                                {{ formatViews(tour.views) }}
                            </span>
                        </div>
                        <div class="p-4">
                            <p class="font-medium">{{ tour.title }}</p>
                            <p
                                v-if="tour.description"
                                class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                            >
                                {{ tour.description }}
                            </p>
                            <p
                                v-if="tour.location"
                                class="mt-2 flex items-center gap-1 text-xs text-muted-foreground"
                            >
                                <MapPin class="size-3.5" /> {{ tour.location }}
                            </p>
                        </div>
                    </a>
                </div>
            </section>

            <section v-else-if="tab === 'videos' && videos.length">
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <a
                        v-for="video in videos"
                        :key="video.id"
                        :href="video.url"
                        class="group overflow-hidden rounded-lg border bg-card hover:border-foreground/20"
                    >
                        <div
                            class="relative aspect-video overflow-hidden bg-black"
                        >
                            <img
                                v-if="video.poster_url"
                                :src="video.poster_url"
                                :alt="video.title"
                                class="size-full object-cover"
                                loading="lazy"
                            />
                            <Clapperboard
                                v-else
                                class="m-auto size-8 text-white/40"
                            />
                            <span
                                class="absolute right-2 bottom-2 flex items-center gap-1 rounded bg-black/70 px-1.5 py-0.5 text-xs text-white"
                            >
                                <Eye class="size-3" />
                                {{ formatViews(video.views) }}
                            </span>
                        </div>
                        <p class="p-4 font-medium">{{ video.title }}</p>
                    </a>
                </div>
            </section>

            <p v-else class="py-16 text-center text-muted-foreground">
                <template v-if="filters.q"
                    >Nothing matches “{{ filters.q }}”.</template
                >
                <template v-else-if="tab === 'videos'"
                    >No video tours have been published here yet.</template
                >
                <template v-else>Nothing has been published here yet.</template>
            </p>
        </main>

        <footer
            v-if="!brand.white_label"
            class="border-t py-6 text-center text-xs text-muted-foreground"
        >
            Powered by {{ $page.props.name }}
        </footer>
    </div>
</template>
