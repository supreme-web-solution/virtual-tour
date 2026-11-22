<script setup lang="ts">
import { Clapperboard as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import { Clapperboard, Globe, Plus } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import videoRoutes from '@/routes/videos';
import type { Paginated, VideoSummary } from '@/types';

defineProps<{
    videos: Paginated<VideoSummary>;
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Video Studio', href: videoRoutes.index() }],
    },
});

const statusTone: Record<string, string> = {
    completed:
        'bg-emerald-50 text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300',
    failed: 'bg-red-50 text-red-700 dark:bg-red-950 dark:text-red-300',
    ready: 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300',
};
</script>

<template>
    <Head title="Video Studio" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Video Studio"
            :icon="HeaderIcon"
            tone="rose"
            description="Turn property photos and tour scenes into cinematic videos with AI camera movement and music."
        >
            <template v-if="can.create" #actions>
                <Button as-child>
                    <Link :href="videoRoutes.create()"><Plus /> New video</Link>
                </Button>
            </template>
        </PageHeader>

        <div
            v-if="videos.data.length"
            class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
        >
            <Link
                v-for="video in videos.data"
                :key="video.id"
                :href="videoRoutes.show(video.id)"
                class="group overflow-hidden rounded-lg border bg-card transition-colors hover:border-foreground/20"
            >
                <div class="relative aspect-video overflow-hidden bg-muted">
                    <img
                        v-if="video.poster_url"
                        :src="video.poster_url"
                        :alt="video.title"
                        class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02]"
                        loading="lazy"
                    />
                    <div
                        v-else
                        class="flex size-full items-center justify-center"
                    >
                        <Clapperboard class="size-8 text-muted-foreground/50" />
                    </div>
                    <span
                        class="absolute right-2 bottom-2 rounded bg-black/60 px-1.5 py-0.5 text-[11px] text-white"
                    >
                        {{ video.aspect_ratio }} ·
                        {{ video.requested_duration }}s
                    </span>
                </div>
                <div class="space-y-2 p-4">
                    <div class="flex items-start justify-between gap-3">
                        <h3 class="truncate font-medium">{{ video.title }}</h3>
                        <span
                            class="shrink-0 rounded-full px-2 py-0.5 text-xs font-medium"
                            :class="
                                statusTone[video.status] ??
                                'bg-muted text-muted-foreground'
                            "
                            >{{ video.status_label }}</span
                        >
                    </div>
                    <div
                        class="flex items-center justify-between text-xs text-muted-foreground"
                    >
                        <span class="truncate"
                            >{{ video.style_label
                            }}<template v-if="video.property">
                                · {{ video.property.name }}</template
                            ></span
                        >
                        <span class="flex shrink-0 items-center gap-2">
                            <Globe
                                v-if="video.is_published"
                                class="size-3.5 text-emerald-600"
                                aria-label="Published"
                            />
                            {{ timeAgo(video.updated_at) }}
                        </span>
                    </div>
                </div>
            </Link>
        </div>

        <EmptyState
            v-else
            :icon="Clapperboard"
            title="No videos yet"
            description="Pick up to 9 photos or tour scenes, choose a style and AI plans the shots for you."
        >
            <Button v-if="can.create" as-child>
                <Link :href="videoRoutes.create()"
                    ><Plus /> Create your first video</Link
                >
            </Button>
        </EmptyState>

        <PaginationLinks :paginator="videos" />
    </div>
</template>
