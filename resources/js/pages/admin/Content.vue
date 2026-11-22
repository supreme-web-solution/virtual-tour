<script setup lang="ts">
import { FileText as HeaderIcon } from '@lucide/vue';
import { Head, Link } from '@inertiajs/vue3';
import { ExternalLink } from '@lucide/vue';
import PageHeader from '@/components/PageHeader.vue';
import PaginationLinks from '@/components/PaginationLinks.vue';
import { Input } from '@/components/ui/input';
import { useListFilters } from '@/composables/useListFilters';
import { formatDate } from '@/lib/format';
import admin from '@/routes/admin';
import type { Paginated } from '@/types';

type ContentItem = {
    id: string;
    title: string;
    workspace: { id: string; name: string };
    property?: string | null;
    location?: string | null;
    scenes_count?: number;
    tours_count?: number;
    status: string;
    visibility?: string;
    public_url?: string | null;
    created_at: string | null;
};

const props = defineProps<{
    tab: 'tours' | 'properties';
    items: Paginated<ContentItem>;
    filters: { q: string };
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Admin', href: admin.dashboard() },
            { title: 'Content', href: admin.content.index() },
        ],
    },
});

const filters = useListFilters(
    { q: props.filters.q, tab: props.tab },
    admin.content.index.url,
);
</script>

<template>
    <Head title="Content · Admin" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="Content"
            :icon="HeaderIcon"
            tone="indigo"
            description="Tours and properties across every workspace (read-only)."
        />

        <div class="flex flex-wrap items-center gap-3">
            <div class="inline-flex rounded-md border p-0.5 text-sm">
                <button
                    v-for="option in ['tours', 'properties'] as const"
                    :key="option"
                    type="button"
                    class="rounded px-3 py-1 capitalize"
                    :class="
                        filters.tab === option
                            ? 'bg-muted font-medium'
                            : 'text-muted-foreground'
                    "
                    @click="filters.tab = option"
                >
                    {{ option }}
                </button>
            </div>
            <Input
                v-model="filters.q"
                :placeholder="
                    tab === 'tours'
                        ? 'Search tour title'
                        : 'Search property name'
                "
                class="w-72"
                aria-label="Search"
            />
        </div>

        <div class="overflow-x-auto rounded-lg border bg-card shadow-sm">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-muted-foreground">
                    <tr class="border-b">
                        <th class="px-4 py-2 font-medium">
                            {{ tab === 'tours' ? 'Tour' : 'Property' }}
                        </th>
                        <th class="px-4 py-2 font-medium">Workspace</th>
                        <th class="px-4 py-2 font-medium">
                            {{ tab === 'tours' ? 'Property' : 'Location' }}
                        </th>
                        <th class="px-4 py-2 text-right font-medium">
                            {{ tab === 'tours' ? 'Scenes' : 'Tours' }}
                        </th>
                        <th class="px-4 py-2 font-medium">Status</th>
                        <th class="px-4 py-2 font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items.data"
                        :key="item.id"
                        class="border-b last:border-0"
                    >
                        <td class="px-4 py-2 font-medium">
                            <a
                                v-if="item.public_url"
                                :href="item.public_url"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 hover:underline"
                                >{{ item.title }}
                                <ExternalLink class="size-3" />
                            </a>
                            <span v-else>{{ item.title }}</span>
                        </td>
                        <td class="px-4 py-2">
                            <Link
                                :href="admin.workspaces.show(item.workspace.id)"
                                class="hover:underline"
                                >{{ item.workspace.name }}</Link
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{
                                (tab === 'tours'
                                    ? item.property
                                    : item.location) ?? '—'
                            }}
                        </td>
                        <td class="px-4 py-2 text-right tabular-nums">
                            {{
                                tab === 'tours'
                                    ? item.scenes_count
                                    : item.tours_count
                            }}
                        </td>
                        <td class="px-4 py-2 capitalize">
                            {{ item.status
                            }}<span
                                v-if="item.visibility"
                                class="text-muted-foreground"
                            >
                                · {{ item.visibility }}</span
                            >
                        </td>
                        <td class="px-4 py-2 text-muted-foreground">
                            {{ formatDate(item.created_at) }}
                        </td>
                    </tr>
                    <tr v-if="!items.data.length">
                        <td
                            colspan="6"
                            class="px-4 py-8 text-center text-muted-foreground"
                        >
                            Nothing found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <PaginationLinks :paginator="items" />
    </div>
</template>
