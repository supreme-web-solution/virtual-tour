<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Clapperboard } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import { show as showVideo } from '@/routes/videos';
import type { VideoMetrics } from '@/types';

defineProps<{
    metrics: VideoMetrics;
}>();

const totals = [
    { key: 'views', label: 'Page views' },
    { key: 'embed_views', label: 'Embed views' },
    { key: 'plays', label: 'Plays' },
    { key: 'completions', label: 'Watched to the end' },
    { key: 'downloads', label: 'Downloads' },
] as const;
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div
                v-for="total in totals"
                :key="total.key"
                class="rounded-lg border bg-card p-4"
            >
                <p class="text-sm text-muted-foreground">{{ total.label }}</p>
                <p class="mt-2 text-2xl font-semibold tabular-nums">
                    {{ metrics.totals[total.key].toLocaleString() }}
                </p>
            </div>
        </div>

        <div
            v-if="metrics.items.length"
            class="overflow-x-auto rounded-lg border bg-card shadow-sm"
        >
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">Video</th>
                        <th class="px-4 py-2 text-right font-medium">Views</th>
                        <th class="px-4 py-2 text-right font-medium">Embeds</th>
                        <th class="px-4 py-2 text-right font-medium">Plays</th>
                        <th class="px-4 py-2 text-right font-medium">
                            Completion
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            Downloads
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="video in metrics.items"
                        :key="video.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2">
                            <Link
                                :href="showVideo(video.id)"
                                class="hover:underline"
                                >{{ video.title }}</Link
                            >
                            <span
                                v-if="!video.published"
                                class="ml-2 text-xs text-muted-foreground"
                                >not published</span
                            >
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ video.views }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ video.embed_views }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ video.plays }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{
                                video.completion_rate === null
                                    ? '—'
                                    : `${video.completion_rate}%`
                            }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{ video.downloads }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <EmptyState
            v-else
            :icon="Clapperboard"
            title="No rendered videos yet"
            description="Views, plays and downloads appear here once you render and share a video."
        />
        <p class="text-xs text-muted-foreground">
            These are all-time totals. A play counts once per page view;
            "watched to the end" needs the browser to report it, so it can be
            lower than the real number.
        </p>
    </div>
</template>
