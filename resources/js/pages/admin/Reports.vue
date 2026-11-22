<script setup lang="ts">
import { Flag as HeaderIcon } from '@lucide/vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type Report = {
    id: string;
    reason: string;
    details: string | null;
    tour: string;
    workspace: { id: string; name: string };
    public_url: string | null;
    created_at: string | null;
    resolved_at: string | null;
};

defineProps<{
    reports: Paginated<Report>;
    filters: { show: 'open' | 'resolved' };
    openCount: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Reports', href: admin.reports.index() },
        ],
    },
});

function show(value: 'open' | 'resolved'): void {
    router.get(
        admin.reports.index.url(),
        { show: value },
        { preserveState: true, replace: true },
    );
}

function resolve(report: Report, resolved: boolean): void {
    router.patch(
        admin.reports.update.url(report.id),
        { resolved },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Reports · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Tour reports"
            :icon="HeaderIcon"
            tone="rose"
            description="Reports sent by visitors with “Report this tour”. Review the tour, then mark the report resolved."
        />

        <div class="inline-flex w-fit rounded-md border p-0.5 text-sm">
            <button
                v-for="option in ['open', 'resolved'] as const"
                :key="option"
                type="button"
                class="rounded px-3 py-1 capitalize"
                :class="
                    filters.show === option
                        ? 'bg-muted font-medium'
                        : 'text-muted-foreground'
                "
                @click="show(option)"
            >
                {{ option
                }}<span v-if="option === 'open' && openCount">
                    ({{ openCount }})</span
                >
            </button>
        </div>

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">Tour</th>
                        <th class="px-4 py-2 font-medium">Reason</th>
                        <th class="px-4 py-2 font-medium">Workspace</th>
                        <th class="px-4 py-2 font-medium">Reported</th>
                        <th class="px-4 py-2" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="report in reports.data"
                        :key="report.id"
                        class="border-b align-top last:border-0"
                    >
                        <td class="px-4 py-2 font-medium">
                            <a
                                v-if="report.public_url"
                                :href="report.public_url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 hover:underline"
                            >
                                {{ report.tour }}
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else>{{ report.tour }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <p>{{ report.reason }}</p>
                            <p
                                v-if="report.details"
                                class="mt-1 max-w-md text-xs text-muted-foreground"
                            >
                                {{ report.details }}
                            </p>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                :href="
                                    admin.workspaces.show(report.workspace.id)
                                "
                                class="hover:underline"
                                >{{ report.workspace.name }}</Link
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ formatDate(report.created_at) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                v-if="!report.resolved_at"
                                size="sm"
                                variant="outline"
                                @click="resolve(report, true)"
                                >Mark resolved</Button
                            >
                            <Button
                                v-else
                                size="sm"
                                variant="ghost"
                                @click="resolve(report, false)"
                                >Reopen</Button
                            >
                        </td>
                    </tr>
                    <tr v-if="!reports.data.length">
                        <td
                            colspan="5"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Nothing here.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationLinks :paginator="reports" />
    </div>
</template>
