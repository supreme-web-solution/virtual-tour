<script setup lang="ts">
import { TriangleAlert as HeaderIcon } from '@lucide/vue';
import { Head, router } from '@inertiajs/vue3';
import { CircleCheck, RotateCcw, Trash2 } from '@lucide/vue';
import EmptyState from '@/components/EmptyState.vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type FailedJob = {
    uuid: string;
    job: string;
    connection: string;
    queue: string;
    attempts: number;
    exception: string;
    failed_at: string;
};

defineProps<{
    jobs: Paginated<FailedJob>;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Failed jobs', href: admin.jobs.index() },
        ],
    },
});

function retry(job: FailedJob): void {
    router.post(admin.jobs.retry(job.uuid).url, {}, { preserveScroll: true });
}

function forget(job: FailedJob): void {
    if (confirm(`Remove the failed ${job.job} job? It will not be retried.`)) {
        router.delete(admin.jobs.destroy(job.uuid).url, {
            preserveScroll: true,
        });
    }
}

function flush(): void {
    if (confirm('Remove every failed job? None of them will be retried.')) {
        router.delete(admin.jobs.flush().url);
    }
}
</script>

<template>
    <Head title="Failed jobs · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Failed jobs"
            :icon="HeaderIcon"
            tone="rose"
            description="Background jobs that ran out of retries. Fix the cause (for example a missing API key) before retrying."
        >
            <template v-if="jobs.total" #actions>
                <Button variant="outline" @click="flush"
                    ><Trash2 /> Remove all</Button
                >
            </template>
        </PageHeader>

        <div v-if="jobs.data.length" class="space-y-3">
            <article
                v-for="job in jobs.data"
                :key="job.uuid"
                class="space-y-2 rounded-lg border bg-card p-4 shadow-sm"
            >
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">{{ job.job }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ job.connection }} · {{ job.queue }} ·
                            {{ job.attempts }} attempt{{
                                job.attempts === 1 ? '' : 's'
                            }}
                            · failed {{ job.failed_at }}
                        </p>
                    </div>
                    <div class="flex gap-1">
                        <Button size="sm" variant="outline" @click="retry(job)"
                            ><RotateCcw /> Retry</Button
                        >
                        <Button
                            size="icon"
                            variant="ghost"
                            class="size-8"
                            :aria-label="`Remove ${job.job}`"
                            @click="forget(job)"
                            ><Trash2
                        /></Button>
                    </div>
                </div>
                <p
                    class="rounded bg-muted/60 p-2 font-mono text-xs break-words"
                >
                    {{ job.exception }}
                </p>
            </article>
        </div>

        <EmptyState
            v-else
            :icon="CircleCheck"
            title="No failed jobs"
            description="Everything in the queues has run successfully."
        />

        <PaginationLinks :paginator="jobs" />
    </div>
</template>
